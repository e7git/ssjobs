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

    const TYPE_STATIC = 'STATIC';                       // 静态
    const TYPE_DYNAMIC = 'DYNAMIC';                     // 动态

    // 配置
    private $dataDir = '';                              // 子进程数据文件目录
    private $maxExecuteTime = 0;                        // 最长运行时间
    private $maxConsumerCount = 0;                      // 最多消费成功消息数
    private $dynamicIdleTime = 0;                       // 最长闲置时长
    private $sleepIdleTime = 60;                        // 达到睡眠条件的闲置时长
    private $masterPid = 0;                             // 主进程pid
    private $processName = '';                          // 进程名
    // 运行时
    private $lastExecuteTime = 0;                       // 最后执行时间
    private $failedCount = 0;                           // 处理失败消息数
    private $doneConnt = 0;                             // 处理成功消息数
    private $ackCount = 0;                              // 处理成功且应答消息数
    private $rejectCount = 0;                           // 处理成功且拒绝消息数
    private $repushCount = 0;                           // 处理成功且重新入队消息数
    private $costTime = 0;                              // 消费成功总耗时
    private $pid = 0;                                   // 子进程ID
    private $beginTime = 0;                             // 开始运行的时间戳
    private $workType = '';                             // 子进程类型
    private $masterStatus = '';                         // 主进程状态
    private $dataFile = '';                             // 子进程数据文件
    private $table = null;                              // 共享内存表

    /**
     * 任务
     * @var Jobs
     */
    private $job;

    /**
     * 消费者
     * @var ConsumerInterface
     */
    private $consumer;

    /**
     * 子进程对象
     * @var \Swoole\Process 
     */
    private $process;

    /**
     * 构造方法
     * @param Jobs $job
     * @param string $workType 子进程类型
     * @param \Swoole\Table $table
     */
    public function __construct(Jobs $job, string $workType, \Swoole\Table $table)
    {
        $config = $job->getWorkerConfig();
        $this->maxExecuteTime = $config['max_execute_time'] ?? 0;
        $this->maxConsumerCount = $config['max_consumer_count'] ?? 0;
        $this->dynamicIdleTime = $config['dynamic_idle_time'] ?? 0;
        $this->dataDir = $config['data_dir'];

        $this->table = $table;
        $this->masterPid = $this->table->get('master', 'pid');
        $this->processName = $this->table->get('master', 'pname');
        if (!$this->masterPid || !$this->processName) {
            throw new \RuntimeException('worker create failed, table error');
        }

        $this->workType = $workType;
        $this->job = $job;
        $this->consumer = $this->job->createConsumer();

        $this->lastExecuteTime = microtime(true);

        $this->setProcess();
    }

    /**
     * 析构方法
     */
    public function __destruct()
    {
        $this->process = null;
    }

    /**
     * 刷新主进程状态
     */
    public function refreshMasterStatus()
    {
        // 共享内存不存在
        if (empty($this->table)) {
            $this->masterStatus = '';
            return true;
        }

        // 读取不到共享内存主进程信息
        if (!$master = $this->table->get('master')) {
            $this->masterStatus = '';
            return true;
        }

        // 验证数据结构
        if (!isset($master['pid']) || !isset($master['status']) ||
                !isset($master['modified']) || !is_numeric($master['modified']) ||
                $this->masterPid != $master['pid']) {
            $this->masterStatus = '';
            return true;
        }

        // 主进程状态显示运行，但共享内存过久未更新，则发送信号检测主进程
        if (Process::STATUS_RUNNING === $master['status'] && time() - $master['modified'] > Process::REFRESH_TABLE_CYCLE + 3) {
            if (!\Swoole\Process::kill($this->masterPid, 0)) {
                $this->masterStatus = '';
                return true;
            }
        }

        $this->masterStatus = $master['status'];
        return true;
    }

    /**
     * 构建子进程数据文件名
     */
    private function fillInfoFilename()
    {
        $this->dataFile = $this->dataDir . intval($this->pid) . '.info';
    }

    /**
     * 设置进程启动函数
     */
    private function setProcess()
    {
        $this->process = new \Swoole\Process(function() {
            $this->pid = getmypid();
            Util::setProcessName('worker:' . $this->processName);

            $timer_refresh_status = 0;
            $timer_refresh_file = 0;

            $queue = Queue::getQueue($this->job->getTopic());

            do {
                try {
                    // 每0.5秒刷新主进程状态
                    if (microtime(true) - $timer_refresh_status > 0.5) {
                        $timer_refresh_status = microtime(true);
                        $this->refreshMasterStatus();
                    }

                    // 是否继续
                    $where = $this->isContinue();

                    // 执行任务
                    if ($where) {
                        $this->run($queue);
                    }

                    // 每5秒更新子进程状态
                    if (time() - $timer_refresh_file > 5) {
                        $timer_refresh_file = time();
                        $this->saveWorkerInfo();
                    }

                    // 当长时间处于空闲状态，则让进程进入半休眠
                    if ($this->idleTimeTooLong()) {
                        sleep(2);
                    }
                } catch (\Exception $ex) {
                    $where = false;
                    Util::logException($ex);
                }
            } while ($where);

            // 退出前记录子进程数据
            $this->saveWorkerInfo();
        });
    }

    /**
     * 启动进程
     * @return int PID
     */
    public function start()
    {
        $this->beginTime = microtime(true);
        $this->pid = $this->process->start();
        $this->job->mountWorker($this);
        return $this->pid;
    }

    /**
     * 释放资源
     */
    public function free()
    {
        $this->job->unloadWorker($this);

        // 删除子进程数据文件
        $this->saveWorkerInfo(true);

        $this->process = null;
        $this->table = null;
        $this->job = null;
        $this->consumer = null;
    }

    /**
     * 获取pid
     * @return int
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * 获取主题
     * @return string
     */
    function getTopic(): string
    {
        return $this->job->getTopic();
    }

    /**
     * 获取任务
     * @return Jobs
     */
    function getJob(): Jobs
    {
        return $this->job;
    }

    /**
     * 获取子进程运行的时长
     * @return float
     */
    private function getDuration()
    {
        if (0 === $this->beginTime) {
            return 0;
        }
        return round(microtime(true) - $this->beginTime, 4);
    }

    /**
     * 获取任务空闲的时长
     * @return float
     */
    private function getIdleTime()
    {
        return round(microtime(true) - $this->lastExecuteTime, 4);
    }

    /**
     * 是否空闲时长太长
     * @return bool
     */
    public function idleTimeTooLong(): bool
    {
        if ($this->sleepIdleTime > 0 && $this->getIdleTime() > $this->sleepIdleTime) {
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
        return $this->workType;
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
    private function isContinue(): bool
    {
        // 主进程非运行
        if (Process::STATUS_RUNNING !== $this->masterStatus) {
            return false;
        }

        // 运行时间过长
        if ($this->maxExecuteTime > 0 && $this->getDuration() > $this->maxExecuteTime) {
            return false;
        }

        // 消费消息过多
        if ($this->maxConsumerCount > 0 && $this->doneConnt > $this->maxConsumerCount) {
            return false;
        }

        // 非静态进程闲置时间过长
        if ($this->dynamicIdleTime > 0 && !$this->isStatic() && $this->getIdleTime() > $this->dynamicIdleTime) {
            return false;
        }

        return true;
    }

    /**
     * 更新或删除子进程数据文件
     * @param bool $isDeleteFile
     * @return bool
     */
    private function saveWorkerInfo(bool $isDeleteFile = false): bool
    {
        if ('' === $this->dataFile) {
            $this->fillInfoFilename();
        }

        if ('' === $this->dataFile) {
            return false;
        }

        if ($isDeleteFile) {
            Util::unlink($this->dataFile);
            return true;
        }

        $info = [
            'pid' => $this->pid,
            'now' => date('Y-m-d H:i:s'),
            'begin' => date('Y-m-d H:i:s', intval($this->beginTime)),
            'last' => date('Y-m-d H:i:s', intval($this->lastExecuteTime)),
            'topic' => $this->getTopic(),
            'type' => $this->getWorkType(),
            'status' => $this->idleTimeTooLong() ? 'IDLE' : 'RUNNING',
            'done' => $this->doneConnt,
            'failed' => $this->failedCount,
            'ack' => $this->ackCount,
            'reject' => $this->rejectCount,
            'repush' => $this->repushCount,
            'duration' => strval($this->getDuration()), // 运行时长
            'cost' => strval($this->costTime), // 消费成功总耗时
        ];

        Util::filePutContents($this->dataFile, json_encode($info, JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * 读取子进程信息文件
     */
    public function readDataInfo()
    {
        if ('' === $this->dataFile) {
            $this->fillInfoFilename();
        }
        if ('' === $this->dataFile) {
            return false;
        }
        if (!$data = Util::fileGetContents($this->dataFile)) {
            return false;
        }
        return json_decode($data, true);
    }

    /**
     * 执行
     */
    private function run(QueueInterface $queue)
    {
        if (null === $body = $queue->pop()) {
            return false;
        }
        $msg = new Message($body);
        $before_time = $this->lastExecuteTime = microtime(true);

        try {
            // 消费消息
            $this->consumer->consume($msg);

            if (!$msg->isDone()) {
                $this->failedCount++;
                return false;
            }

            $this->doneConnt++;
            $this->costTime = round($this->costTime + (microtime(true) - $before_time), 4);

            if ($msg->isAck()) {
                $this->ackCount++;
                return true;
            }

            if ($msg->isReject()) {
                $this->rejectCount++;
                return true;
            }

            if ($msg->isRepush() && $queue->repush($body)) {
                $this->repushCount++;
            }

            return true;
        } catch (\Throwable $ex) {
            Util::logException($ex);
        }

        $this->failedCount++;
        return false;
    }

}
