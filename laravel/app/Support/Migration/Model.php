<?php
namespace App\Support\Migration;

/** Retains reviewed model contracts while queries execute through Laravel. */
class Model
{
    protected $table;
    protected $primaryKey = 'id';
    protected $allowedFields = [];
    protected $validationRules = [];
    protected $useTimestamps = false;
    private ?Query $query = null;
    private int $lastId = 0;
    private array $validationErrors = [];
    private function query(): Query { return $this->query ??= db_connect()->table($this->table); }
    public function __call(string $method, array $args): mixed {
        $result = $this->query()->$method(...$args);
        if ($result instanceof Query) return $this;
        $this->query = null;
        return $result;
    }
    public function findAll(int $limit = 0, int $offset = 0): array { if ($limit) $this->query()->limit($limit, $offset); $rows = $this->query()->get()->getResultArray(); $this->query = null; return $rows; }
    public function first(): ?array { $rows = $this->findAll(1); return $rows[0] ?? null; }
    public function find(mixed $id): ?array { $this->query()->where($this->primaryKey, $id); return $this->first(); }
    public function countAllResults(bool $reset = true): int { $count = $this->query()->countAllResults($reset); if ($reset) $this->query = null; return $count; }
    private function data(array $data, bool $insert): array|false {
        $data = array_intersect_key($data, array_flip($this->allowedFields));
        $rules = $insert ? $this->validationRules : array_intersect_key($this->validationRules, $data);
        $validator = new Validation(); $validator->setRules($rules);
        if (!$validator->run($data)) { $this->validationErrors = $validator->getErrors(); return false; }
        if ($this->useTimestamps) { $data['updated_at'] = now()->format('Y-m-d H:i:s'); if ($insert) $data['created_at'] = $data['updated_at']; }
        return $data;
    }
    public function insert(array $data): int|false {
        if (($data = $this->data($data, true)) === false) return false;
        $connection = db_connect(); $connection->table($this->table)->insert($data);
        return $this->lastId = $connection->insertID();
    }
    public function getInsertID(): int { return $this->lastId; }
    public function update(mixed $id, array $data): bool {
        if (($data = $this->data($data, false)) === false) return false;
        return db_connect()->table($this->table)->where($this->primaryKey, $id)->update($data);
    }
    public function save(array $data): bool { return isset($data[$this->primaryKey]) ? $this->update($data[$this->primaryKey], $data) : $this->insert($data) !== false; }
    public function delete(mixed $id): bool { return db_connect()->table($this->table)->where($this->primaryKey, $id)->delete(); }
    public function errors(): array { return $this->validationErrors; }
}
