<?php

namespace Sayhey\Jobs\Interfaces;

/**
 * 消息通知接口
 * 
 */
interface NotifierInterface
{

    /**
     * 构造方法
     * @param array $params
     */
    public function __construct(array $params);

    /**
     * 发送消息
     * @param string $msg
     */
    public function send(string $msg);
}
