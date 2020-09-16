<?php

namespace Sayhey\Jobs\Interfaces;

/**
 * 队列接口
 * 
 */
interface QueueInterface
{

    /**
     * 获取连接
     * @param array $config
     * @param string $queueName
     * @return QueueInterface 失败返回false
     */
    public static function getConnection(array $config, string $queueName);

    /**
     * 队列是否连接
     * @return bool
     */
    public function isConntected(): bool;

    /**
     * 获取当前队列的长度
     * @return int
     */
    public function size(): int;

    /**
     * 从队列中弹出一条消息
     * @return string 没有数据时返回null
     */
    public function pop();

    /**
     * 将消息重新加入到队列中
     * @param string $body
     * @return bool
     */
    public function repush(string $body): bool;

    /**
     * 关闭
     */
    public function close();
}
