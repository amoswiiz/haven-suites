<?php
use App\Support\Migration\Connection;

function db_connect(): Connection { return app(Connection::class); }
function haven_auth(): \App\Support\Migration\Auth { return new \App\Support\Migration\Auth(); }
function haven_session(): \App\Support\Migration\Session { return new \App\Support\Migration\Session(); }
function haven_view(string $view, array $data = []): string { return view(str_replace('/', '.', strtolower($view)), $data)->render(); }
function haven_service(string $name): mixed {
    return match ($name) {
        'response' => response(''), 'validation' => new \App\Support\Migration\Validation(),
        'request' => new \App\Support\Migration\Request(), 'auth' => haven_auth(),
        default => throw new LogicException('Unmapped service: '.$name),
    };
}
function site_url(string $path = ''): string {
    if (in_array(trim($path, '/'), ['login','logout'], true)) return url(trim($path,'/'));
    $tenant = app()->bound('currentTenant') ? app('currentTenant')->slug : null;
    return url(($tenant ? 't/'.$tenant.'/' : '').ltrim($path,'/'));
}
function base_url(string $path = ''): string { return asset($path); }
function esc(mixed $value, string $context = 'html'): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function old_input(string $field, mixed $default = ''): mixed { return old($field, $default); }
function log_message(string $level, string $message, array $context = []): void { logger()->log($level, $message, $context); }
function haven_private_path(): string {
    $tenant = app('currentTenant');
    $directory = storage_path('app/private/tenants/'.$tenant->slug).DIRECTORY_SEPARATOR;
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    return $directory;
}
function uri_string(): string { return preg_replace('#^t/[^/]+/?#', '', request()->path()); }
function current_url(): string { return url()->current(); }
function model(string $class): object { return new $class(); }
function haven_can_access(string $url, string $method = 'GET'): bool { return app(\App\Support\RouteAccess::class)->allows($url,$method); }
function haven_landing(): string {
    $user=auth()->user();
    if ($user?->can('dashboard.view')) return site_url('dashboard');
    if ($user?->can('housekeeping.view')) return site_url('housekeeping');
    return site_url('home');
}
