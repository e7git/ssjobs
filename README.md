# ssjobs（swoole-simple-jobs）

基于swoole的job调度组件

e7git@outlook.com

## 场景
系统中允许异步处理的长时任务，如发送邮件、导出文件、报表统计、第三方交互等。

## 特性

- 基于swool的process实现多进程管理，可为不同的任务topic配置不同的worker进程数。
- 静态worker退出后自动拉起，同时支持动态worker进程，队列积压时创建动态worker，闲置时释放。
- worker运行时间和消费数量可配置，防止业务代码内存泄漏。
- 内置redis队列操作，内置文件日志系统，内置消费者适配器。
- 配置有通知模块，可配错误日志通知和任务监控报警。
- 控制台命令完善，包括启动、停止、重启、复活、配置检测、帮助等。
- 使用共享内存和文件控制进程运行过程，基本不会产生僵尸进程。
- 灵活，队列模块、日志模块、通知模块等均可定义，jobs配置详细到每个topic。
- 健壮，进程文件被破坏可自动修复，异常退出产生的垃圾文件会自动清理，主进程被杀死子进程自动平滑退出、停止和重启均是平滑操作。

## 结构
```
.
├── composer.json
├── config_example.php	# 配置文件示例
├── producer_example.php	# 生产者示例
├── LICENSE
├── README.md
└── src
    ├── Common	# 通用模块
    │   ├── Config.php	# 配置类
    │   └── Util.php	# 工具类
    ├── Console.php	# 控制台，入口
    ├── Core	核心模块
    │   ├── Jobs.php	# 任务类
    │   ├── Log.php	 #日志类
    │   ├── Message.php	# 消息类
    │   ├── Process.php	# 进程管理类，核心内容
    │   ├── Queue.php	# 队列类
    │   └── Worker.php	# 子进程类
    ├── Demo	示例模块
    │   ├── DingTalkNotifier.php	# 钉钉机器人通知
    │   ├── FileLogger.php	# 传统文件日志
    │   ├── RedisQueue.php	# Redis队列操作
    │   └── TestConsumer.php	# 测试消费者类
    ├── Exception	# 异常模块
    │   ├── FatalException.php	致命异常
    │   └── LogException.php	日志异常
    └── Interfaces	接口模块
        ├── ConsumerInterface.php	# 消费者接口
        ├── LogInterface.php	# 日志接口
        ├── NotifierInterface.php	# 通知接口
        └── QueueInterface.php	# 队列接口
```

## 示例

```
# 安装
composer require sayhey/simple-swoole-jobs
```

```
# 引入
# 拷贝config_example.php到自己的项目中，并按需修改
# 在项目中新建入口文件，如ssjobs.php，核心内容如下：
require_once '项目目录/vendor/autoload.php';
$config = include('项目配置文件目录/config_example.php');
$console = new Sayhey\Jobs\Console($config); // 此处传入的$config只要符合config_example.php的结构即可
$console->run();
```

```
# 使用
# php 项目目录/ssjobs.php

用法:
  command [options]

可用命令:
  help              打印帮助信息
  start             启动
  stop              停止
  restart           重启
  revive            复活
  status            查看状态
  check             检查配置

# 建议定期重启jobs，可使用crontab：
0 0 * * * php 项目目录/ssjobs.php restart   # 定时重启，未运行时直接运行，运行中则先平滑退出，再启动
1 0 * * * php 项目目录/ssjobs.php revive    # 未运行则启动，应对上一步的重启失败

```

## 感谢
swoole
binsuper
