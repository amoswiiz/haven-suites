<?php
namespace App\Support\Migration;
class Session
{
    public function getFlashdata(string $key): mixed { return session($key); }
    public function __call(string $method, array $args): mixed { return session()->$method(...$args); }
}
