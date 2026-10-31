<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class CompanyMail
{
    public function settings(int $tenantId): ?object
    {
        return DB::table('company_mail_settings')->where('tenant_id', $tenantId)->first([
            'provider', 'host', 'port', 'username', 'from_address', 'from_name', 'enabled',
        ]);
    }

    public function sendsExternally(int $tenantId): bool
    {
        $settings = $this->settings($tenantId);

        return $settings ? (bool) $settings->enabled : ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function assertPublicHost(string $host): void
    {
        // SMTP settings require a hostname; do not send IP literals to DNS.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw ValidationException::withMessages([
                'host' => 'Enter your email provider\'s public SMTP hostname, not an IP address.',
            ]);
        }

        if (! preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/iD', $host) || ! str_contains($host, '.')) {
            throw ValidationException::withMessages(['host' => 'Enter a public SMTP hostname without a URL or port.']);
        }
        $addresses = gethostbynamel($host) ?: [];
        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $addresses[] = $record['ipv6'];
        }
        if (! $addresses) {
            throw ValidationException::withMessages(['host' => 'The SMTP hostname could not be resolved.']);
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages(['host' => 'Use a public mail provider hostname. Private server addresses are not supported.']);
            }
        }
    }

    public function send(Tenant $tenant, string $recipient, string $subject, string $body, bool $test = false): void
    {
        $row = DB::table('company_mail_settings')->where('tenant_id', $tenant->id)->first();
        if (! $row) {
            if ($test) {
                throw new \RuntimeException('Save email settings first.');
            }
            Mail::raw($body, fn ($message) => $message->to($recipient)->subject($subject));

            return;
        }
        if (! $tenant->active || (! $test && ! $row->enabled)) {
            throw new \RuntimeException('Company email delivery is disabled.');
        }
        $this->assertPublicHost($row->host);
        $mailer = app(MailManager::class)->build([
            'transport' => 'smtp', 'host' => $row->host, 'port' => (int) $row->port,
            'scheme' => (int) $row->port === 465 ? 'smtps' : 'smtp',
            'require_tls' => true, 'timeout' => 15,
            'username' => $row->username, 'password' => Crypt::decryptString($row->password_encrypted),
        ]);
        try {
            $mailer->raw($body, fn ($message) => $message->from($row->from_address, $row->from_name)->to($recipient)->subject($subject));
        } finally {
            $mailer->getSymfonyTransport()->stop();
        }
    }
}
