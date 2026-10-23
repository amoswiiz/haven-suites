<?php
$root=dirname(__DIR__).'/laravel';
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$count=0;
foreach (['app','config','routes','database','tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension()!=='php') continue;
        token_get_all(file_get_contents($file->getPathname()),TOKEN_PARSE); $count++;
    }
}
$routes=0;
foreach ($app['router']->getRoutes() as $route) {
    $action=$route->getActionName();
    if (!str_contains($action,'@')) continue;
    [$class,$method]=explode('@',$action,2);
    if (str_starts_with($class,'App\\')) {
        if (!method_exists($class,$method)) throw new RuntimeException('Missing route method: '.$action);
        $routes++;
    }
}
echo "Parsed $count PHP source files; verified $routes application route handlers.\n";
