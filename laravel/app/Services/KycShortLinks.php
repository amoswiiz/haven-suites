<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class KycShortLinks
{
    public function create(int $tenantId, int $kycId, string $token, string $createdAt): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Invalid invitation token.');
        }
        $created = strtotime($createdAt);
        if ($created === false) throw new \InvalidArgumentException('Invalid invitation creation date.');
        // 12 random bytes encode to exactly 16 URL-safe characters (96 bits).
        $code = strtr(base64_encode(random_bytes(12)), '+/', '-_');
        DB::table('kyc_short_links')->insert([
            'tenant_id' => $tenantId, 'kyc_id' => $kycId,
            'code_hash' => hash('sha256', $code), 'token_encrypted' => Crypt::encryptString($token),
            'expires_at' => date('Y-m-d H:i:s', $created + KycInvitationValidity::HOURS * 3600),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return rtrim(config('app.url'), '/').'/k/'.$code;
    }
}
