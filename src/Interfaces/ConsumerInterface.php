<?php

namespace Sayhey\Jobs\Interfaces;

use Sayhey\Jobs\Core\Message;

/**
 * 消费者接口
 * 
 */
interface ConsumerInterface
{

    /**
     * 消费消息
     * @param Message $msg
     * @return bool
     */
    public function consume(Message $msg): bool;
}
