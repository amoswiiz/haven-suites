$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpBinary = 'C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe'
Set-Location $projectRoot
& $phpBinary -c "$PSScriptRoot/php83.ini" -S 127.0.0.1:8001 -t "$projectRoot/laravel/public" "$PSScriptRoot/laravel-router.php"
