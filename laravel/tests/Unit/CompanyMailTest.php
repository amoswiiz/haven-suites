<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Services\CompanyMail;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

class CompanyMailTest extends TestCase
{
    public function test_sequential_sends_use_each_company_credentials_and_sender(): void
    {
        $service = Mockery::mock(CompanyMail::class)->makePartial();
        $manager = Mockery::mock(MailManager::class);
        $this->app->instance(MailManager::class, $manager);
        foreach ([11, 22] as $id) {
            $row = (object) ['enabled' => true, 'host' => "smtp.company$id.example", 'port' => 587,
                'username' => "user$id", 'password_encrypted' => "encrypted$id",
                'from_address' => "sender$id@example.com", 'from_name' => "Company $id"];
            $query = Mockery::mock();
            DB::shouldReceive('table')->once()->with('company_mail_settings')->andReturn($query);
            $query->shouldReceive('where')->once()->with('tenant_id', $id)->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn($row);
            $service->shouldReceive('assertPublicHost')->once()->with($row->host);
            Crypt::shouldReceive('decryptString')->once()->with("encrypted$id")->andReturn("secret$id");
            $mailer = Mockery::mock(Mailer::class);
            $manager->shouldReceive('build')->once()->with(Mockery::on(fn ($config) =>
                $config['host'] === $row->host && $config['username'] === "user$id"
                && $config['password'] === "secret$id" && $config['require_tls'] === true
            ))->andReturn($mailer);
            $mailer->shouldReceive('raw')->once()->with('Body', Mockery::type('Closure'))
                ->andReturnUsing(function ($body, $callback) use ($row): void {
                    $message = Mockery::mock(Message::class);
                    $message->shouldReceive('from')->once()->with($row->from_address, $row->from_name)->andReturnSelf();
                    $message->shouldReceive('to')->once()->with('recipient@example.com')->andReturnSelf();
                    $message->shouldReceive('subject')->once()->with('Subject')->andReturnSelf();
                    $callback($message);
                });
            $transport = Mockery::mock(EsmtpTransport::class);
            $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn($transport);
            $transport->shouldReceive('stop')->once();
            $tenant = new Tenant;
            $tenant->forceFill(['id' => $id, 'active' => true]);
            $service->send($tenant, 'recipient@example.com', 'Subject', 'Body');
        }
        $this->assertTrue(true);
    }

    public function test_disabled_company_does_not_send_using_platform_fallback(): void
    {
        $query = Mockery::mock();
        DB::shouldReceive('table')->once()->with('company_mail_settings')->andReturn($query);
        $query->shouldReceive('where')->once()->with('tenant_id', 11)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) ['enabled' => false]);
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 11, 'active' => true]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Company email delivery is disabled.');
        (new CompanyMail)->send($tenant, 'recipient@example.com', 'Subject', 'Body');
    }

    public function test_private_smtp_address_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new CompanyMail)->assertPublicHost('127.0.0.1');
    }
}
