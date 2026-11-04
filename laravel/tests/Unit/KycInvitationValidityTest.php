<?php

namespace Tests\Unit;

use App\Services\KycInvitationValidity;
use PHPUnit\Framework\TestCase;

class KycInvitationValidityTest extends TestCase
{
    private function record(array $overrides = []): array
    {
        return array_replace([
            'submission_token_hash' => hash('sha256', 'test-token'),
            'submitted_at' => null, 'status' => 'pending',
            'created_at' => '2026-11-04 10:00:00', 'reservation_id' => null,
        ], $overrides);
    }

    public function test_prebooking_and_booking_invitations_are_usable_before_expiry(): void
    {
        $policy = new KycInvitationValidity;
        $now = strtotime('2026-11-05 10:00:00');
        $this->assertTrue($policy->usable($this->record(), $now));
        $this->assertTrue($policy->usable($this->record(['reservation_id' => 42]), $now));
    }

    public function test_expiry_boundary_is_exactly_seventy_two_hours(): void
    {
        $policy = new KycInvitationValidity;
        $expiry = strtotime('2026-11-04 10:00:00') + 72 * 3600;
        $this->assertTrue($policy->usable($this->record(), $expiry - 1));
        $this->assertFalse($policy->usable($this->record(), $expiry));
        $this->assertFalse($policy->usable($this->record(), $expiry + 1));
    }

    public function test_used_revoked_and_reviewed_invitations_are_unusable(): void
    {
        $policy = new KycInvitationValidity;
        $now = strtotime('2026-11-05 10:00:00');
        foreach ([['submitted_at' => '2026-11-04 11:00:00'], ['submission_token_hash' => null],
            ['status' => 'approved'], ['status' => 'rejected']] as $changes) {
            $this->assertFalse($policy->usable($this->record($changes), $now));
        }
    }

    public function test_missing_invalid_and_future_creation_dates_are_unusable(): void
    {
        $policy = new KycInvitationValidity;
        $now = strtotime('2026-11-05 10:00:00');
        foreach ([null, '', 'invalid', '2026-11-06 10:00:00'] as $created) {
            $this->assertFalse($policy->usable($this->record(['created_at' => $created]), $now));
        }
    }
}
