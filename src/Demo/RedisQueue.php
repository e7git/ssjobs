<?php

namespace Sayhey\Jobs\Demo;

use Sayhey\Jobs\Common\Util;
use Sayhey\Jobs\Interfaces\QueueInterface;
use Sayhey\Jobs\Exception\FatalException;

/**
 * Redis队列
 * 
 */
class RedisQueue implements QueueInterface
{

    private $_host;                                     // 主机
    private $_port;                                     // 端口
    private $_auth;                                     // 密码
    private $_db;                                       // 数据库
    private $_queueName;                                // 队列名

    /**
     * 操作者
     * @var \Redis
     */
    private $_handler;

    /**
     * 构造函数
     * @param array $config
     * @param string $queueName
     */
    private function __construct(array $config, string $queueName)
    {
        $this->_queueName = $queueName;
        $this->_host = $config['host'] ?? '127.0.0.1';
        $this->_port = $config['port'] ?? 6379;
        $this->_db = $config['db'] ?? 0;
        $this->_auth = $config['pass'] ?? '';
        $this->_handler = new \Redis();
        $this->_connect();
    }

    /**
     * 获取连接
     * @param array $config
     * @param string $queueName
     * @return QueueInterface 失败返回false
     */
    public static function getConnection(array $config, string $queueName)
    {
        return new self($config, $queueName);
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Exception $e) {
            // no code
        }
    }

    /**
     * 连接Redis
     * @throws \RedisException
     */
    private function _connect()
    {
        try {
            if (empty($this->_host) || empty($this->_port)) {
                throw new FatalException('redis host or port is empty');
            }
            $this->_handler->connect($this->_host, $this->_port, 3);
            if (!empty($this->_auth)) {
                $this->_handler->auth($this->_auth);
            }
            if (!empty($this->_db)) {
                $this->_handler->select($this->_db);
            }
        } catch (\RedisException $e) {
            throw $e;
        }
    }

    /**
     * 获取是否连接
     * @return bool
     */
    public function isConntected(): bool
    {
        return $this->_handler->isConnected();
    }

    /**
     * 返回当前队列长度
     * @return int
     */
    public function size(): int
    {
        try {
            $len = $this->_command(function() {
                return $this->_handler->lLen($this->_queueName);
            });
            if (!$len) {
                return 0;
            }
            return $len ?: 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 执行命令
     * @param callable $callback
     * @return mixed
     */
    private function _command($callback)
    {
        try {
            return call_user_func($callback);
        } catch (\RedisException $e) {
            if ($this->isConntected()) {
                throw $e;
            }
            $try_times = 0; // 尝试3次重连执行
            $error = null;
            do {
                // 失败后重连
                if ($try_times == 1) {
                    sleep(1);
                } else if ($try_times == 2) {
                    sleep(2);
                }

                // 尝试重连
                try {
                    if ($this->_connect()) {
                        return call_user_func($callback);
                    }
                } catch (\RedisException $e) {
                    $error = $e;
                }
                $try_times++;
            } while ($try_times <= 3);
            if ($error) {
                throw $error;
            }
        }
    }

    /**
     * 从队列中弹出一条消息
     * @return string 没有数据时返回null
     */
    public function pop()
    {
        try {
            $ret = $this->_command(function() {
                return $this->_handler->brPop($this->_queueName, 1);
            });
            if (empty($ret)) {
                return null;
            }
            return $ret[1];
        } catch (\Exception $e) {
            Util::logException($e);
            return null;
        }
    }

    /**
     * 关闭
     */
    public function close()
    {
        if ($this->_handler) {
            $this->_handler->close();
            $this->_handler = null;
        }
    }

    /**
     * 将消息重新加入到队列中
     * @param string $body
     * @return bool
     */
    public function repush(string $body): bool
    {
        try {
            $ret = $this->_command(function() use($body) {
                return $this->_handler->lPush($this->_queueName, $body);
            });
            if ($ret) {
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

}
