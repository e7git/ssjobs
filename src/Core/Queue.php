<?php

namespace Sayhey\Jobs\Core;

use Sayhey\Jobs\Exception\FatalException;
use Sayhey\Jobs\Interfaces\QueueInterface;

/**
 * 队列
 *
 */
class Queue
{

    /**
     * 获取队列
     * @param string $topic
     * @param array $config
     * @return QueueInterface
     * @throws FatalException
     * @throws Exception
     */
    public static function getQueue(string $topic, array $config)
    {
        $class = $config['class'] ?? '';
        $params = (isset($config['params']) && is_array($config['params'])) ? $config['params'] : [];

        if (!$class || !class_exists($class)) {
            throw new FatalException('queue.class ' . $class . ' not exists');
        }
        if (!isset(class_implements($class)[QueueInterface::class])) {
            throw new FatalException('queue.class ' . $class . ' must implements class ' . QueueInterface::class);
        }

        $lastException = null;
        for ($i = 0; $i < 3; $i++) {
            try {
                return $class::getConnection($params, $topic);
            } catch (\Exception $e) {
                $lastException = $e;
            }
        }
        if (!$lastException) {
            throw $lastException;
        }
    }

}
