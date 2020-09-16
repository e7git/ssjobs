<?php

namespace Sayhey\Jobs\Core;

use Sayhey\Jobs\Interfaces\LogInterface;
use Sayhey\Jobs\Interfaces\NotifierInterface;
use Sayhey\Jobs\Exception\FatalException;

/**
 * 日志类
 *
 */
class Log
{

    const LEVEL_INFO = 'INFO';                          // info级日志
    const LEVEL_ERROR = 'ERROR';                        // error级日志

    /**
     * 日志类
     * @var LogInterface 
     */
    private static $_logger = null;

    /**
     * 通知类
     * @var NotifierInterface 
     */
    private static $_notifier = null;

    /**
     * 初始化
     * @param array $config
     * @return type
     * @throws Exception
     */
    public static function init(array $config)
    {
        try {
            self::_initLogger($config['log'] ?? []);
            self::_initNotifier($config['notifier'] ?? []);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * 初始化日志类
     * @param array $config
     * @throws Exception
     */
    private static function _initLogger(array $config)
    {
        if (!self::$_logger) {
            $class = $config['class'] ?? null;
            $params = (isset($config['params']) && is_array($config['params'])) ? $config['params'] : [];

            if (empty($class)) {
                throw new FatalException('config log.class is empty');
            }
            if (!class_exists($class)) {
                throw new FatalException('log.class ' . $class . ' not exists');
            }
            if (!isset(class_implements($class)[LogInterface::class])) {
                throw new FatalException('log.class ' . $class . ' must implements class ' . LogInterface::class);
            }

            self::$_logger = new $class($params);
        }
    }

    /**
     * 初始化通知类
     * @param array $config
     * @throws Exception
     */
    private static function _initNotifier(array $config)
    {
        if (!self::$_notifier) {
            $class = $config['class'] ?? null;
            $params = (isset($config['params']) && is_array($config['params'])) ? $config['params'] : [];
            if (!isset($params['log_notify']) || true !== $params['log_notify']) {
                return false;
            }

            if (empty($class)) {
                throw new FatalException('config notifier.class is empty');
            }
            if (!class_exists($class)) {
                throw new FatalException('notifier.class ' . $class . ' not exists');
            }
            if (!isset(class_implements($class)[NotifierInterface::class])) {
                throw new FatalException('notifier.class ' . $class . ' must implements class ' . NotifierInterface::class);
            }

            self::$_notifier = new $class($params);
        }
    }

    /**
     * 记录info级别日志
     * @param string $msg
     * @param bool $need_notify
     */
    public static function info(string $msg, bool $need_notify = false)
    {
        try {
            if (self::$_logger) {
                self::$_logger->log($msg, self::LEVEL_INFO);
            }
            if ($need_notify) {
                self::_logNotify($msg);
            }
        } catch (\Exception $e) {
            // no code
        }
    }

    /**
     * 记录error级别日志
     * @param string $msg
     * @param bool $need_notify
     */
    public static function error(string $msg, bool $need_notify = true)
    {
        try {
            if (self::$_logger) {
                self::$_logger->log($msg, self::LEVEL_ERROR);
            }
            if ($need_notify) {
                self::_logNotify($msg);
            }
        } catch (\Exception $e) {
            // no code
        }
    }

    /**
     * 日志通知
     * @param string $msg
     */
    private static function _logNotify(string $msg)
    {
        if (self::$_notifier) {
            $notifier = self::$_notifier;
            go(function() use($notifier, $msg) {
                $notifier->send($msg);
            });
        }
    }

}
