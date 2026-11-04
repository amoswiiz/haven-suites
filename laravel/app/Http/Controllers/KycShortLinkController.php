<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\KycInvitationValidity;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class KycShortLinkController extends Controller
{
    private function invalid()
    {
        return response('This KYC link is invalid, expired or already used. Please request a new link.', 404)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function show(string $code)
    {
        if (! preg_match('/^[A-Za-z0-9_-]{16}$/D', $code)) return $this->invalid();
        $link = DB::table('kyc_short_links')->where('code_hash', hash('sha256', $code))->first();
        if (! $link || strtotime($link->expires_at) <= time()) return $this->invalid();
        $tenant = Tenant::where('id', $link->tenant_id)->where('active', true)->first();
        if (! $tenant) return $this->invalid();
        try {
            $token = Crypt::decryptString($link->token_encrypted);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            return $this->invalid();
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) return $this->invalid();

        // Check the exact tenant's invitation; never look up a KYC ID globally.
        $original = config('database.connections.tenant');
        $connection = $original;
        $connection['database'] = $tenant->database_name;
        // Preserve an existing transaction when it belongs to this database.
        // Otherwise use an isolated connection without changing tenant state.
        $reuse = (string) ($original['database'] ?? '') === (string) $tenant->database_name;
        $tenantDb = $reuse ? DB::connection('tenant') : DB::build($connection);
        try {
            $record = $tenantDb->table('customer_kyc')->where('id', $link->kyc_id)->first();
        } finally {
            if (! $reuse) $tenantDb->disconnect();
        }
        if (! $record || ! (new KycInvitationValidity)->usable((array) $record)
            || ! hash_equals((string) $record->submission_token_hash, hash('sha256', $token))) {
            return $this->invalid();
        }
        // Fixed application destination: this is not a general-purpose redirect.
        return redirect(rtrim(config('app.url'), '/').'/t/'.rawurlencode($tenant->slug).'/kyc/'.$token)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
