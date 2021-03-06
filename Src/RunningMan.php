<?php

/**
 * @brief        主程序
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-1-16 23:01:17
 * @copyright    © RunningMan
 */

namespace RunningMan;

use RunningMan\Config;
use RunningMan\Library\Event;
use RunningMan\Library\Connection;
use RunningMan\Library\Util;

require __DIR__ . '/Common/bootstrap.inc.php';

class RunningMan
{

    /**
     * backlog
     */
    const BACKLOG = 512;

    /**
     * 开始标记
     */
    const STATUS_START = 1;

    /**
     * 停止标记
     */
    CONST STATUS_STOP = 2;

    /**
     * 重启标记
     */
    const STATUS_RESTART = 4;

    /**
     * 重载标记
     */
    const STATUS_RELOAD = 8;

    /**
     * 消息类型(status)
     */
    const MSG_QUEUE_TYPE_STATUS = 1;

    /**
     * 守护进程
     * @var boolean
     */
    public $daemon = false;

    /**
     * 子进程数
     * @var integer
     */
    public $worker = 3;

    /**
     * 运行用户
     * @var string
     */
    public $user = 'www';

    /**
     * 运行用户组
     * @var string
     */
    public $group = 'www';

    /**
     * 指令集合
     * @var array
     */
    private $dirList = ['start', 'stop', 'restart', 'reload', 'status'];

    /**
     * 指令
     * @var string
     */
    private $dir = '';

    /**
     * 事件驱动列表
     * @var array
     */
    private $eventList = ['libevent'];

    /**
     * 事件驱动名
     * @var string
     */
    private $eventName = 'select';

    /**
     * 交换协议列表
     * @var array
     */
    private $protocolList = ['text'];

    /**
     * 交换协议名
     * @var string
     */
    private $protocolName = 'text';

    /**
     * 交换协议
     * @var null
     */
    private $protocol = null;

    /**
     * 信号列表
     * @var array
     */
    private $signalList = [SIGINT, SIGALRM, SIGUSR1, SIGUSR2];

    /**
     * 运行状态
     * @var int
     */
    private $status = 0;

    /**
     * 主进程Id
     * @var integer
     */
    private $masterPid = 0;

    /**
     * 进程Id
     * @var array
     */
    private $pidMap = [];

    /**
     * 进程文件
     * @var string
     */
    public $pidFile = '';

    /**
     * 日志文件
     * @var string
     */
    public $logFile = '';

    /**
     * 消息队列
     * @var null
     */
    public $msgQueue = null;

    /**
     * 监听地址
     * @var string
     */
    public $localDomain = '';

    /**
     * 上下文
     * @var object
     */
    public $streamContext = null;

    /**
     * 服务器Socket
     * @var resource
     */
    public $serverSocket = null;

    /**
     * 连接回调
     * @var object
     */
    public $onConnect = null;

    /**
     * 消息回调
     * @var object
     */
    public $onReceive = null;

    /**
     * 发送回调
     * @var object
     */
    public $onSend = null;

    /**
     * 关闭回调
     * @var object
     */
    public $onClose = null;

    /**
     * 错误回调
     * @var object
     */
    public $onError = null;

    /**
     * socket 列表
     * @var array
     */
    public $connections = [];

    /**
     * 不活跃保持时间
     * @var int
     */
    public $keepalived = 60;

    /**
     * 心跳包请求
     * @var string
     */
    public $ping = 'ping';

    /**
     * 心跳包响应
     * @var string
     */
    public $pong = 'pong';

    /**
     * 全局统计
     * @var array
     */
    public static $statistic = [];

    /**
     * 构造器
     * @param string $domain 监听地址
     * @param array $context 上下文选项
     * @throws \Exception 交换协议不支持
     */
    public function __construct($domain, $context = [])
    {
        // 运行文件
        $logDir        = sprintf('%s/Log', RM_RUNTIME);
        $this->pidFile = $logDir . '/RM.pid';
        $this->logFile = $logDir . '/RM.log';
        Util\File::makeDirs($logDir);

        touch($this->logFile);
        chmod($this->logFile, 0622);

        self::$statistic['start_time'] = time();

        // 监听地址
        $this->localDomain = $domain;

        // 流上下文
        $streamContext['socket']['backlog'] = self::BACKLOG;
        $streamContext                      = array_merge($streamContext, $context); // 注意顺序，后面覆盖前面
        $this->streamContext                = stream_context_create($streamContext);

        // 交换协议
        if (!in_array($this->protocolName, $this->protocolList)) {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_PROTOCOL], Config\Code::ERR_PROTOCOL);
        }
        $protocolClass  = __NAMESPACE__ . '\\Library\\Protocol\\' . ucfirst($this->protocolName);
        $this->protocol = new $protocolClass();

        // 事件驱动
        foreach ($this->eventList as $ev) {
            if (extension_loaded($ev)) {
                $this->eventName = $ev;
                break;
            }
        }

        // 回调初始化
        $this->onConnect = function () {
        };
        $this->onReceive = function () {
        };
        $this->onSend    = function () {
        };
        $this->onClose   = function () {
        };
        $this->onError   = function () {
        };

        // 消息队列初始化
        $msgId          = ftok(__FILE__, 's');
        $this->msgQueue = msg_get_queue($msgId);

        // 定时器初始化
        Util\Timer::init();
    }

    /**
     * 运行
     * @return void
     * @throws \Exception 异常
     */
    public function run()
    {
        try {
            // 模式检查
            $this->checkSapi();
            // 命令解析
            $this->parseDir();
            // 前置检查
            $this->checkPre();

            // 指令分发
            switch ($this->dir) {
                case 'start':
                    $this->start();
                    break;

                case 'stop':
                    $this->stop();
                    usleep(100000); // 完整中断输出
                    break;

                case 'restart':
                    $this->restart();
                    break;

                case 'reload':
                    $this->reload();
                    usleep(100000); // 完整中断输出
                    break;

                case 'status';
                    $this->status();
                    usleep(100000); // 完整中断输出
                    break;

                default:
                    break;
            }
        } catch (\Exception $e) {
            $this->printScreen(sprintf('Exception [%s] %s %s:%s', $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine()));
        } catch (\Error $e) {
            $this->printScreen(sprintf('Error [%s] %s %s:%s', $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine()));
        } finally {
        }
        exit;
    }

    /**
     * 开始
     * @return void
     */
    public function start()
    {
        // 启动进程
        $this->startProcess();
        // 启动画面
        $this->bootScreen();
        // 信号注册
        $this->masterSignalProcessor();
        // 信号监听
        $this->signalWatch();
    }

    /**
     * 启动进程
     * @return void
     * @throws \Exception 异常
     */
    public function startProcess()
    {
        $this->status = self::STATUS_START;
        cli_set_process_title('RunningMan: master process (' . __FILE__ . ')');
        if ($this->daemon) {
            umask(0);
            $pid = pcntl_fork();
            if ($pid == - 1) {
                throw new \Exception(Config\Code::$msg[Config\Code::ERR_FORK], Config\Code::ERR_FORK);
            } else if ($pid > 0) { // 当前进程退出，子进程作为 daemon master 继续运行
                usleep(100000); // 完整终端输出
                exit;
            }
        }

        // 更新主进程Id
        $this->setMasterPid();

        // 监听端口请求
        $this->listen();

        // Fork 子进程
        for ($i = 0; $i < $this->worker; $i ++) {
            $this->forkProcess();
        }
    }

    /**
     * 设置主进程Id
     * @return void
     * @throws \Exception 异常
     */
    public function setMasterPid()
    {
        $this->masterPid = posix_getpid();
        Util\File::writeFile($this->pidFile, $this->masterPid, LOCK_EX);
    }

    /**
     * Fork进程
     * @return void
     * @throws \Exception 异常
     */
    public function forkProcess()
    {
        $pid = pcntl_fork();
        if ($pid == - 1) {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_FORK], Config\Code::ERR_FORK);
        } else if ($pid > 0) {
            $this->pidMap[$this->masterPid][] = $pid;
        } else {
            cli_set_process_title('RunningMan: worker process');

            // 设置用户
            $userInfo = posix_getpwuid(posix_getuid());
            if ($userInfo['name'] != $this->user) {
                $user = posix_getpwnam($this->user);
                posix_setuid($user['uid']);
            }

            // 设置用户组
            $groupInfo = posix_getgrgid(posix_getgid());
            if ($groupInfo['name'] != $this->group) {
                $group = posix_getgrnam($this->group);
                posix_setgid($group['gid']);
            }

            // 定时器初始化
            Util\Timer::init(true);

            // 心跳检测
            if ($this->keepalived > 0) {
                Util\Timer::add('keepalived', [function () {
                    $time = time();
                    foreach ($this->connections as $connection) {
                        // 不活跃连接管理
                        if ($time - $connection->activeTime >= $this->keepalived) {
                            $connection->close();
                            unset($this->connections[spl_object_hash($connection)]);
                        }
                    }
                }, null], true, 1);
            }

            // 事件处理
            $this->eventLoop();
            exit; // 无 exit 会导致子进程运行主进程的方法
        }
    }

    /**
     * 信号注册
     * @return void
     * @throws \Exception 异常
     */
    public function masterSignalProcessor()
    {
        foreach ($this->signalList as $signal) {
            pcntl_signal($signal, function ($s) {
                switch ($s) {
                    case SIGINT:
                        $this->stop();
                        break;

                    case SIGALRM:
                        Util\Timer::runTask();
                        break;

                    case SIGUSR1:
                        $this->reload();
                        break;

                    case SIGUSR2:
                        $this->status();
                        break;

                    default:
                        break;
                }
            }, false); // 收到信号，系统调用不从开始处开始处理
            // 这个 false 很重要，会影响主进程信号执行
            // 不是 false 主进程接收到信号但无反应
        }
    }

    /**
     * 进程信号处理
     * @param  int $signal 信号量
     * @return void
     */
    public function workerSignalProcessor($signal)
    {
        switch ($signal) {
            case SIGINT:  // stop
                exit;

            case SIGALRM:
                Util\Timer::runTask();
                break;

            case SIGUSR1: // reload
                exit;

            case SIGUSR2: // status
                $this->statusWorkerProcess();
                break;

            default:
                break;
        }
    }

    /**
     * 信号看守
     * @return void
     * @throws \Exception 异常
     */
    public function signalWatch()
    {
        while (true) {
            pcntl_signal_dispatch();
            $status = 0;
            $pid    = pcntl_wait($status); // 避免子进程僵死; 非 wait3 第二个参数无效
            pcntl_signal_dispatch();    // pcntl_signal(, , false);   没有这个代码，发送 sigint 信号的时候，只有主进程退出
            if ($pid > 0) {
                $this->printScreen("Worker process [$pid] exit with status [$status]");
                unset($this->pidMap[$this->masterPid][array_search($pid, $this->pidMap[$this->masterPid])]);

                // 非正常退出
                if ($this->status != self::STATUS_STOP) {
                    $this->forkProcess();
                } else {
                    if (count($this->pidMap[$this->masterPid]) === 0) {
                        fclose($this->serverSocket);
                        unlink($this->pidFile);
                        $this->printScreen("\33[42;37;5m Stop success. \33[0m");
                        exit;
                    }
                }
                /// 正常退出
            } else {
                // 主进程 -1
            }
        }
    }

    /**
     * 停止进程
     * @return void
     * @throws \Exception 异常
     */
    public function stop()
    {
        $this->status = self::STATUS_STOP;
        // ctrl c 或 signal
        if ($this->masterPid == posix_getpid()) {
            $this->printScreen('Stopping...');
            foreach ($this->pidMap[$this->masterPid] as $pid) {
                posix_kill($pid, SIGINT);
            }
        } else {
            $pid = file_get_contents($this->pidFile);
            $pid and posix_kill($pid, SIGINT);
        }
        /// stop 命令
    }

    /**
     * 重启
     * @return void
     */
    public function restart()
    {
        $this->status = self::STATUS_RESTART;
        $this->stop();
        usleep(200000);  // 避免rest输出到终端与子进程退出状态重合
        $this->printScreen('Restarting...');
        usleep(600000);  // 避免server socket 未释放完成
        $this->start();
    }

    /**
     * 重载
     * @return void
     */
    public function reload()
    {
        $this->status = self::STATUS_RELOAD;
        // ctrl c 或 signal
        if ($this->masterPid == posix_getpid()) {
            $this->printScreen('Reloading...');
            foreach ($this->pidMap[$this->masterPid] as $pid) {
                posix_kill($pid, SIGUSR1);
            }
        } else {
            $pid = file_get_contents($this->pidFile);
            $pid and posix_kill($pid, SIGUSR1);
        }
        /// stop 命令
    }

    /**
     * 状态
     * @return void
     */
    public function status()
    {
        // signal
        if ($this->masterPid == posix_getpid()) {
            $rmVersion  = Config\Config::VERSION;
            $phpVersion = PHP_VERSION;
            if ($this->daemon) {
                $mode = 'Daemon';
            } else {
                $mode = 'Terminal';
            }

            $event     = $this->eventName;
            $protocol  = $this->protocolName;
            $pid       = $this->masterPid;
            $loadavg   = implode(', ', sys_getloadavg());
            $memory    = round(memory_get_usage(true) / 1024 / 1024, 2);
            $startTime = date('Y-m-d H:i:s', self::$statistic['start_time']);
            $runTime   = time() - self::$statistic['start_time'];
            $runDay    = intval($runTime / 86400);
            $runHour   = intval($runTime % 86400 / 3600);
            $runMinute = intval($runTime % 86400 % 3600 / 60);
            $runSecond = intval($runTime % 86400 % 3600 % 60);
            $runTime   = sprintf('%d天%d时%d分%d秒', $runDay, $runHour, $runMinute, $runSecond);

            $pidName       = str_pad('PID', 10, ' ');
            $userName      = str_pad('Group-User', 14, ' ');
            $listenName    = str_pad('Listen', 24, ' ');
            $memoryName    = str_pad('Memory', 10, ' ');
            $connectName   = str_pad('Conn', 8, ' ');
            $recvName      = str_pad('Recv', 8, ' ');
            $heartbeatName = str_pad('Heart', 8, ' ');
            $sendName      = str_pad('Send', 8, ' ');
            $closeName     = str_pad('Close', 8, ' ');
            $errorName     = 'Error';
            $msg           = <<<EOF

_______________________________________________\33[47;30m RunningMan \33[0m______________________________________________
Summary：
 RM Version: ${rmVersion}    PHP Version: ${phpVersion}    Mode: ${mode}    EV: ${event}    Protocol: ${protocol}
 PID: ${pid}    Loadavg: ${loadavg}    RunTime: ${startTime} (${runTime})    Memory: ${memory}M

Worker Process：
\33[47;30m ${pidName}${userName}${listenName}${memoryName}${connectName}${recvName}${heartbeatName}${sendName}${closeName}${errorName} \33[0m
EOF;
            foreach ($this->pidMap[$this->masterPid] as $pid) {
                posix_kill($pid, SIGUSR2);
                msg_receive($this->msgQueue, self::MSG_QUEUE_TYPE_STATUS, $msgType, 1024, $message);
                $msg .= sprintf("\n%s", $message);
            }

            $this->printScreen($msg);
        } else {
            $pid = file_get_contents($this->pidFile);
            $pid and posix_kill($pid, SIGUSR2);
        }
        /// status 命令
    }

    /**
     * 子进程状态
     * @return void
     */
    public function statusWorkerProcess()
    {
        $connect   = str_pad(Connection\Tcp::$statistic['connect'], 8, ' ');
        $recv      = str_pad(Connection\Tcp::$statistic['receive'], 8, ' ');
        $heartbeat = str_pad(Connection\Tcp::$statistic['heartbeat'], 8, ' ');
        $send      = str_pad(Connection\Tcp::$statistic['send'], 8, ' ');
        $close     = str_pad(Connection\Tcp::$statistic['close'], 8, ' ');
        $error     = Connection\Tcp::$statistic['error'];

        $pid    = str_pad(posix_getpid(), 10, ' ');
        $user   = str_pad(sprintf('%s %s', $this->group, $this->user), 14, ' ');
        $local  = str_pad($this->localDomain, 24, ' ');
        $memory = str_pad(round(memory_get_usage(true) / 1024 / 1024, 2) . 'M', 10, ' ');

        $msg = <<<EOF
 ${pid}${user}${local}${memory}${connect}${recv}${heartbeat}${send}${close}${error}
EOF;
        msg_send($this->msgQueue, self::MSG_QUEUE_TYPE_STATUS, $msg);
    }

    /**
     * 检查运行模式
     * @return void
     * @throws \Exception 异常
     */
    public function checkSapi()
    {
        if (PHP_SAPI != 'cli') {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_MODE], Config\Code::ERR_MODE);
        }

        if (!extension_loaded('pcntl') or
            !extension_loaded('posix')
        ) {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_EXTENSION], Config\Code::ERR_EXTENSION);
        }
    }

    /**
     * 解析指令
     * @return void
     * @throws \Exception 异常
     */
    public function parseDir()
    {
        global $argv;
        $dir1 = isset($argv[1]) ? $argv[1] : null;
        $dir2 = isset($argv[2]) ? $argv[2] : null;

        if (!in_array($dir1, $this->dirList)) {
            $exceMsg = sprintf('Usage: php %s {%s} [-d]', $argv[0], implode('|', $this->dirList));
            throw new \Exception($exceMsg, Config\Code::ERR_DIRECTIVE);
        }

        if ($dir2 == '-d') {
            $this->daemon = true;
        }

        $this->dir = $dir1;

    }

    /**
     * 前置检查
     * @return void
     * @throws \Exception 异常
     */
    public function checkPre()
    {
        $masterPid = 0;
        is_file($this->pidFile) and $masterPid = (int) file_get_contents($this->pidFile);
        if ($this->dir == 'start' and $masterPid and posix_kill($masterPid, 0)) {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_RUNNING], Config\Code::ERR_RUNNING);
        }
        if (in_array($this->dir, ['stop', 'restart', 'reload', 'status']) and (!$masterPid or !posix_kill($masterPid, 0))) {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_NOT_RUN], Config\Code::ERR_NOT_RUN);
        }
    }

    /**
     * 打印屏幕
     * @param  string $str 字符串
     * @return void
     */
    public function printScreen($str)
    {
        $logStr = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $str);
        Util\File::writeFile($this->logFile, $logStr);
        echo $str . "\n";
    }

    /**
     * 启动屏幕
     * @return void
     */
    public function bootScreen()
    {
        $rmVersion  = Config\Config::VERSION;
        $phpVersion = PHP_VERSION;

        $event    = $this->eventName;
        $protocol = $this->protocolName;

        if ($this->daemon) {
            $mode = 'Daemon';
            $tips = 'Press stop directive to quit.';
        } else {
            $mode = 'Terminal';
            $tips = 'Press Ctrl+C to quit.';
        }

        $note = <<<EOF

-------------------------------------\33[47;30m RunningMan \33[0m-------------------------------------
RM Version：$rmVersion   PHP Version：$phpVersion   MODE：$mode   Ev：$event   Protocol：$protocol
Master PID：$this->masterPid   Group-User：$this->group-$this->user   Listen：$this->localDomain   Process：$this->worker

\33[44;37;5m Start success. \33[0m  $tips
EOF;
        $this->printScreen($note);
    }

    /**
     * 监听
     * @return void
     * @throws \Exception 创建 socket 失败
     */
    public function listen()
    {
        list($transport) = explode('://', $this->localDomain, 2);
        $errno  = 0;
        $errmsg = '';
        $flag   = $transport === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

        $this->serverSocket = stream_socket_server($this->localDomain, $errno, $errmsg, $flag, $this->streamContext);
        if (!$this->serverSocket) {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_CONNECT_SERVER], Config\Code::ERR_CONNECT_SERVER);
        }

        $serverSocket = socket_import_stream($this->serverSocket);
        socket_set_option($serverSocket, SOL_SOCKET, SO_KEEPALIVE, 1);
        socket_set_option($serverSocket, SOL_TCP, TCP_NODELAY, 1);

        stream_set_blocking($this->serverSocket, 0); // 非阻塞
    }

    /**
     * 事件轮询
     * @return void
     */
    public function eventLoop()
    {
        $eventClass = __NAMESPACE__ . '\\Library\Event\\' . ucfirst($this->eventName);
        $eventIns   = new $eventClass();

        // 接收事件
        $eventIns->add($this->serverSocket, Event\EventInterface::EV_READ, [$this, 'accept']);

        // 注册子进程信号处理
        foreach ($this->signalList as $signal) {
            pcntl_signal($signal, SIG_IGN, false);
            $eventIns->add($signal, Event\EventInterface::EV_SIGNAL, [$this, 'workerSignalProcessor']);
        }
        $eventIns->loop();
    }

    /**
     * 接收连接
     * @param  resource $serverSocket 服务器Socket
     * @param  object $eventHandler 事件对象
     * @return void
     */
    public function accept($serverSocket, $flag, $eventHandler)
    {
        // 惊群 屏蔽错误 Interrupted system call
        $acceptSocket = @stream_socket_accept($serverSocket, 3, $remoteClient);
        if ($acceptSocket) {
            $tcpIns            = new Connection\Tcp();
            $tcpIns->socket    = $acceptSocket;
            $tcpIns->client    = $remoteClient;
            $tcpIns->event     = $eventHandler;
            $tcpIns->protocol  = $this->protocol;
            $tcpIns->onConnect = $this->onConnect;
            $tcpIns->onReceive = $this->onReceive;
            $tcpIns->onSend    = $this->onSend;
            $tcpIns->onClose   = $this->onClose;
            $tcpIns->onError   = $this->onError;
            $tcpIns->ping      = $this->ping;
            $tcpIns->pong      = $this->pong;
            $tcpIns->accept();

            // 加入 socket 列表
            $this->connections[spl_object_hash($tcpIns)] = $tcpIns;
        }
    }

}
