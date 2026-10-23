<?php
// Deterministic source port. The CI application remains unchanged.
$root = dirname(__DIR__);
$target = $root . '/laravel';
if (is_file($target.'/config/saas.php')) {
    throw new RuntimeException('The initial port is archived. SaaS development has started; edit the Laravel application directly to preserve its new modules.');
}
function put(string $path, string $contents): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    if (preg_match('/\.(php|md|json|sql)$/i',$path)) {
        $contents = str_replace("\r\n", "\n", $contents);
        $contents = rtrim(preg_replace('/[ \t]+$/m','',$contents))."\n";
    }
    file_put_contents($path, $contents);
}
function convert(string $source): string {
    $source = str_replace([
        'namespace App\\Controllers;', 'use App\\Models\\',
        'CodeIgniter\\Exceptions\\PageNotFoundException',
        'CodeIgniter\\HTTP\\RedirectResponse', 'CodeIgniter\\HTTP\\ResponseInterface',
        'CodeIgniter\\Database\\BaseConnection', 'use Config\\Database;',
        'use CodeIgniter\\Model;', 'CodeIgniter\\Shield\\Models\\UserModel',
        'CodeIgniter\\Shield\\Entities\\User',
    ], [
        'namespace App\\Http\\Controllers\\Haven;', 'use App\\Models\\Haven\\',
        'App\\Support\\Migration\\PageNotFoundException',
        'Illuminate\\Http\\RedirectResponse', 'Symfony\\Component\\HttpFoundation\\Response',
        'App\\Support\\Migration\\Connection', 'use App\\Support\\Migration\\Database;',
        'use App\\Support\\Migration\\Model;', 'App\\Support\\Migration\\UserProvider',
        'App\\Models\\User',
    ], $source);
    // Keep original local type names where imported classes changed.
    $source = str_replace([
        'use Symfony\\Component\\HttpFoundation\\Response;',
        'use App\\Support\\Migration\\Connection;',
        'use App\\Support\\Migration\\UserProvider;',
    ], [
        'use Symfony\\Component\\HttpFoundation\\Response as ResponseInterface;',
        'use App\\Support\\Migration\\Connection as BaseConnection;',
        'use App\\Support\\Migration\\UserProvider as UserModel;',
    ], $source);
    $source = str_replace('\\Symfony\\Component\\HttpFoundation\\Response', '\\Symfony\\Component\\HttpFoundation\\Response', $source);
    $source = preg_replace('/\bauth\(\)/', 'haven_auth()', $source);
    $source = preg_replace('/\\\\App\\\\Models\\\\(?!Haven\\\\|User\b)/', '\\\\App\\\\Models\\\\Haven\\\\', $source);
    $source = preg_replace('/\bsession\(\)/', 'haven_session()', $source);
    $source = preg_replace('/\bview\(/', 'haven_view(', $source);
    $source = preg_replace('/\bservice\(/', 'haven_service(', $source);
    $source = str_replace(['->setBody(', '->setHeader(', 'WRITEPATH', 'FCPATH'],
        ['->setContent(', '->header(', 'haven_private_path()', '(public_path() . DIRECTORY_SEPARATOR)'], $source);
    $source = preg_replace('/redirect\(\)->to\([\'"]\/([^\'"]*)[\'"]\)/', "redirect()->to(site_url('$1'))", $source);
    return $source;
}
foreach (glob($root . '/app/Controllers/*.php') as $file) {
    if (in_array(basename($file), ['BaseController.php','Home.php'], true)) continue;
    put($target . '/app/Http/Controllers/Haven/' . basename($file), convert(file_get_contents($file)));
}
foreach (glob($root . '/app/Models/*.php') as $file) {
    $code = convert(file_get_contents($file));
    $code = str_replace('namespace App\\Models;', 'namespace App\\Models\\Haven;', $code);
    put($target . '/app/Models/Haven/' . basename($file), $code);
}
foreach (glob($root . '/app/Services/*.php') as $file) {
    put($target . '/app/Services/' . basename($file), convert(file_get_contents($file)));
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR)) continue;
    if (basename($file->getPathname()) === 'welcome_message.php') continue;
    $relative = substr($file->getPathname(), strlen($root . '/app/Views/'));
    $code = convert(file_get_contents($file->getPathname()));
    $code = preg_replace('/echo\s+\$this->extend\([\'"]([^\'"]+)[\'"]\);/', "?>@extends('$1')<?php", $code);
    $code = preg_replace('/echo\s+\$this->section\([\'"]([^\'"]+)[\'"]\);/', "?>@section('$1')<?php", $code);
    $code = preg_replace('/<\?=\s*\$this->extend\([\'"]([^\'"]+)[\'"]\)\s*\?>/', "@extends('$1')", $code);
    $code = preg_replace('/<\?=\s*\$this->section\([\'"]([^\'"]+)[\'"]\)\s*\?>/', "@section('$1')", $code);
    $code = preg_replace('/<\?=\s*\$this->endSection\(\)\s*\?>/', '@endsection', $code);
    $code = preg_replace('/<\?=\s*\$this->renderSection\([\'"]([^\'"]+)[\'"]\)\s*\?>/', "@yield('$1')", $code);
    $code = preg_replace('/<\?=\s*\$this->include\([\'"]([^\'"]+)[\'"]\)\s*\?>/', "@include('$1')", $code);
    $relative = strtolower(str_replace('\\', '/', $relative));
    // Action visibility uses the same permissions as the Laravel endpoints.
    foreach (['a','form'] as $tag) {
        $code = preg_replace_callback('/<'.$tag.'\b[\s\S]*?<\/'.$tag.'>/', function ($match) use ($tag) {
            $attribute=$tag==='a'?'href':'action';
            if (!preg_match('/\b'.$attribute.'="<\?=\s*(.*?)\s*\?>"/s',$match[0],$url) || !str_contains($url[1],'site_url(')) return $match[0];
            $method=$tag==='form' && preg_match('/\bmethod="post"/i',$match[0])?'POST':'GET';
            return '<?php if (haven_can_access('.$url[1].', \''.$method.'\')): ?>'.$match[0].'<?php endif; ?>';
        }, $code);
    }
    if ($relative==='invoices/show.php') {
        $code=str_replace("if (haven_can_access(site_url('invoices/' . (int) \$invoice['id'] . '/payments'), 'GET'))", "if (\$canManage && !empty(\$invoice['reservation_id']) && haven_can_access(site_url('invoices/' . (int) \$invoice['id'] . '/payments'), 'GET'))",$code);
    }
    if ($relative === 'units/index.php') {
        $code = preg_replace_callback('/<a href="<\?= site_url\(\'units\/(?:create|edit\/)[\s\S]*?<\/a>/',
            fn($match) => "<?php if (haven_auth()->user()?->can('units.manage')): ?>".$match[0]."<?php endif; ?>", $code);
    }
    if ($relative === 'layouts/sidebar.php') {
        $code=str_replace("'path' => 'customers', 'icon' => 'contact', 'permission' => 'reservations.view'", "'path' => 'customers', 'icon' => 'contact', 'permission' => 'finance.view'",$code);
        $code=str_replace("'path' => 'staff/kyc', 'icon' => 'id-card', 'permission' => 'reservations.view'", "'path' => 'staff/kyc', 'icon' => 'id-card', 'permission' => 'finance.view'",$code);
        $code = str_replace('<div class="hs-sidebar-footer">', '<div class="hs-sidebar-footer"><div class="mb-2"><?= esc(app("currentTenant")->name) ?></div><form method="post" action="<?= url("/logout") ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-light mb-3">Sign out</button></form>', $code);
    }
    put($target . '/resources/views/' . substr($relative, 0, -4) . '.blade.php', $code);
}
$assets = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/assets', FilesystemIterator::SKIP_DOTS));
foreach ($assets as $file) {
    $relative = substr($file->getPathname(), strlen($root . '/public/'));
    put($target . '/public/' . $relative, file_get_contents($file->getPathname()));
}
// Route parser uses the explicit CI declarations, not controller auto-routing.
$source = file_get_contents($root . '/app/Config/Routes.php');
preg_match_all('/\$routes->(get|post)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([A-Za-z]+)::([A-Za-z]+)(?:\/\$1)?[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\);/', $source, $matches, PREG_SET_ORDER);
$routes = [];
foreach ($matches as $match) {
    [$whole, $verb, $uri, $controller, $action] = $match;
    if ($controller === 'CleanerPayments' && $action === 'show') continue;
    $uri = str_replace(['(:num)', '(:segment)'], ['{id}', '{token}'], $uri);
    $public = in_array($controller, ['Kyc'], true) || ($controller === 'Quotations' && in_array($action, ['publicView', 'submitPayment'], true));
    preg_match('/permission:([a-z_.]+)/', $match[5] ?? '', $permission);
    $middleware = $public ? ($verb === 'post' ? ['throttle:10,1'] : []) : ['auth', 'tenant.member'];
    if (!empty($permission[1])) $middleware[] = 'permission:' . $permission[1];
    $line = "Route::$verb(" . var_export($uri, true) . ", [\\App\\Http\\Controllers\\Haven\\$controller::class, '$action'])";
    if ($middleware) $line .= '->middleware(' . var_export($middleware, true) . ')';
    if (str_contains($uri, '{id}')) $line .= "->whereNumber('id')";
    $routes[strtoupper($verb) . ' ' . $uri] ??= $line . ';';
}
$routes['GET owner-remittances/export'] = "Route::get('owner-remittances/export', [\\App\\Http\\Controllers\\Haven\\OwnerRemittances::class, 'export'])->middleware(['auth','tenant.member','permission:finance.view']);";
$routes['GET home'] = "Route::get('home', function () { return haven_landing() === site_url('home') ? view('auth.company-home') : redirect()->to(haven_landing()); })->middleware(['auth','tenant.member']);";
$routes['GET payments/cleaners/{id}'] = "Route::get('payments/cleaners/{id}', [\\App\\Http\\Controllers\\Haven\\CleanerPaymentDetails::class, 'show'])->whereNumber('id')->middleware(['auth','tenant.member','permission:finance.view']);";
put($target . '/routes/haven.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\n" . implode("\n", $routes) . "\n");
// Authorization matrix remains identical to the reviewed application.
$roles = file_get_contents($root . '/app/Config/AuthGroups.php');
$roles = str_replace(['namespace Config;', 'use CodeIgniter\\Shield\\Config\\AuthGroups as ShieldAuthGroups;', 'class AuthGroups extends ShieldAuthGroups'], ['', '', 'class HavenRoleDefinitions'], $roles);
$roles = preg_replace('/declare\(strict_types=1\);/', '', $roles);
preg_match('/public array \$matrix = (\[[\s\S]*?\n    \]);/', $roles, $matrix);
if (!$matrix) throw new RuntimeException('Role matrix could not be read.');
put($target . '/config/haven.php', "<?php\nreturn ['roles' => ".$matrix[1]."];\n");
$composer = json_decode(file_get_contents($target . '/composer.json'), true);
$composer['name'] = 'haven-suites/saas';
$composer['description'] = 'Haven Suites Laravel 13 property operations platform';
$composer['require']['dompdf/dompdf'] = '^3.1';
$composer['autoload']['files'] = ['app/Support/Migration/helpers.php'];
put($target . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Ported controllers, models, services, Blade templates and ' . count($routes) . " routes.\n";
$overrides = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/laravel-overrides', FilesystemIterator::SKIP_DOTS));
foreach ($overrides as $file) put($target.'/'.substr($file->getPathname(), strlen(__DIR__.'/laravel-overrides/')), file_get_contents($file->getPathname()));
$appConfig = $target.'/config/app.php';
put($appConfig, str_replace("'timezone' => 'UTC'", "'timezone' => env('APP_TIMEZONE', 'Africa/Nairobi')", file_get_contents($appConfig)));
$helpers = $target.'/app/Support/Migration/helpers.php';
$content = file_get_contents($helpers);
$content = preg_replace('/^function csrf_field\(\).*\R/m', '', $content);
put($helpers, $content);
// These unused starter pages are not part of the migrated application.
foreach (['app/Http/Controllers/Haven/Home.php','resources/views/welcome_message.blade.php','resources/views/welcome.blade.php'] as $unused) {
    if (is_file($target.'/'.$unused)) unlink($target.'/'.$unused);
}
$examplePath = $target.'/.env.example';
$example = file_get_contents($examplePath);
if (!str_contains($example,'TENANT_DB_HOST=')) {
    $example .= "\nAPP_TIMEZONE=Africa/Nairobi\nSESSION_COOKIE=haven_laravel_session\n# Operational databases are selected from the authenticated company context.\nTENANT_DB_HOST=127.0.0.1\nTENANT_DB_PORT=3306\nTENANT_DB_USERNAME=root\nTENANT_DB_PASSWORD=\n";
    put($examplePath,$example);
}
$schemaPath = $target.'/database/schema/tenant.mysql.sql';
if (is_file($schemaPath)) put($schemaPath,file_get_contents($schemaPath));
