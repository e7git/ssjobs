<?php

namespace Sayhey\Jobs\Core;

/**
 * 消息类
 *
 */
class Message
{

    const STATUS_ACK = 'ACK';                           // 状态应答
    const STATUS_REJECT = 'REJECT';                     // 状态拒绝
    const STATUS_REPUSH = 'REPUSH';                     // 状态重新入队

    private $_status = '';                              // 状态
    private $_isDone = false;                           // 是否处理
    private $_body = '';                                // 消息体

    /**
     * 构造方法
     * @param string $body
     */
    public function __construct(string $body)
    {
        $this->_body = $body;
        $this->_isDone = false;
        $this->_status = '';
    }

    /**
     * 获取消息体
     * @return string
     */
    public function getBody(): string
    {
        return $this->_body;
    }

    /**
     * 是否处理
     * @return bool
     */
    public function isDone(): bool
    {
        return $this->_isDone;
    }

    /**
     * 应答
     * @return bool
     */
    public function ack(): bool
    {
        $this->_isDone = true;
        $this->_status = self::STATUS_ACK;
        return true;
    }

    /**
     * 是否应答
     * @return bool
     */
    public function isAck(): bool
    {
        return self::STATUS_ACK === $this->_status;
    }

    /**
     * 拒绝
     * @return bool
     */
    public function reject(): bool
    {
        $this->_isDone = true;
        $this->_status = self::STATUS_REJECT;
        return true;
    }

    /**
     * 是否拒绝
     * @return bool
     */
    public function isReject(): bool
    {
        return self::STATUS_REJECT === $this->_status;
    }

    /**
     * 重新入队
     * @return bool
     */
    public function repush(): bool
    {
        $this->_isDone = true;
        $this->_status = self::STATUS_REPUSH;
        return true;
    }

    /**
     * 是否重新入队
     * @return bool
     */
    public function isRepush(): bool
    {
        return self::STATUS_REPUSH === $this->_status;
    }

}
