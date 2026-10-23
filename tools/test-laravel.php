<?php
$root = dirname(__DIR__);
$command = [PHP_BINARY, '-c', __DIR__.'/php83.ini', $root.'/laravel/vendor/bin/phpunit', '--configuration', $root.'/laravel/phpunit.xml', ...array_slice($argv,1)];
$process = proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
$json=json_decode(trim($output),true);
if ($json) {
    echo json_encode(array_diff_key($json,['failures'=>true]),JSON_PRETTY_PRINT).PHP_EOL;
    foreach ($json['failures'] ?? [] as $failure) {
        echo $failure['test'].PHP_EOL;
        $message=$failure['message'];
        $start=strpos($message,'The following exception occurred');
        echo substr($start!==false?substr($message,$start):$message,0,800).PHP_EOL.PHP_EOL;
    }
} else echo $output;
if ($errors) echo $errors;
exit($code);
