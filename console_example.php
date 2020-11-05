<?php

if ('cli' !== php_sapi_name()) {
    echo 'service can only run in cli mode', PHP_EOL;
    exit;
}

if (!extension_loaded('swoole')) {
    echo 'swoole extension not exist', PHP_EOL;
    exit;
}

// 自动加载
if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
} else {

    function autoload($class)
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
        require_once str_replace('Sayhey/Jobs', 'src', $file);
    }

    spl_autoload_register('autoload');
}

$config = include('config_example.php');
$console = new Sayhey\Jobs\Console($config);
$console->run();