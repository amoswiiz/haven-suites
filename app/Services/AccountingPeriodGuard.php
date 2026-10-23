<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

class AccountingPeriodGuard
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    public function monthOf(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Invalid accounting date.');
        }
        return $parsed->format('Y-m-01');
    }

    public function isLocked(int $unitId, string $date): bool
    {
        return $this->db->table('accounting_period_locks')
            ->where('unit_id', $unitId)
            ->where('period_month', $this->monthOf($date))
            ->where('is_locked', 1)
            ->countAllResults() > 0;
    }

    public function assertOpen(int $unitId, string $date): void
    {
        if ($this->isLocked($unitId, $date)) {
            throw new RuntimeException('This unit accounting month is locked. Ask an administrator to reopen it before changing financial records.');
        }
    }

    // Use while holding a unit-row FOR UPDATE lock in the caller's transaction.
    public function assertOpenForChange(int $unitId, string $oldDate, ?string $newDate = null): void
    {
        $this->assertOpen($unitId, $oldDate);
        if ($newDate !== null) {
            $this->assertOpen($unitId, $newDate);
        }
    }
}
