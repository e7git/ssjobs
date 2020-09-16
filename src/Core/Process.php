<?php

namespace Sayhey\Jobs\Core;

use Sayhey\Jobs\Core\Log;
use Sayhey\Jobs\Common\Config;
use Sayhey\Jobs\Common\Util;
use Sayhey\Jobs\Core\Jobs;
use Sayhey\Jobs\Core\Worker;
use Sayhey\Jobs\Interfaces\NotifierInterface;
use Sayhey\Jobs\Exception\FatalException;

/**
 * 进程管理
 * 
 */
class Process
{

    // 枚举
    const VERSION = '1.0';                              // 版本
    const STATUS_RUNNING = 'RUNNING';                   // 运行中
    const STATUS_WAIT = 'WAIT';                         // 等待所有子进程平滑结束
    const STATUS_STOP = 'STOP';                         // 强制退出
    const REFRESH_TABLE_CYCLE = 30;                     // 主进程共享内存表数据周期，单位秒
    const MAX_STATUS_GHOST_INFO_FILE_SIZE = 10;         // 进程状态残影文件最大容量，单位MB

    // 配置
    private $_dataDir = '';                             // 数据文件存储路径
    private $_masterPidFile = '';                       // 存储主进程信息的文件路径
    private $_statusInfoFile = '';                      // 存储进程状态的文件路径
    private $_statusGhostInfoFile = '';                 // 存储进程状态残影文件路径
    private $_workerDataDir = '';                       // 存储子进程信息文件目录
    private $_processName = 'ssjobs';                   // 进程名称
    // 运行时
    private $_pid = 0;                                  // 进程ID
    private $_status = '';                              // 进程状态
    private $_beginTime = 0;                            // 记录进程开始时间
    private $_workers = [];                             // 子进程列表
    private $_jobs = [];                                // 任务列表

    /**
     * 共享内存表
     * @var \swoole\table
     */
    private $_table = null;

    /**
     * 消息通知对象
     * @var NotifierInterface
     */
    private $_notifier = null;

    /**
     * 构造方法
     * @throws Exception
     */
    public function __construct()
    {
        // 获取配置
        $config = Config::get('process');
        if (empty($config) || empty($config['data_dir'])) {
            throw new FatalException('config process.data_dir is empty');
        }

        // 设置消息通知
        $this->setMessageNotifier();

        // 加载配置
        $this->_processName = $config['process_name'] ?? $this->_processName;
        $this->_dataDir = rtrim($config['data_dir'], DIRECTORY_SEPARATOR);
        $this->_masterPidFile = $this->_dataDir . DIRECTORY_SEPARATOR . 'master.pid';
        $this->_statusInfoFile = $this->_dataDir . DIRECTORY_SEPARATOR . 'status.info';
        $this->_statusGhostInfoFile = $this->_dataDir . DIRECTORY_SEPARATOR . 'status-ghost.info';
        $this->_workerDataDir = $this->_dataDir . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR;

        // 创建数据目录
        if (!Util::mkdir($this->_dataDir) || !Util::mkdir($this->_workerDataDir)) {
            throw new FatalException('mkdir process.data_dir failed');
        }
    }

    /**
     * 设置消息通知
     */
    protected function setMessageNotifier()
    {
        $config = Config::get('notifier', '', []);
        $class = $config['class'] ?? null;
        $params = (isset($config['params']) && is_array($config['params'])) ? $config['params'] : [];
        if (empty($class)) {
            throw new FatalException('config notifier.class is empty');
        }
        if (!class_exists($class)) {
            throw new FatalException('notifier.class ' . $class . ' not exists');
        }
        if (!isset(class_implements($class)[NotifierInterface::class])) {
            throw new FatalException('notifier.class ' . $class . ' must implements class ' . NotifierInterface::class);
        }

        $this->_notifier = new $class($params);
    }

    /**
     * 退出主进程
     */
    protected function exit()
    {
        $this->_saveStatusInfo(true);

        Util::unlink($this->_masterPidFile);

        // 清空子进程信息文件目录
        Util::mkdir($this->_workerDataDir);
        if (!!$fns = scandir($this->_workerDataDir)) {
            foreach ($fns as $fn) {
                $file = $this->_workerDataDir . $fn;
                if (is_file($file) && (!!$arr = explode('.', $fn)) &&
                        2 === count($arr) && is_numeric($arr[0]) && 'info' === $arr[1]) {
                    Util::unlink($file);
                }
            }
        }

        Log::info('master exit');
        exit;
    }

    /**
     * 获取主进程pid，通过读文件
     * @return int
     */
    public function readMasterPid(): int
    {
        return intval(Util::file_get_contents($this->_masterPidFile));
    }

    /**
     * 获取主进程pid文件名
     * @return int
     */
    public function getMasterPidFile(): string
    {
        return $this->_masterPidFile;
    }

    /**
     * 写主进程pid到文件
     * @throws Exception
     */
    private function _writeMasterPid()
    {
        if (!Util::file_put_contents($this->_masterPidFile, $this->_pid)) {
            throw new FatalException('file_put_contents master-pid file failed');
        }
    }

    /**
     * 检查主进程pid文件，如果文件不存在则重新创建，如果文件存在且内容不符则退出
     */
    private function _checkMasterPid()
    {
        if ($this->_pid !== $pid = $this->readMasterPid()) {
            if ($pid) {
                Log::error('master.pid mismatching, pid=' . $this->_pid . ', file pid=' . $pid);
                $this->safeExit();
            } else {
                $this->_writeMasterPid();
            }
        }
    }

    /**
     * 构建共享内存表并更新
     * @throws Exception
     */
    private function _initTable()
    {
        $this->_table = new \Swoole\Table(1024);
        $this->_table->column('pid', \Swoole\Table::TYPE_INT, 4);
        $this->_table->column('pname', \Swoole\Table::TYPE_STRING, 32);
        $this->_table->column('status', \Swoole\Table::TYPE_STRING, 16);
        $this->_table->column('modified', \Swoole\Table::TYPE_INT, 4);
        if (true !== $this->_table->create()) {
            throw new FatalException('can not init table');
        }

        $this->_refreshTable();
    }

    /**
     * 更新主进程共享内存表数据
     * @throws Exception
     */
    private function _refreshTable()
    {
        if ($this->_table) {
            $ret = $this->_table->set('master', [
                'pid' => $this->_pid,
                'pname' => $this->_processName,
                'status' => $this->_status,
                'modified' => time()
            ]);

            if (true !== $ret) {
                throw new FatalException('can not save update master table, pid=' . $this->_pid);
            }
        }
    }

    /**
     * 启动
     */
    public function start()
    {
        // 初始化
        $this->_init();

        // 注册信号
        $this->_registSignal();

        // 注册任务
        $this->_registJobs();

        // 注册定时器
        $this->_registTimer();
    }

    /**
     * 初始化
     * @throws Exception
     */
    private function _init()
    {
        $this->_beginTime = time();

        // 判断进程是否正在运行
        if (is_file($this->_masterPidFile) && (!!$pid = $this->readMasterPid())) {
            for ($i = 0; $i < 30; $i++) {
                if (\Swoole\Process::kill($pid, 0)) {
                    exit('process already runing, please stop or kill it first, pid=' . $pid . PHP_EOL);
                }
                usleep(100000);
            }
        }
        Util::unlink($this->_masterPidFile);
        Util::unlink($this->_statusInfoFile);

        $this->_saveStatusInfo();

        // 使当前进程蜕变为一个守护进程
        \Swoole\Process::daemon();

        // 设置进程名
        Util::setProcessName('master:' . $this->_processName);

        // 获取pid
        if (!$this->_pid = getmypid()) {
            throw new FatalException('can not get the master pid');
        }

        // 设置状态为启动
        $this->_status = self::STATUS_RUNNING;

        // 写主进程pid到文件
        $this->_writeMasterPid();

        // 初始化共享内存表
        $this->_initTable();

        Log::info('master start, pid=' . $this->_pid);
    }

    /**
     * 注册信号
     */
    private function _registSignal()
    {
        // 平滑退出
        \Swoole\Process::signal(SIGUSR1, function($param) {
            $this->safeExit();
        });

        // 保存主进程的状态信息
        \Swoole\Process::signal(SIGUSR2, function($param) {
            $this->_saveStatusInfo();
        });

        // 子进程关闭信号
        \Swoole\Process::signal(SIGCHLD, function($param) {
            try {
                while (!!$ret = \Swoole\Process::wait(false)) {
                    $pid = $ret['pid'];
                    $worker = $this->_workers[$pid] ?? null;
                    if (!$worker) {
                        Log::error('worker pid not found, pid=' . $pid);
                    }
                    unset($this->_workers[$pid]);

                    // 主进程正常运行且子进程是静态类型，则重启该进程
                    if ($this->isRunning() && $worker && $worker->isStatic()) {
                        // 多次尝试重启进程
                        for ($i = 0; $i < 3; $i++) {
                            if (!!$new_pid = $this->forkWorker($worker->getJob(), $worker->getWorkType())) {
                                break;
                            }
                        }

                        // 重启失败
                        if (!$new_pid) {
                            $errno = swoole_errno();
                            $errmsg = swoole_strerror($errno);
                            Log::error("worker process restart failed, it will exited later; errno: {$errno} errmsg: {$errmsg}");
                            $this->safeExit();
                            continue;
                        }
                        Log::info("worker restart, signal={$param}, pid={$new_pid}, type={$worker->getWorkType()}");
                    } else {
                        Log::info("worker exit, signal={$param}, pid={$pid}, type={$worker->getWorkType()}");
                    }

                    // 释放worker资源
                    if ($worker) {
                        $worker->free();
                        unset($worker);
                    }

                    // 主进程状态为WAIT且所有子进程退出, 则主进程安全退出
                    if (empty($this->_workers) && $this->isWait()) {
                        Log::info('all workers exit, master will exited later');
                        $this->exit();
                    }
                }
            } catch (\Exception $e) {
                Util::logException($e);
            }
        });
    }

    /**
     * 注册任务
     */
    private function _registJobs()
    {
        $config = Config::get('jobs');
        foreach ($config as $job_config) {
            $job = new Jobs($job_config, $this->_workerDataDir);

            $job->createStaticWorkers(function() use($job) {
                if (!$pid = $this->forkWorker($job, Worker::TYPE_STATIC)) {
                    $errno = swoole_errno();
                    $errmsg = swoole_strerror($errno);
                    Log::error("worker start failed, it will exited later; errno: {$errno} errmsg: {$errmsg}");
                    $this->safeExit();
                } else {
                    Log::info("worker start, pid={$pid}, type=" . Worker::TYPE_STATIC);
                }
            });
            $this->_jobs[] = $job;
        }
    }

    /**
     * 注册定时器
     */
    private function _registTimer()
    {
        // 关闭协程
        \Swoole\Timer::set([
            'enable_coroutine' => false,
        ]);

        // 每5秒动态进程管理
        \Swoole\Timer::tick(5000, function() {
            $this->_checkDynamic();
        });

        // 每30秒更新主进程共享内存表数据
        \Swoole\Timer::tick(self::REFRESH_TABLE_CYCLE * 1000, function() {
            $this->_refreshTable();
        });

        // 每60秒保存进程状态信息 & 检测主进程pid文件
        \Swoole\Timer::tick(60000, function() {
            \Swoole\Process::kill($this->_pid, SIGUSR2);
            $this->_checkMasterPid();
        });

        // 每300秒任务检测并通知
        if (!empty($this->_notifier) && Config::get('notifier', 'jobs_check_notify')) {
            \Swoole\Timer::tick(300000, function() {
                foreach ($this->_jobs as $job) {
                    if (!!$notification = $job->getTriggerNotification()) {
                        $error = sprintf("[%s][pname=%s][topic=%s]%s：%s", date('Y-m-d H:i:s'), $this->_processName, $job->getTopic(), '任务监控警报', implode('，', $notification));
                        $notifier = $this->_notifier;
                        go(function() use($notifier, $error) {
                            $notifier->send($error);
                        });
                    }
                }
            });
        }
    }

    /**
     * fork子进程
     * @param Jobs $job
     * @param string $worker_type 子进程类型
     * @return int 成功返回子进程的ID，失败返回false
     */
    protected function forkWorker(Jobs $job, string $worker_type)
    {
        if (!$this->isRunning()) {
            return false;
        }

        $worker = new Worker($job, $worker_type, $this->_table);

        try {
            if (!!$pid = $worker->start()) {
                $this->_workers[$pid] = $worker;
            }
        } catch (\Exception $ex) {
            Util::logException($ex);
        }

        return $pid;
    }

    /**
     * 动态进程管理
     */
    private function _checkDynamic()
    {
        try {
            foreach ($this->_jobs as $job) {
                $job->createDynamicWorkers(function() use($job) {
                    if (!!$pid = $this->forkWorker($job, Worker::TYPE_DYNAMIC)) {
                        Log::info("worker start, pid={$pid}, type=" . Worker::TYPE_DYNAMIC);
                    }
                });
            }
        } catch (\Throwable $ex) {
            Util::logException($ex);
        }
    }

    /**
     * 平滑退出，通知并等待所有子进程退出
     */
    public function safeExit()
    {
        $this->_status = self::STATUS_WAIT;
        $this->_refreshTable();
        if (empty($this->_workers)) {
            Log::info('master now exit, all workers already exit');
            $this->exit();
        } else {
            Log::info('master will exit, wait workers exit');
        }
    }

    /**
     * 保存主进程的状态信息
     * @param bool $appendGhost
     * @return string
     * @throws Exception
     */
    private function _saveStatusInfo(bool $appendGhost = false)
    {
        if (!Util::mkdir($this->_dataDir)) {
            Log::info('save status failed, dir error');
            if ($appendGhost) {
                Log::info('save status-ghost failed, dir error');
            }
        }

        $content = $this->_buildStatusInfo();

        // 保存到状态文件
        if (!Util::file_put_contents($this->_statusInfoFile, $content)) {
            Log::info('save status failed');
        }

        // 保存到状态残影文件
        if ($appendGhost && !empty($this->_statusGhostInfoFile)) {
            if (Util::is_file($this->_statusGhostInfoFile) && filesize($this->_statusGhostInfoFile) > self::MAX_STATUS_GHOST_INFO_FILE_SIZE * 1024 * 1024) {
                Util::unlink($this->_statusGhostInfoFile);
            }

            if (!Util::file_put_contents($this->_statusGhostInfoFile, PHP_EOL . PHP_EOL . $content, FILE_APPEND | LOCK_EX)) {
                Log::info('save status-ghost failed');
            }
        }
    }

    /**
     * 读取状态信息
     * @param bool $fileIsLatest
     * @return string
     */
    public function readStatusInfo(bool $fileIsLatest = false): string
    {
        if ($fileIsLatest) {
            Util::file_is_latest($this->_statusInfoFile);
        }

        return (string) Util::file_get_contents($this->_statusInfoFile);
    }

    /**
     * 状态是否为运行中
     * @return bool
     */
    public function isRunning()
    {
        return self::STATUS_RUNNING === $this->_status;
    }

    /**
     * 状态是否为等待所有子进程平滑结束
     * @return bool
     */
    public function isWait()
    {
        return self::STATUS_WAIT === $this->_status;
    }

    /**
     * 构建进程状态信息
     * @return string
     */
    private function _buildStatusInfo()
    {
        $str = '----------------------------------------------------------------------- Status -----------------------------------------------------------------------' . PHP_EOL;

        $extime = time() - $this->_beginTime;

        // 汇总子进程信息到任务
        $worker_count = 0;
        $jobs = [];
        foreach ($this->_jobs as $job) {
            $item = $job->getWorkerSummary();
            $worker_count += $item['workers'];
            $jobs[] = $item;
        }

        // 系统信息
        $str .= '# System' . PHP_EOL;
        $str .= "Process name: \t\t" . $this->_processName . PHP_EOL;
        $str .= "Version: \t\t" . self::VERSION . PHP_EOL;
        $str .= PHP_EOL;

        // 运行信息
        $str .= '# Rumtime' . PHP_EOL;
        $str .= "Start: \t\t\t" . date('Y-m-d H:i:s', $this->_beginTime) . PHP_EOL;
        $str .= "Now: \t\t\t" . date('Y-m-d H:i:s') . PHP_EOL;
        $str .= "Duration: \t\t" . (floor($extime / 60) . 'm ' . ($extime % 60) . 's') . PHP_EOL;
        $str .= "Loadavg: \t\t" . Util::getSysLoadAvg() . PHP_EOL;
        $str .= "Memory used: \t\t" . Util::getMemoryUsage() . PHP_EOL;
        $str .= PHP_EOL;

        // 主进程信息
        $str .= '# Master' . PHP_EOL;
        $str .= "Pid: \t\t\t" . $this->_pid . PHP_EOL;
        $str .= "Status: \t\t" . $this->_status . PHP_EOL;
        $str .= "Register Workers: \t" . count($this->_workers) . PHP_EOL;
        $str .= "Real Workers: \t\t" . $worker_count . PHP_EOL;
        $str .= PHP_EOL;

        // 任务信息
        $str .= '# Jobs' . PHP_EOL;
        $str .= '------------------------------------------------------------------------------------------------------------------------------------------------------' . PHP_EOL;
        $str .= $this->_formatStatusInfo(['Topic', 'Queue', 'Workers', 'HistoryWorker', 'AvgConsumerTime', 'Done', 'Ack', 'Reject', 'Repush', 'Failed']) . PHP_EOL;
        $str .= '------------------------------------------------------------------------------------------------------------------------------------------------------' . PHP_EOL;


        foreach ($jobs as $item) {
            $str .= $this->_formatStatusInfo([
                        $item['topic'] ?? '-',
                        $item['queue'] ?? '-',
                        $item['workers'] ?? '-',
                        $item['history_workers'] ?? '-',
                        (isset($item['cost']) && is_numeric($item['cost']) && isset($item['done']) && is_numeric($item['done']) && $item['done'] > 0) ? round($item['cost'] / $item['done'], 4) . 's' : '-',
                        ($item['done'] ?? '-'),
                        ($item['ack'] ?? '-'),
                        ($item['reject'] ?? '-'),
                        ($item['repush'] ?? '-'),
                        ($item['failed'] ?? '-'),
                    ]) . PHP_EOL;
        }
        return $str;
    }

    /**
     * 格式化输出
     * @param array $data
     * @return string
     */
    private function _formatStatusInfo(array $data)
    {
        $str = '';
        $rule = [25, 10, 10, 15, 20, 12, 12, 12, 12];
        foreach ($data as $i => $col) {
            $str .= str_pad($col, ($rule[$i] ?? 0) < strlen($col) ? strlen($col) + 2 : ($rule[$i] ?? 0));
        }
        return $str;
    }

}
