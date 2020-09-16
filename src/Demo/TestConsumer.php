<?php

namespace Sayhey\Jobs\Demo;

use Sayhey\Jobs\Core\Message;
use Sayhey\Jobs\Interfaces\ConsumerInterface;

/**
 * 测试消费者
 * 
 */
class TestConsumer implements ConsumerInterface
{

    /**
     * 消费消息
     * @param Message $msg
     * @return bool
     */
    public function consume(Message $msg): bool
    {
        $servername = 'localhost';
        $username = 'root';
        $password = 'root';
        $dbname = '_temp';
        $num = rand(1, 1000);
        $value = -1;

        if (1 === $num) {
            $value = 0; // 处理失败
        } elseif (2 === $num) {
            $msg->reject();
            $value = 2; // 拒绝
        } elseif ($num <= 10) {
            $msg->repush();
            return true;
        } else {
            $value = 1;
            $msg->ack(); // 应答
        }

        $conn = new \PDO("mysql:host={$servername};dbname={$dbname}", $username, $password);
        $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $sql = 'INSERT INTO t1 (c1,c2) VALUES (' . $msg->getBody() . ',' . $value . ')';
        $conn->exec($sql);

        return true;
    }

}
