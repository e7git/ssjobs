<?php

namespace Sayhey\Jobs;

use Sayhey\Jobs\Common\Config;
use Sayhey\Jobs\Core\Log;
use Sayhey\Jobs\Common\Util;
use Sayhey\Jobs\Interfaces\ConsumerInterface;
use Sayhey\Jobs\Interfaces\NotifierInterface;
use Sayhey\Jobs\Interfaces\QueueInterface;
use Sayhey\Jobs\Interfaces\LogInterface;
use Sayhey\Jobs\Core\Process;

/**
 * 控制台
 * 
 */
class Console
{

    private $_runningMasterPid = 0;                     // 运行中的主进程pid
    private $_runningMasterPidFile = '';                // 运行中的主进程pid文件

    /**
     * 构造方法
     * @param array $config
     */
    public function __construct(array $config)
    {
        try {
            Config::init($config);

            Log::init($config);

            $process = new Process();
            $this->_runningMasterPid = $process->readMasterPid();
            if (!$this->_signal()) {
                $this->_runningMasterPid = null;
            } else {
                $this->_runningMasterPidFile = $process->getMasterPidFile();
            }
        } catch (\Exception $e) {
            Util::logException($e);
            exit($e->getMessage() . PHP_EOL);
        }
    }

    /**
     * 运行
     */
    public function run(string $action = '')
    {
        $actions = [
            'help' => 'help', // 打印帮助信息
            'start' => 'start', // 启动
            'stop' => 'stop', // 停止
            'restart' => 'restart', // 重启
            'revive' => 'revive', // 复活
            'status' => 'status', // 查看状态
            'check' => 'check', // 检查配置
        ];

        if (!$action) {
            global $argv;
            $action = $actions[$argv[1] ?? 'help'] ?? 'help';
        }

        Log::info('console run ' . $action);

        try {
            $this->$action();
        } catch (\Exception $e) {
            Util::logException($e);
            exit($e->getMessage() . PHP_EOL);
        }
    }

    /**
     * 打印帮助信息
     */
    public function help()
    {
        $txt = <<<EOF

{#y}用法:
{##}  command [options]

{#y}可用命令:
{#g}  help              {##}打印帮助信息
{#g}  start             {##}启动
{#g}  stop              {##}停止
{#g}  restart           {##}重启
{#g}  revive            {##}复活
{#g}  status            {##}查看状态
{#g}  check             {##}检查配置

EOF;
        $rep = [
            '{#y}' => "\033[0;33m", //黄色
            '{#g}' => "\033[0;32m", //绿色
            '{##}' => "\033[0m" // 清空颜色
        ];
        echo strtr($txt, $rep);
    }

    /**
     * 启动
     * @return bool
     */
    public function start(): bool
    {
        // 运行中
        if ($this->_runningMasterPid) {
            echo 'process already running, please stop or stop it first, PID=', $this->_runningMasterPid, PHP_EOL;
            return false;
        }

        // 检查配置
        if (true !== $result = $this->_checkConfig()) {
            echo 'configuration has error, ', $result, PHP_EOL;
            return false;
        }

        // 启动
        echo 'start success', PHP_EOL;
        (new Process())->start();

        return true;
    }

    /**
     * 停止
     * @return bool
     */
    public function stop(): bool
    {
        // 运行中则停止
        if ($this->_runningMasterPid) {
            $this->_signal(SIGUSR1);
            echo 'stopping...', PHP_EOL;
            for ($i = 0; $i < 200; $i++) {
                if (!Util::is_file($this->_runningMasterPidFile)) {
                    break;
                }
                usleep(100000); // 0.1秒
            }

            for ($i = 0; $i < 10; $i++) {
                if (!$this->_signal()) {
                    echo 'stop success', PHP_EOL;
                    $this->_runningMasterPid = 0;
                    return true;
                }
                usleep(1000000); // 1秒
            }

            if ($this->_runningMasterPid) {
                echo 'stop failed', PHP_EOL;
                return false;
            }
        }

        echo'process has stopped', PHP_EOL;
        return false;
    }

    /**
     * 重启
     * @return bool
     */
    public function restart()
    {
        // 未运行则启动
        if (!$this->_runningMasterPid) {
            echo 'process not running, it will start', PHP_EOL;
            return $this->start();
        }

        // 运行中则重启
        echo 'process already running, it will restart', PHP_EOL;
        $this->stop();

        if ($this->_runningMasterPid) {
            echo 'restart failed, please try again', PHP_EOL;
            return false;
        }

        return $this->start();
    }

    /**
     * 复活
     * @return bool
     */
    public function revive()
    {
        // 未运行则启动
        if (!$this->_runningMasterPid) {
            echo 'process not running, it will start', PHP_EOL;
            $this->start();
            return true;
        }
        echo 'process is running', PHP_EOL;
        return true;
    }

    /**
     * 检查配置
     */
    public function check()
    {
        if (true !== $result = $this->_checkConfig()) {
            echo 'configuration has error, ', $result, PHP_EOL;
        } else {
            echo 'configuration is OK', PHP_EOL;
        }

        return false;
    }

    /**
     * 展示状态信息
     */
    public function status()
    {
        $file_is_latest = true;
        if (!$this->_runningMasterPid) {
            $file_is_latest = false;
            echo PHP_EOL, 'process is not running! ', PHP_EOL, 'here is the last process status info', PHP_EOL, PHP_EOL;
            sleep(3);
        } else {
            $file_is_latest = true;
            $this->_signal(SIGUSR2);
        }

        echo (new Process())->readStatusInfo($file_is_latest);
        return false;
    }

    /**
     * 检查配置
     * @return string|true 当返回true表通过，string表报错
     */
    private function _checkConfig()
    {
        $config = Config::get();

        // 进程
        if (empty($config['process']['data_dir'])) {
            return 'process.data_dir is empty';
        }

        // 日志
        if (empty($config['log']['class'])) {
            return 'log.class is empty';
        }
        if (!class_exists($config['log']['class'])) {
            return 'log.class ' . $config['log']['class'] . ' not exists';
        }
        if (!isset(class_implements($config['log']['class'])[LogInterface::class])) {
            return 'log.class ' . $config['log']['class'] . ' must implements class ' . LogInterface::class;
        }
        if (isset($config['log']['params']) && !is_array($config['log']['params'])) {
            return 'log.params must be a array';
        }

        // 队列
        if (empty($config['queue']['class'])) {
            return 'queue.class is empty';
        }
        if (!class_exists($config['queue']['class'])) {
            return 'queue.class ' . $config['queue']['class'] . ' not exists';
        }
        if (!isset(class_implements($config['queue']['class'])[QueueInterface::class])) {
            return 'queue.class ' . $config['queue']['class'] . ' must implements class ' . QueueInterface::class;
        }
        if (isset($config['queue']['params']) && !is_array($config['queue']['params'])) {
            return 'queue.params must be a array';
        }

        // 通知
        if (!empty($config['notifier']['class'])) {
            if (!class_exists($config['notifier']['class'])) {
                return 'notifier.class ' . $config['notifier']['class'] . ' not exists';
            }
            if (!isset(class_implements($config['notifier']['class'])[NotifierInterface::class])) {
                return 'notifier.class ' . $config['notifier']['class'] . ' must implements class ' . NotifierInterface::class;
            }
            if (isset($config['notifier']['params']) && !is_array($config['notifier']['params'])) {
                return 'notifier.params must be a array';
            }
        }

        // 任务
        if (empty($config['jobs'])) {
            return 'jobs is empty';
        }
        if (!is_array($config['jobs'])) {
            return 'jobs must be a array';
        }
        foreach ($config['jobs'] as $key => $job) {
            $prefix = 'jobs[' . $key . '].';
            if (!isset($job['topic']) || '' === $job['topic']) {
                return $prefix . 'topic must be a non-empty string';
            }
            if (empty($job['consumer'])) {
                return $prefix . 'consumer must be a class name';
            }
            if (!class_exists($job['consumer'])) {
                return $prefix . 'consumer class ' . $job['consumer'] . ' not exists';
            }
            if (!isset(class_implements($job['consumer'])[ConsumerInterface::class])) {
                return $prefix . 'consumer class ' . $job['consumer'] . ' must implements class ' . ConsumerInterface::class;
            }
            if (isset($job['static_workers']) && (!is_numeric($job['static_workers']) || $job['static_workers'] < 1 || $job['static_workers'] > 1024)) {
                return $prefix . 'static_workers must between 1 and 1024';
            }
            if (isset($job['dynamic_workers']) && (!is_numeric($job['dynamic_workers']) || $job['dynamic_workers'] < 0 || $job['dynamic_workers'] > 1024)) {
                return $prefix . 'dynamic_workers must between 0 and 1024';
            }
            if (isset($job['queue_health_size']) && (!is_numeric($job['queue_health_size']) || $job['queue_health_size'] < 0)) {
                return $prefix . 'queue_health_size must be greater than or equal to 0';
            }
            if (isset($job['max_execute_time']) && (!is_numeric($job['max_execute_time']) || $job['max_execute_time'] < 0)) {
                return $prefix . 'max_execute_time must be greater than or equal to 0';
            }
            if (isset($job['max_consumer_count']) && (!is_numeric($job['max_consumer_count']) || $job['max_consumer_count'] < 0)) {
                return $prefix . 'max_consumer_count must be greater than or equal to 0';
            }
            if (isset($job['dynamic_idle_time']) && (!is_numeric($job['dynamic_idle_time']) || $job['dynamic_idle_time'] < 0)) {
                return $prefix . 'dynamic_idle_time must be greater than or equal to 0';
            }
        }

        return true;
    }

    /**
     * 向进程发送信号
     * @param int $signal
     * @return mix
     */
    private function _signal(int $signal = 0)
    {
        if (!$this->_runningMasterPid) {
            return false;
        }
        return \Swoole\Process::kill($this->_runningMasterPid, $signal);
    }

}
