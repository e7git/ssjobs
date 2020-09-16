<?php

namespace Sayhey\Jobs\Core;

use Sayhey\Jobs\Common\Util;
use Sayhey\Jobs\Interfaces\ConsumerInterface;
use Sayhey\Jobs\Core\Queue;
use Sayhey\Jobs\Core\Worker;

/**
 * Job类
 * 
 */
class Jobs
{

    // 配置
    private $_staticWorkerCount = 1;                    // 静态子进程数
    private $_dynamicWorkerCount = 0;                   // 动态子进程数
    private $_topic = '';                               // 主题名称
    private $_consumerClass;                            // 消费者类
    private $_queueHealthSize = 0;                      // 健康的队列长度, 超出后将开启动态进程
    private $_workerConfig = [];                        // 子进程配置
    // 运行时
    private $_workers = [];                             // 挂载子进程列表
    private $_historyWorkerSummary = [];                // 历史子进程信息汇总
    private $_lastTriggerCondition = [];                // 最后触发消息提醒的数据信息

    /**
     * 队列
     * @var QueueInterface 
     */
    private $_queue = null;

    /**
     * 构造方法
     * @param array $config
     * @param string $workerDataDir
     */
    public function __construct(array $config, string $workerDataDir)
    {
        $this->_topic = $config['topic'];
        $this->_consumerClass = $config['consumer'];
        $this->_staticWorkerCount = $config['static_workers'] ?? 1;
        $this->_dynamicWorkerCount = $config['dynamic_workers'] ?? 0;
        $this->_queueHealthSize = $config['queue_health_size'] ?? 0;

        $this->_workerConfig = [
            'max_execute_time' => $config['max_execute_time'] ?? 0,
            'max_consumer_count' => $config['max_consumer_count'] ?? 0,
            'dynamic_idle_time' => $config['dynamic_idle_time'] ?? 0,
            'data_dir' => $workerDataDir
        ];

        $this->_queue = Queue::getQueue($this->_topic);

        $this->_lastConsumerTime = microtime(true);
    }

    /**
     * 返回子进程默认配置
     * @return array
     */
    public function getWorkerConfig(): array
    {
        return $this->_workerConfig;
    }

    /**
     * 返回主题
     * @return string
     */
    public function getTopic(): string
    {
        return $this->_topic;
    }

    /**
     * 创建消费者
     * @return ConsumerInterface
     */
    public function createConsumer(): ConsumerInterface
    {
        return new $this->_consumerClass();
    }

    /**
     * 返回队列消息数
     * @return int
     */
    private function _getQueueSize(): int
    {
        return $this->_queue->size();
    }

    /**
     * 挂载子进程
     * @param Worker $worker
     */
    public function mountWorker(Worker $worker)
    {
        $this->_workers[$worker->getPid()] = $worker;
    }

    /**
     * 卸载子进程
     * @param Worker $worker
     */
    public function unloadWorker(Worker $worker)
    {
        unset($this->_workers[$worker->getPid()]);

        // 回收并汇总到历史子进程信息
        $info = $worker->readDataInfo();
        $this->_historyWorkerSummary['workers'] = ($this->_historyWorkerSummary['workers'] ?? 0) + 1;
        $this->_historyWorkerSummary['done'] = ($this->_historyWorkerSummary['done'] ?? 0) + intval($info['done'] ?? 0);
        $this->_historyWorkerSummary['failed'] = ($this->_historyWorkerSummary['failed'] ?? 0) + intval($info['failed'] ?? 0);
        $this->_historyWorkerSummary['ack'] = ($this->_historyWorkerSummary['ack'] ?? 0) + intval($info['ack'] ?? 0);
        $this->_historyWorkerSummary['reject'] = ($this->_historyWorkerSummary['reject'] ?? 0) + intval($info['reject'] ?? 0);
        $this->_historyWorkerSummary['repush'] = ($this->_historyWorkerSummary['repush'] ?? 0) + intval($info['repush'] ?? 0);
        $this->_historyWorkerSummary['duration'] = round(($this->_historyWorkerSummary['duration'] ?? 0) + floatval($info['duration'] ?? 0), 4);
        $this->_historyWorkerSummary['cost'] = round(($this->_historyWorkerSummary['cost'] ?? 0) + floatval($info['cost'] ?? 0), 4);
    }

    /**
     * 创建静态子进程
     * @param function $callback
     */
    public function createStaticWorkers($callback)
    {
        for ($i = 0; $i < $this->_staticWorkerCount; $i++) {
            call_user_func($callback);
        }
    }

    /**
     * 创建动态子进程
     * @param callable $callback
     */
    public function createDynamicWorkers($callback)
    {
        try {
            if (0 === $this->_queueHealthSize || $this->_queueHealthSize > $this->_getQueueSize()) {
                return false;
            }
            for ($i = count($this->_workers); $i < ($this->_dynamicWorkerCount + $this->_staticWorkerCount); $i++) {
                call_user_func($callback);
            }
        } catch (\Exception $ex) {
            Util::logException($ex);
        }
    }

    /**
     * 检测并获取触发消息提醒
     * @return array
     */
    public function getTriggerNotification(): array
    {
        $msg_arr = [];

        // 队列积压检测
        if ($this->_queueHealthSize > 0) {
            $wait_count = $this->_getQueueSize();

            if ($wait_count > max($this->_queueHealthSize * 2, intval($this->_lastTriggerCondition['queue'] ?? 0))) {
                $msg_arr[] = '队列积压条数=' . $wait_count;
            }

            $this->_lastTriggerCondition['queue'] = $wait_count;
        }

        // 子进程信息汇总检测
        $sum = $this->getWorkerSummary();
        if ($sum['failed'] > intval($this->_lastTriggerCondition['failed'] ?? 0)) {
            $msg_arr[] = '消息处理失败条数=' . $sum['failed'];
            $this->_lastTriggerCondition['failed'] = $sum['failed'];
        }
        if ($sum['reject'] > intval($this->_lastTriggerCondition['reject'] ?? 0)) {
            $msg_arr[] = '消息拒绝条数=' . $sum['reject'];
            $this->_lastTriggerCondition['reject'] = $sum['reject'];
        }
        if ($sum['repush'] > intval($this->_lastTriggerCondition['repush'] ?? 0)) {
            $msg_arr[] = '消息重新入队条数=' . $sum['repush'];
            $this->_lastTriggerCondition['repush'] = $sum['repush'];
        }

        return $msg_arr;
    }

    /**
     * 获取子进程信息汇总
     * @return array
     */
    public function getWorkerSummary(): array
    {
        $item = [
            'topic' => $this->getTopic(),
            'queue' => $this->_getQueueSize(),
            'error' => [],
            'workers' => 0,
            'history_workers' => 0,
            'done' => 0,
            'failed' => 0,
            'ack' => 0,
            'reject' => 0,
            'repush' => 0,
            'duration' => 0,
            'cost' => 0,
        ];
        foreach ($this->_workers as $pid => $worker) {
            if (!!$info = $worker->readDataInfo()) {
                $item['workers']++;
                $item['done'] += intval($info['done'] ?? 0);
                $item['failed'] += intval($info['failed'] ?? 0);
                $item['ack'] += intval($info['ack'] ?? 0);
                $item['reject'] += intval($info['reject'] ?? 0);
                $item['repush'] += intval($info['repush'] ?? 0);
                $item['duration'] += round($info['duration'] ?? 0, 4);
                $item['cost'] += round($info['cost'] ?? 0, 4);
            } else {
                $item['error'][] = 'READ_FAIL';
            }
            if (!isset($this->_workers[$pid])) {
                $item['error'][] = 'MISMATCH';
            }
        }

        $history = $this->_historyWorkerSummary;
        if (!empty($history['workers'])) {
            $item['history_workers'] = intval($history['workers']);
            $item['done'] += intval($history['done'] ?? 0);
            $item['failed'] += intval($history['failed'] ?? 0);
            $item['ack'] += intval($history['ack'] ?? 0);
            $item['reject'] += intval($history['reject'] ?? 0);
            $item['repush'] += intval($history['repush'] ?? 0);
            $item['duration'] += round($history['duration'] ?? 0, 4);
            $item['cost'] += round($history['cost'] ?? 0, 4);
        }

        return $item;
    }

}
