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

    private $host;                                      // 主机
    private $port;                                      // 端口
    private $auth;                                      // 密码
    private $db;                                        // 数据库
    private $queueName;                                 // 队列名

    /**
     * 操作者
     * @var \Redis
     */
    private $handler;

    /**
     * 构造函数
     * @param array $config
     * @param string $queueName
     */
    private function __construct(array $config, string $queueName)
    {
        $this->queueName = $queueName;
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = $config['port'] ?? 6379;
        $this->db = $config['db'] ?? 0;
        $this->auth = $config['pass'] ?? '';
        $this->handler = new \Redis();
        $this->connect();
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
    private function connect()
    {
        try {
            if (empty($this->host) || empty($this->port)) {
                throw new FatalException('redis host or port is empty');
            }
            $this->handler->connect($this->host, $this->port, 3);
            if (!empty($this->auth)) {
                $this->handler->auth($this->auth);
            }
            if (!empty($this->db)) {
                $this->handler->select($this->db);
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
        return $this->handler->isConnected();
    }

    /**
     * 返回当前队列长度
     * @return int
     */
    public function size(): int
    {
        try {
            $len = $this->command(function() {
                return $this->handler->lLen($this->queueName);
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
     * 返回当前队列全部长度
     * @return int
     */
    public function allSize(): int
    {
        try {
            $len = $this->command(function() {
                return $this->handler->lLen($this->queueName);
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
    private function command($callback)
    {
        try {
            return call_user_func($callback);
        } catch (\RedisException $e) {
            if ($this->isConntected()) {
                throw $e;
            }
            $tryTimes = 0; // 尝试3次重连执行
            $error = null;
            do {
                // 失败后重连
                if ($tryTimes == 1) {
                    sleep(1);
                } else if ($tryTimes == 2) {
                    sleep(2);
                }

                // 尝试重连
                try {
                    if ($this->connect()) {
                        return call_user_func($callback);
                    }
                } catch (\RedisException $e) {
                    $error = $e;
                }
                $tryTimes++;
            } while ($tryTimes <= 3);
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
            $ret = $this->command(function() {
                return $this->handler->brPop($this->queueName, 1);
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
        if ($this->handler) {
            $this->handler->close();
            $this->handler = null;
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
            $ret = $this->command(function() use($body) {
                return $this->handler->lPush($this->queueName, $body);
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
