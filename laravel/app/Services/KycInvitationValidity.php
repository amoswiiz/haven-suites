<?php

namespace App\Services;

class KycInvitationValidity
{
    public const HOURS = 72;

    public function usable(array $record, ?int $now = null): bool
    {
        if (! empty($record['submitted_at']) || empty($record['submission_token_hash'])
            || ($record['status'] ?? null) !== 'pending' || empty($record['created_at'])) {
            return false;
        }
        $created = strtotime((string) $record['created_at']);
        $now ??= time();

        return $created !== false && $created <= $now && $now < $created + self::HOURS * 3600;
    }
}
