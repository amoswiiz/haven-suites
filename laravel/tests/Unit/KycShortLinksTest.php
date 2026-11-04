<?php

namespace Tests\Unit;

use App\Http\Controllers\KycShortLinkController;
use App\Services\KycShortLinks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KycShortLinksTest extends TestCase
{
    private string $tenantFile;
    private string $token;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantFile = tempnam(sys_get_temp_dir(), 'haven-kyc-test-');
        config([
            'database.default' => 'kyc_short_test',
            'database.connections.kyc_short_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.connections.tenant' => ['driver' => 'sqlite', 'database' => $this->tenantFile, 'prefix' => '', 'foreign_key_constraints' => true],
            'app.url' => 'https://haven.example',
        ]);
        DB::purge('tenant');
        DB::connection()->getSchemaBuilder()->create('tenants', function (Blueprint $t): void {
            $t->id(); $t->string('slug'); $t->string('database_name'); $t->boolean('active');
        });
        (require database_path('migrations/2026_11_04_150000_create_kyc_short_links_table.php'))->up();
        DB::table('tenants')->insert(['id' => 1, 'slug' => 'haven', 'database_name' => $this->tenantFile, 'active' => true]);
        DB::connection('tenant')->getSchemaBuilder()->create('customer_kyc', function (Blueprint $t): void {
            $t->id(); $t->string('submission_token_hash')->nullable(); $t->string('status');
            $t->timestamp('created_at'); $t->timestamp('submitted_at')->nullable();
        });
        $this->token = bin2hex(random_bytes(32));
        DB::connection('tenant')->table('customer_kyc')->insert([
            'id' => 7, 'submission_token_hash' => hash('sha256', $this->token),
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $url = app(KycShortLinks::class)->create(1, 7, $this->token, date('Y-m-d H:i:s'));
        $this->code = basename($url);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        DB::purge('kyc_short_test');
        if (isset($this->tenantFile) && is_file($this->tenantFile)) unlink($this->tenantFile);
        parent::tearDown();
    }

    public function test_short_code_redirects_only_to_configured_kyc_origin(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16}$/D', $this->code);
        $row = DB::table('kyc_short_links')->first();
        $this->assertSame(hash('sha256', $this->code), $row->code_hash);
        $this->assertStringNotContainsString($this->token, $row->token_encrypted);
        $response = app(KycShortLinkController::class)->show($this->code);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://haven.example/t/haven/kyc/'.$this->token, $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_used_or_revoked_invitation_is_not_redirected(): void
    {
        DB::connection('tenant')->table('customer_kyc')->where('id', 7)->update(['submitted_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(404, app(KycShortLinkController::class)->show($this->code)->getStatusCode());
        DB::connection('tenant')->table('customer_kyc')->where('id', 7)->update(['submitted_at' => null, 'submission_token_hash' => null]);
        $this->assertSame(404, app(KycShortLinkController::class)->show($this->code)->getStatusCode());
    }

    public function test_expired_link_is_not_redirected(): void
    {
        DB::table('kyc_short_links')->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);
        $this->assertSame(404, app(KycShortLinkController::class)->show($this->code)->getStatusCode());
    }

    public function test_expired_underlying_invitation_is_not_redirected(): void
    {
        DB::connection('tenant')->table('customer_kyc')->where('id', 7)->update(['created_at' => date('Y-m-d H:i:s', time() - 73 * 3600)]);
        $this->assertSame(404, app(KycShortLinkController::class)->show($this->code)->getStatusCode());
    }

    public function test_inactive_tenant_or_mismatched_token_is_not_redirected(): void
    {
        DB::table('tenants')->where('id', 1)->update(['active' => false]);
        $this->assertSame(404, app(KycShortLinkController::class)->show($this->code)->getStatusCode());
        DB::table('tenants')->where('id', 1)->update(['active' => true]);
        DB::connection('tenant')->table('customer_kyc')->where('id', 7)->update(['submission_token_hash' => hash('sha256', 'another-token')]);
        $this->assertSame(404, app(KycShortLinkController::class)->show($this->code)->getStatusCode());
    }

    public function test_unknown_and_malformed_codes_are_not_redirected(): void
    {
        $this->assertSame(404, app(KycShortLinkController::class)->show('short')->getStatusCode());
        $this->assertSame(404, app(KycShortLinkController::class)->show(str_repeat('x', 16))->getStatusCode());
    }
}
