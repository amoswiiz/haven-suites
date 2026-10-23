<?php
namespace App\Support\Migration;

use Illuminate\Support\Facades\Validator;

class Validation
{
    private array $rules = [];
    private array $errors = [];
    public function setRules(array $rules): static { $this->rules = $rules; return $this; }
    public function run(array $data): bool {
        $translated = [];
        foreach ($this->rules as $field => $rules) {
            $items = [];
            foreach (explode('|', $rules) as $rule) {
                preg_match('/^([^\[]+)(?:\[(.*)\])?$/', $rule, $m);
                $arg = $m[2] ?? '';
                $items[] = match ($m[1]) {
                    'permit_empty' => 'nullable', 'valid_email' => 'email:rfc',
                    'min_length' => 'min:'.$arg, 'max_length' => 'max:'.$arg,
                    'in_list' => 'in:'.$arg, 'valid_date' => 'date_format:'.($arg ?: 'Y-m-d'),
                    'is_natural' => 'regex:/^\d+$/', 'is_natural_no_zero' => 'regex:/^[1-9]\d*$/',
                    'greater_than_equal_to' => 'gte:'.$arg,
                    'uploaded' => 'required', 'max_size' => 'max:'.explode(',', $arg)[1],
                    'ext_in' => 'mimes:'.substr($arg, strpos($arg, ',')+1),
                    'mime_in' => 'mimetypes:'.substr($arg, strpos($arg, ',')+1),
                    'matches' => 'same:'.$arg,
                    'required', 'numeric', 'integer', 'alpha_dash', 'date' => $m[1],
                    default => throw new \LogicException('Unmapped validation rule: '.$rule),
                };
            }
            $translated[$field] = $items;
        }
        $validator = Validator::make($data, $translated);
        $this->errors = array_map(fn($messages) => $messages[0], $validator->errors()->messages());
        return !$validator->fails();
    }
    public function getErrors(): array { return $this->errors; }
}
