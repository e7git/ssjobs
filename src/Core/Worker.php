<?php

namespace Sayhey\Jobs\Core;

use Sayhey\Jobs\Core\Message;
use Sayhey\Jobs\Common\Util;
use Sayhey\Jobs\Interfaces\ConsumerInterface;
use Sayhey\Jobs\Interfaces\QueueInterface;
use Sayhey\Jobs\Core\Jobs;
use Sayhey\Jobs\Core\Process;
use Sayhey\Jobs\Core\Queue;

/**
 * 子进程类
 *
 */
class Worker
{

    const TYPE_STATIC = 'STATIC';                   // 静态
    const TYPE_DYNAMIC = 'DYNAMIC';                 // 动态

    // 配置
    private $_dataDir = '';                             // 子进程数据文件目录
    private $_maxExecuteTime = 0;                       // 最长运行时间
    private $_maxConsumerCount = 0;                     // 最多消费成功消息数
    private $_dynamicIdleTime = 0;                      // 最长闲置时长
    private $_sleepIdleTime = 60;                       // 达到睡眠条件的闲置时长
    private $_masterPid = 0;                            // 主进程pid
    private $_processName = '';                         // 进程名
    // 运行时
    private $_lastExecuteTime = 0;                      // 最后执行时间
    private $_failedCount = 0;                          // 处理失败消息数
    private $_doneConnt = 0;                            // 处理成功消息数
    private $_ackCount = 0;                             // 处理成功且应答消息数
    private $_rejectCount = 0;                          // 处理成功且拒绝消息数
    private $_repushCount = 0;                          // 处理成功且重新入队消息数
    private $_costTime = 0;                             // 消费成功总耗时
    private $_pid = 0;                                  // 子进程ID
    private $_beginTime = 0;                            // 开始运行的时间戳
    private $_workType = '';                            // 子进程类型
    private $_masterStatus = '';                        // 主进程状态
    private $_dataFile = '';                            // 子进程数据文件
    private $_table = null;                             // 共享内存表

    /**
     * 任务
     * @var Jobs
     */
    private $_job;

    /**
     * 消费者
     * @var ConsumerInterface
     */
    private $_consumer;

    /**
     * 子进程对象
     * @var \Swoole\Process 
     */
    private $_process;

    /**
     * 构造方法
     * @param Jobs $job
     * @param string $workType 子进程类型
     * @param \Swoole\Table $table
     */
    public function __construct(Jobs $job, string $workType, \Swoole\Table $table)
    {
        $config = $job->getWorkerConfig();
        $this->_maxExecuteTime = $config['max_execute_time'] ?? 0;
        $this->_maxConsumerCount = $config['max_consumer_count'] ?? 0;
        $this->_dynamicIdleTime = $config['dynamic_idle_time'] ?? 0;
        $this->_dataDir = $config['data_dir'];

        $this->_table = $table;
        $this->_masterPid = $this->_table->get('master', 'pid');
        $this->_processName = $this->_table->get('master', 'pname');
        if (!$this->_masterPid || !$this->_processName) {
            throw new \RuntimeException('worker create failed, table error');
        }

        $this->_workType = $workType;
        $this->_job = $job;
        $this->_consumer = $this->_job->createConsumer();

        $this->_lastExecuteTime = microtime(true);

        $this->_setProcess();
    }

    /**
     * 析构方法
     */
    public function __destruct()
    {
        $this->_process = null;
    }

    /**
     * 刷新主进程状态
     */
    public function _refreshMasterStatus()
    {
        // 共享内存不存在
        if (empty($this->_table)) {
            $this->_masterStatus = '';
            return true;
        }

        // 读取不到共享内存主进程信息
        if (!$master = $this->_table->get('master')) {
            $this->_masterStatus = '';
            return true;
        }

        // 验证数据结构
        if (!isset($master['pid']) || !isset($master['status']) ||
                !isset($master['modified']) || !is_numeric($master['modified']) ||
                $this->_masterPid != $master['pid']) {
            $this->_masterStatus = '';
            return true;
        }

        // 主进程状态显示运行，但共享内存过久未更新，则发送信号检测主进程
        if (Process::STATUS_RUNNING === $master['status'] && time() - $master['modified'] > Process::REFRESH_TABLE_CYCLE + 3) {
            if (!\Swoole\Process::kill($this->_masterPid, 0)) {
                $this->_masterStatus = '';
                return true;
            }
        }

        $this->_masterStatus = $master['status'];
        return true;
    }

    /**
     * 构建子进程数据文件名
     */
    private function _fillInfoFilename()
    {
        $this->_dataFile = $this->_dataDir . intval($this->_pid) . '.info';
    }

    /**
     * 设置进程启动函数
     */
    private function _setProcess()
    {
        $this->_process = new \Swoole\Process(function() {
            $this->_pid = getmypid();
            Util::setProcessName('worker:' . $this->_processName);

            $timer_refresh_status = 0;
            $timer_refresh_file = 0;

            $queue = Queue::getQueue($this->_job->getTopic());

            do {
                try {
                    // 每0.5秒刷新主进程状态
                    if (microtime(true) - $timer_refresh_status > 0.5) {
                        $timer_refresh_status = microtime(true);
                        $this->_refreshMasterStatus();
                    }

                    // 是否继续
                    $where = $this->_isContinue();

                    // 执行任务
                    if ($where) {
                        $this->_run($queue);
                    }

                    // 每5秒更新子进程状态
                    if (time() - $timer_refresh_file > 5) {
                        $timer_refresh_file = time();
                        $this->_saveWorkerInfo();
                    }

                    // 当长时间处于空闲状态，则让进程进入半休眠
                    if ($this->_idleTimeTooLong()) {
                        sleep(2);
                    }
                } catch (\Exception $ex) {
                    $where = false;
                    Util::logException($ex);
                }
            } while ($where);

            // 退出前记录子进程数据
            $this->_saveWorkerInfo();
        });
    }

    /**
     * 启动进程
     * @return int PID
     */
    public function start()
    {
        $this->_beginTime = microtime(true);
        $this->_pid = $this->_process->start();
        $this->_job->mountWorker($this);
        return $this->_pid;
    }

    /**
     * 释放资源
     */
    public function free()
    {
        $this->_job->unloadWorker($this);

        // 删除子进程数据文件
        $this->_saveWorkerInfo(true);

        $this->_process = null;
        $this->_table = null;
        $this->_job = null;
        $this->_consumer = null;
    }

    /**
     * 获取pid
     * @return int
     */
    public function getPid()
    {
        return $this->_pid;
    }

    /**
     * 获取主题
     * @return string
     */
    function getTopic(): string
    {
        return $this->_job->getTopic();
    }

    /**
     * 获取任务
     * @return Jobs
     */
    function getJob(): Jobs
    {
        return $this->_job;
    }

    /**
     * 获取子进程运行的时长
     * @return float
     */
    private function _getDuration()
    {
        if (0 === $this->_beginTime) {
            return 0;
        }
        return round(microtime(true) - $this->_beginTime, 4);
    }

    /**
     * 获取任务空闲的时长
     * @return float
     */
    private function _getIdleTime()
    {
        return round(microtime(true) - $this->_lastExecuteTime, 4);
    }

    /**
     * 是否空闲时长太长
     * @return bool
     */
    public function _idleTimeTooLong(): bool
    {
        if ($this->_sleepIdleTime > 0 && $this->_getIdleTime() > $this->_sleepIdleTime) {
            return true;
        }
        return false;
    }

    /**
     * 获取子进程类型
     * @return string
     */
    public function getWorkType()
    {
        return $this->_workType;
    }

    /**
     * 获取是否静态子进程
     * @return bool
     */
    public function isStatic()
    {
        return $this->getWorkType() === self::TYPE_STATIC;
    }

    /**
     * 是否继续运行
     * @return bool
     */
    private function _isContinue(): bool
    {
        // 主进程非运行
        if (Process::STATUS_RUNNING !== $this->_masterStatus) {
            return false;
        }

        // 运行时间过长
        if ($this->_maxExecuteTime > 0 && $this->_getDuration() > $this->_maxExecuteTime) {
            return false;
        }

        // 消费消息过多
        if ($this->_maxConsumerCount > 0 && $this->_doneConnt > $this->_maxConsumerCount) {
            return false;
        }

        // 非静态进程闲置时间过长
        if ($this->_dynamicIdleTime > 0 && !$this->isStatic() && $this->_getIdleTime() > $this->_dynamicIdleTime) {
            return false;
        }

        return true;
    }

    /**
     * 更新或删除子进程数据文件
     * @param bool $isDeleteFile
     * @return bool
     */
    private function _saveWorkerInfo(bool $isDeleteFile = false): bool
    {
        if ('' === $this->_dataFile) {
            $this->_fillInfoFilename();
        }

        if ('' === $this->_dataFile) {
            return false;
        }

        if ($isDeleteFile) {
            Util::unlink($this->_dataFile);
            return true;
        }

        $info = [
            'pid' => $this->_pid,
            'now' => date('Y-m-d H:i:s'),
            'begin' => date('Y-m-d H:i:s', intval($this->_beginTime)),
            'last' => date('Y-m-d H:i:s', intval($this->_lastExecuteTime)),
            'topic' => $this->getTopic(),
            'type' => $this->getWorkType(),
            'status' => $this->_idleTimeTooLong() ? 'IDLE' : 'RUNNING',
            'done' => $this->_doneConnt,
            'failed' => $this->_failedCount,
            'ack' => $this->_ackCount,
            'reject' => $this->_rejectCount,
            'repush' => $this->_repushCount,
            'duration' => strval($this->_getDuration()), // 运行时长
            'cost' => strval($this->_costTime), // 消费成功总耗时
        ];

        Util::file_put_contents($this->_dataFile, json_encode($info, JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * 读取子进程信息文件
     */
    public function readDataInfo()
    {
        if ('' === $this->_dataFile) {
            $this->_fillInfoFilename();
        }
        if ('' === $this->_dataFile) {
            return false;
        }
        if (!$data = Util::file_get_contents($this->_dataFile)) {
            return false;
        }
        return json_decode($data, true);
    }

    /**
     * 执行
     */
    private function _run(QueueInterface $queue)
    {
        if (null === $body = $queue->pop()) {
            return false;
        }
        $msg = new Message($body);
        $before_time = $this->_lastExecuteTime = microtime(true);

        try {
            // 消费消息
            $this->_consumer->consume($msg);

            if (!$msg->isDone()) {
                $this->_failedCount++;
                return false;
            }

            $this->_doneConnt++;
            $this->_costTime = round($this->_costTime + (microtime(true) - $before_time), 4);

            if ($msg->isAck()) {
                $this->_ackCount++;
                return true;
            }

            if ($msg->isReject()) {
                $this->_rejectCount++;
                return true;
            }

            if ($msg->isRepush() && $queue->repush($body)) {
                $this->_repushCount++;
            }

            return true;
        } catch (\Throwable $ex) {
            Util::logException($ex);
        }

        $this->_failedCount++;
        return false;
    }

}
