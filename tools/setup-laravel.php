<?php
$root = dirname(__DIR__);
$target = $root.'/laravel';
$legacy = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^\s*([a-zA-Z][\w.]*)\s*=\s*(.*?)\s*$/', $line, $m)) $legacy[$m[1]] = trim($m[2], "\"'");
}
if (file_exists($target.'/.env')) throw new RuntimeException('Laravel .env already exists; it was not overwritten.');
function quoted(string $value): string { return '"'.str_replace(['\\','"','$'], ['\\\\','\\"','\\$'], $value).'"'; }
$env = [
    'APP_NAME'=>'Haven Suites', 'APP_ENV'=>'local','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'APP_DEBUG'=>'true','APP_URL'=>'http://127.0.0.1:8001','APP_TIMEZONE'=>'Africa/Nairobi',
    'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>str_replace('\\','/',$target.'/database/database.sqlite'),
    'SESSION_DRIVER'=>'file','SESSION_COOKIE'=>'haven_laravel_session','SESSION_LIFETIME'=>'120',
    'CACHE_STORE'=>'file','QUEUE_CONNECTION'=>'database','LOG_CHANNEL'=>'stack',
    'TENANT_DB_HOST'=>$legacy['database.default.hostname'] ?? '127.0.0.1',
    'TENANT_DB_PORT'=>$legacy['database.default.port'] ?? '3306',
    'TENANT_DB_USERNAME'=>$legacy['database.default.username'] ?? 'root',
    'TENANT_DB_PASSWORD'=>$legacy['database.default.password'] ?? '',
    'MAIL_MAILER'=>'log',
];
$content = '';
foreach ($env as $key=>$value) $content .= $key.'='.quoted($value)."\n";
file_put_contents($target.'/.env', $content);
touch($target.'/database/database.sqlite');
echo 'Created private Laravel environment and empty central SQLite database.'.PHP_EOL;
echo 'Legacy source database: '.($legacy['database.default.database'] ?? '(not set)').PHP_EOL;
