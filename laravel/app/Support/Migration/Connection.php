<?php
namespace App\Support\Migration;

use Illuminate\Support\Facades\DB;

/** Transitional API backed exclusively by Laravel's tenant connection. */
class Connection
{
    private int $lastId = 0;
    public function native(): \Illuminate\Database\Connection { return DB::connection('tenant'); }
    public function table(string $table): Query { return new Query($this, $table); }
    public function query(string $sql, array $bindings = []): Result {
        if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|WITH)\b/i', $sql)) {
            if ($this->native()->getDriverName() === 'sqlite') $sql = str_ireplace(' FOR UPDATE', '', $sql);
            return new Result($this->native()->select($sql, $bindings));
        }
        $this->native()->statement($sql, $bindings);
        return new Result([]);
    }
    public function rememberId(int $id): void { $this->lastId = $id; }
    public function insertID(): int { return $this->lastId; }
    public function transBegin(): void { $this->native()->beginTransaction(); }
    public function transStart(): void { $this->transBegin(); }
    public function transCommit(): void { $this->native()->commit(); }
    public function transComplete(): void { $this->transCommit(); }
    public function transRollback(): void { if ($this->native()->transactionLevel()) $this->native()->rollBack(); }
    public function transStatus(): bool { return true; } // Laravel throws on failed statements.
    public function escape(mixed $value): string { return $this->native()->getPdo()->quote((string) $value); }
    public function tableExists(string $table): bool { return $this->native()->getSchemaBuilder()->hasTable($table); }
}
