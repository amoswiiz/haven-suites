<?php
namespace App\Support\Migration;
class Result
{
    public function __construct(private array $rows) {}
    public function getResultArray(): array { return array_map(fn($row) => (array) $row, $this->rows); }
    public function getRowArray(): ?array { return isset($this->rows[0]) ? (array) $this->rows[0] : null; }
}
