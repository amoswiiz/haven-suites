<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * One shared KYC registration link per company that staff can send to any guest. Opening it only shows the form;
 * each submission creates its own customer and pending KYC record for staff to review. Replacing the link stops
 * the old one working; turning it off stops all shared registrations.
 */
class KycJoinLink
{
    public const CODE_LENGTH = 20;

    public const CODE_PATTERN = '[A-Za-z0-9]{20}';

    public function replace(Tenant $tenant): string
    {
        do {
            $code = Str::random(self::CODE_LENGTH);
        } while (Tenant::where('kyc_join_code', $code)->exists());
        $tenant->forceFill(['kyc_join_code' => $code])->save();

        return $code;
    }

    public function turnOff(Tenant $tenant): void
    {
        $tenant->forceFill(['kyc_join_code' => null])->save();
    }

    public function url(Tenant $tenant): ?string
    {
        return $tenant->kyc_join_code ? rtrim(config('app.url'), '/').'/j/'.$tenant->kyc_join_code : null;
    }

    public function matches(Tenant $tenant, string $code): bool
    {
        return (bool) preg_match('/^'.self::CODE_PATTERN.'$/D', $code)
            && is_string($tenant->kyc_join_code) && hash_equals($tenant->kyc_join_code, $code);
    }
}
