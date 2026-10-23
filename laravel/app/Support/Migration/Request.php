<?php
namespace App\Support\Migration;
class Request
{
    public function getPost(?string $key = null): mixed { return $key === null ? request()->post() : request()->post($key); }
    public function getGet(?string $key = null): mixed { return $key === null ? request()->query() : request()->query($key); }
    public function getFile(string $key): ?Upload { $file = request()->file($key); return $file ? new Upload($file) : null; }
    public function getUri(): \Illuminate\Http\Request { return request(); }
}
