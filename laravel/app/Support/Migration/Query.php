<?php
namespace App\Support\Migration;

use Illuminate\Database\Query\Builder;

class Query
{
    protected Builder $builder;
    private array $groups = [];
    private array $values = [];
    public function __construct(protected Connection $connection, protected string $table) {
        $this->builder = $connection->native()->table($this->tableName($table));
    }
    private function tableName(string $table): mixed {
        if (str_starts_with(trim($table),'(')) return $this->connection->native()->raw($table);
        return preg_replace('/^([`\w.]+)\s+(?!as\b)(\w+)$/i','$1 as $2',$table);
    }
    public function select(string $columns, bool $escape = true): static { $this->builder->selectRaw($columns); return $this; }
    public function selectSum(string $field, string $alias = ''): static { return $this->select('SUM('.$field.') AS '.($alias ?: $field)); }
    public function selectMax(string $field, string $alias = ''): static { return $this->select('MAX('.$field.') AS '.($alias ?: $field)); }
    public function join(string $table, string $condition, string $type = 'inner'): static {
        $this->builder->join($this->tableName($table), fn($join) => $join->whereRaw($condition), null, null, $type ?: 'inner');
        return $this;
    }
    private function condition(string|array $field, mixed $value, string $boolean): static {
        if (is_array($field)) { foreach ($field as $key => $item) $this->condition($key, $item, $boolean); return $this; }
        if (preg_match('/^(.+?)\s+(>=|<=|!=|<>|>|<|=|IS NOT|IS|NOT LIKE|LIKE)$/i', trim($field), $parts)) {
            $column = $parts[1]; $operator = strtoupper($parts[2]);
        } else { $column = $field; $operator = '='; }
        if ($value === null) $this->builder->whereNull($column, $boolean, in_array($operator, ['!=', '<>', 'IS NOT']));
        else $this->builder->where($column, $operator, $value, $boolean);
        return $this;
    }
    public function where(string|array $field, mixed $value = null, ?bool $escape = null): static {
        if ($escape === false && $value === null && is_string($field)) { $this->builder->whereRaw($field); return $this; }
        return $this->condition($field, $value, 'and');
    }
    public function orWhere(string|array $field, mixed $value = null): static { return $this->condition($field, $value, 'or'); }
    public function whereIn(string $field, array $values): static { $this->builder->whereIn($field, $values); return $this; }
    public function whereNotIn(string $field, array $values): static { $this->builder->whereNotIn($field, $values); return $this; }
    public function like(string $field, string $value): static { $this->builder->where($field, 'like', '%'.addcslashes($value, '%_').'%', 'and'); return $this; }
    public function orLike(string $field, string $value): static { $this->builder->orWhere($field, 'like', '%'.addcslashes($value, '%_').'%'); return $this; }
    public function groupStart(): static { $this->groups[] = $this->builder; $this->builder = $this->builder->forNestedWhere(); return $this; }
    public function groupEnd(): static { $nested = $this->builder; $this->builder = array_pop($this->groups); $this->builder->addNestedWhereQuery($nested); return $this; }
    public function groupBy(string $field): static { $this->builder->groupByRaw($field); return $this; }
    public function orderBy(string $field, string $direction = 'asc'): static { $this->builder->orderBy($field, $direction); return $this; }
    public function limit(int $limit, int $offset = 0): static { $this->builder->limit($limit)->offset($offset); return $this; }
    public function get(?int $limit = null): Result { if ($limit !== null) $this->builder->limit($limit); return new Result($this->builder->get()->all()); }
    public function countAllResults(bool $reset = true): int { $count = $this->builder->count(); if ($reset) $this->reset(); return $count; }
    protected function reset(): void { $this->builder = $this->connection->native()->table($this->tableName($this->table)); $this->groups = []; $this->values = []; }
    public function set(string|array $field, mixed $value = null, bool $escape = true): static {
        if (is_array($field)) $this->values = array_merge($this->values, $field);
        else $this->values[$field] = $escape ? $value : $this->connection->native()->raw($value);
        return $this;
    }
    public function insert(array $data): bool { $id = $this->builder->insertGetId($data); $this->connection->rememberId((int)$id); return true; }
    public function update(array $data = []): bool { $this->builder->update(array_merge($this->values, $data)); return true; }
    public function delete(): bool { $this->builder->delete(); return true; }
}
