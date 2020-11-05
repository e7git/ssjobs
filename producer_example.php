<?php

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379, 3);

for ($i = 0; $i < 10000; $i++) {
    $redis->lpush('test.topic', $i);
}

echo 'OK', PHP_EOL;
