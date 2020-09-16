<?php

namespace Sayhey\Jobs\Interfaces;

/**
 * 日志接口
 * 
 */
interface LogInterface
{

    /**
     * 构造方法
     * @param array $config
     */
    public function __construct(array $config);

    /**
     * 记录日志
     * @param string $msg
     * @param string $level
     */
    public function log(string $msg, string $level);
}
