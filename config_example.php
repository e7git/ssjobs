<?php

/* ------------------------------------------------------------------------ 
 * 配置文件示例
 * 实例化控制台[Sayhey\Jobs\Console]时传入配置
 * 实际使用时只要传入控制台的配置参数符合以下结构即可，不必须使用该文件
 * 标有[必填]的为必需配置，否则可不作设置
 * ------------------------------------------------------------------------ */

return [
    // 进程 [必填]
    'process' => [
        'user' => 'www:www',                                // 执行用户
        'process_name' => 'ssjobs',                         // 进程名关键词（默认ssjobs，启动后主进程名为master:ssjobs，子进程名为worker:ssjobs）
        'data_dir' => __DIR__ . '/data/process'             // [必填] 进程数据目录（存储进程运行时的数据文件）
    ],
    
    // 日志 [必填]
    'log' => [
        'class' => '\Sayhey\Jobs\Demo\FileLogger',          // [必填] 日志类，必须实现[Sayhey\Jobs\Interfaces\LogInterface]
        'params' => [                                       // 日志类所用参数，按需设置
            'log_dir' => __DIR__ . '/data/logs' 
        ]           
    ],
    
    // 队列 [必填]
    'queue' => [
        'class' => '\Sayhey\Jobs\Demo\RedisQueue',          // [必填] 队列类，必须实现[Sayhey\Jobs\Interfaces\QueueInterface]
        'params' => [                                       // 队列类所用参数，按需设置
            'host' => '127.0.0.1',
            'port' => 6379,
            'pass' => '',
            'db' => 0
        ]
        
    ],
    
    // 通知 [选填]
    'notifier' => [
        'class' => '\Sayhey\Jobs\Demo\DingTalkNotifier',    // [可选必填] 通知类，必须实现[Sayhey\Jobs\Interfaces\DingTalkNotifier]
        'params' => [                                       // 通知类所用参数，按需设置
            'token' => 'xxxxxx', 
            'prefix' => 'prefix',
            'log_notify' => true,                           // 是否允许发送日志通知
            'jobs_check_notify' => true                     // 是否允许发送任务监控报警
        ],
    ],
    
    // 任务 [必填]
    'jobs' => [
        [
            'topic' => 'test.topic',                        // [必填] 主题，即队列主题
            'consumer' => '\Sayhey\Jobs\Demo\TestConsumer', // [必填] 消费者类，必须实现[Sayhey\Jobs\Interfaces\ConsumerInterface]
            'static_workers' => 2,                          // 静态子进程数（1至1024之间的整数，默认1）
            'dynamic_workers' => 2,                         // 动态子进程数（0至1024之间的整数，默认0）
            'queue_health_size' => 100,                     // 健康的队列长度（默认0，即不判断队列健康）
            'max_execute_time' => 3600,                     // 子进程最长运行时间（单位秒, 0为不限制）
            'max_consumer_count' => 1000,                   // 子进程最多消费成功任务数量（单位秒, 0为不限制）
            'dynamic_idle_time' => 60,                      // 动态子进程闲置的最长时间（单位秒, 0为不限制）
            'queue' => [                                    // 必要时可以单独为任务设定队列类
                'class' => '\Sayhey\Jobs\Demo\RedisQueue',          // [必填] 队列类，必须实现[Sayhey\Jobs\Interfaces\QueueInterface]
                'params' => [                                       // 队列类所用参数，按需设置
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'pass' => '',
                    'db' => 0
                ]
            ],
        ],
    ]
];
