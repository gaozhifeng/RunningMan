<?php

/**
 * @brief        主程序
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-1-16 23:01:17
 * @copyright    © RunningMan
 */

namespace RunningMan;

use RunningMan\Common;
use RunningMan\Config;
use RunningMan\Library\Event;
use RunningMan\Library\Connection;

require __DIR__ . '/Common/bootstrap.inc.php';

class RunningMan {

    /**
     * backlog
     */
    const BACKLOG = 65535;

    /**
     * 守护程序
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
     * 运行状态
     * @var null
     */
    public $status = null;

    /**
     * 指令集合
     * @var array
     */
    public $dirList = [];

    /**
     * 指令
     * @var string
     */
    public $dir = '';

    /**
     * 主进程Id
     * @var integer
     */
    public $masterPid = 0;

    /**
     * 进程Id
     * @var array
     */
    public $pidMap = [];

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
     * Socket域名
     * @var string
     */
    public $localSocket = '';

    /**
     * Socket上下文
     * @var array
     */
    public $socketContext = null;

    /**
     * ServerSocket
     * @var stream
     */
    public $serverSocket = null;

    /**
     * 消息回调
     * @var string
     */
    public $onConnect = null;
    public $onMessage = null;
    public $onSend    = null;
    public $onClose   = null;
    public $onError   = null;

    /**
     * 全局统计
     * @var array
     */
    public $statistic = [];

    /**
     * 构造器
     */
    public function __construct($domain, $context = []) {
        $this->dirList = [
            'start',
            'stop',
            'restart',
            'reload',
            'status',
        ];

        $this->pidFile = RM_RUNTIME . '/Log/RM.pid';
        $this->logFile = RM_RUNTIME . '/Log/RM.log';

        $this->statistic['start_time'] = time();

        $this->localSocket = $domain;

        $socketContext['socket']['backlog'] = self::BACKLOG;
        $context = array_merge($context, $socketContext);
        $this->socketContext = stream_context_create($context);

        $this->onConnect = function () {};
        $this->onMessage = function () {};
        $this->onSend    = function () {};
        $this->onClose   = function () {};
        $this->onError   = function () {};
    }

    /**
     * 运行
     * @return void
     * @throws Exception 异常
     */
    public function run() {
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
                    // 启动进程
                    $this->startProcess();
                    // 启动画面
                    $this->bootScreen();
                    // 信号注册
                    $this->signalReg();
                    // 信号监听
                    $this->signalWatch();
                    break;

                case 'stop':
                    $this->stopProcess();
                    break;

                case 'status';
                    $this->statusProcess();
                    break;

                default:
                    break;
            }
        } catch (\Exception $e) {
            $this->print(sprintf('Ecception [%s] %s %s:%s', $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine()));
        } catch (\Error $e) {
            $this->print(sprintf('Error [%s] %s %s:%s', $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine()));
        } finally {

        }
        exit;
    }

    /**
     * 启动进程
     * @return void
     * @throws Exception 异常
     */
    public function startProcess() {
        cli_set_process_title('RunningMan: master process (' . __FILE__ . ')');
        if ($this->daemon) {
            umask(0);
            $pid = pcntl_fork();
            if ($pid == -1) {
                throw new \Exception(Config\Code::$msg[Config\Code::ERR_FORK], Config\Code::ERR_FORK);
            } else if ($pid > 0) {
                exit;
            }
        }

        $this->setMasterPid();

        $this->listen();

        // Fork 子进程
        for ($i = 0; $i < $this->worker; $i ++) {
            $this->forkProcess();
        }
    }

    /**
     * 设置主进程Id
     * @return void
     * @throws Exception 异常
     */
    public function setMasterPid() {
        $this->masterPid = posix_getpid();
        Common\Util::writeFile($this->pidFile, $this->masterPid, LOCK_EX);
    }

    /**
     * Fork进程
     * @return void
     * @throws Exception 异常
     */
    public function forkProcess() {
        $pid = pcntl_fork();
        if ($pid == -1) {
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

            // 注册信号
            #$this->signalSubReg();

            // 事件处理
            $this->eventLoop();
            exit;
        }
    }

    /**
     * 信号注册
     * @return void
     * @throws Exception 异常
     */
    public function signalReg() {
        $signalList = [
            SIGINT,
            SIGUSR1,
            SIGUSR2,
        ];

        foreach ($signalList as $signal) {
            pcntl_signal($signal, function ($s) {
                switch ($s) {
                    case SIGINT:
                        $this->stopProcess();
                        break;

                    case SIGUSR1:
                        $this->reloadProcess();
                        break;

                    case SIGUSR2:
                        $this->statusProcess();
                        break;

                    default:
                        break;
                }
            }, false); // 这个 false 很重要，会影响主进程信号执行
                       // 不是 false 主进程接收到信号但无反应
        }
    }

    /**
     * 子进程信号注册
     * @return void
     */
    public function signalSubReg() {
        $signalList = [
            SIGUSR2,
        ];

        foreach ($signalList as $signal) {
            pcntl_signal($signal, function ($s) {
                switch ($s) {
                    case SIGUSR2:
                        var_dump('sss' . posix_getpid());
                        #$this->statusProcess();
                        break;

                    default:
                        break;
                }
            }, false);
        }
    }

    /**
     * 信号看守
     * @return void
     * @throws Exception 异常
     */
    public function signalWatch() {
        while (true) {
            pcntl_signal_dispatch();
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            pcntl_signal_dispatch(); // pcntl_signal(, , false);   没有这个代码，发送 sigint 信号的时候，只有主进程退出
            if ($pid > 0) {
                $this->print("Worker pid [$pid] exit with status [$status]");
                unset($this->pidMap[$this->masterPid][array_search($pid, $this->pidMap[$this->masterPid])]);
                if (count($this->pidMap[$this->masterPid]) === 0) {
                    unlink($this->pidFile);
                    $this->print("\33[42;37;5m Stop success. \33[0m");
                    exit;
                }
            } else {
                // 主进程 -1
            }
        }
    }

    /**
     * 停止进程
     * @return void
     * @throws Exception 异常
     */
    public function stopProcess() {
        if ($this->masterPid == posix_getpid()) {
            $this->print('...');
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
     * 重载进程
     * @return void
     */
    public function reloadProcess() {

    }

    /**
     * 进程状态
     * @return void
     */
    public function statusProcess() {
        if ($this->masterPid == posix_getpid()) {
            echo 1;
            /*foreach ($this->pidMap[$this->masterPid] as $pid) {
                posix_kill($pid, SIGUSR2);
            }*/
        } else {
            echo '2';
        }
/*        // 主进程
        if ($this->masterPid == posix_getpid()) {
            $loadavg = sys_getloadavg();
            $workerTotal = count($this->pidMap[$this->masterPid]);
            $startTime = date('Y-m-d H:i:s', $this->startTime);

            $runTime   = time() - $this->startTime;
            $runDay    = (int) ($runTime / 86400);
            $runHour   = (int) ($runTime % 86400 / 3600);
            $runMinute = (int) ($runTime % 86400 / 3600 / 60);
$note = <<<EOF

----------------\33[47;30m RunningMan \33[0m----------------
RM Version [$rmVersion]      PHP Version [$phpVersion]
Mode [$mode]         Master PID [$this->masterPid]
Start [$startTime] $runDayd$runHourh$runMinutem
Master Process [1] Worker Process [$workerTotal]

EOF;
        } else {

        }
        /// 子进程
        $this->note;*/
    }

    /**
     * 检查运行模式
     * @return void
     * @throws Exception 异常
     */
    public function checkSapi() {
        if (PHP_SAPI != 'cli') {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_MODE], Config\Code::ERR_MODE);
        }
    }

    /**
     * 解析指令
     * @return void
     * @throws Exception 异常
     */
    public function parseDir() {
        global $argv;
        $dir1 = $argv[1];
        $dir2 = $argv[2];

        if (!in_array($dir1, $this->dirList)) {
            $exceMsg = sprintf('Usage: php %s {%s}', $argv[0], implode('|', $this->dirList));
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
     * @throws Exception 异常
     */
    public function checkPre() {
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
     * 打印
     * @param  string $str 字符串
     * @return void
     */
    public function print($str) {
        $logStr = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $str);
        Common\Util::writeFile($this->logFile, $logStr);
        echo $str . "\n";
    }

    /**
     * 启动屏幕
     * @return void
     */
    public function bootScreen() {
        $rmVersion  = Config\Config::VERSION;
        $phpVersion = PHP_VERSION;

        if ($this->daemon) {
            $mode = 'Daemon';
            $tips = 'Press stop directive to quit.';
        } else {
            $mode = 'Terminal';
            $tips = 'Press Ctrl+C to quit.';
        }

        $l1 = strlen(sprintf('%s %s', $this->group, $this->user)) - strlen('User') + 1;
        $l2 = strlen($this->localSocket) - strlen('Listen') + 2;

        $f1 = 'User' . str_pad(' ', $l1);
        $f2 = 'Listen' . str_pad(' ', $l2);
        $f3 = 'Process';

$note = <<<EOF

----------------\33[47;30m RunningMan \33[0m----------------
RM Version [$rmVersion]      PHP Version [$phpVersion]
MODE [$mode]         Master PID [$this->masterPid]

\33[47;30m ${f1} ${f2} ${f3} \33[0m
$this->group $this->user   $this->localSocket   $this->worker

\33[44;37;5m Start success. \33[0m  $tips
EOF;
        $this->print($note);
    }

    /**
     * 监听
     * @return void
     */
    public function listen() {
        list($transport) = explode('://', $this->localSocket, 2);
        $errno  = 0;
        $errmsg = '';
        $flag   = $transport === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

        $this->serverSocket = stream_socket_server($this->localSocket, $errno, $errmsg, $flag, $this->socketContext);
        if (!$this->serverSocket) {
            throw new \Exception(Config\Code::$msg[Config\Code::ERR_SOCKET], Config\Code::ERR_SOCKET);
        }

        $serverSocket = socket_import_stream($this->serverSocket);
        socket_set_option($serverSocket, SOL_SOCKET, SO_KEEPALIVE, 1);
        socket_set_option($serverSocket, SOL_TCP, TCP_NODELAY, 1);

        stream_set_blocking($this->serverSocket, 0);
    }

    /**
     * 事件轮询
     * @return void
     */
    public function eventLoop() {
        $this->event = new Event\Select();
        $this->event->add($this->serverSocket, Event\EventInterface::EV_READ, [$this, 'accept']);
        $this->event->loop();
    }

    /**
     * 接收连接
     * @param  resource $serverSocket 服务器Socket
     * @param  object   $event        事件对象
     * @return void
     */
    public function accept($serverSocket, $event) {
        // stream_socket_get_name
        $acceptSocket = stream_socket_accept($serverSocket, 0, $remoteClient);
        if (!$acceptSocket) {
            throw new \Exception('Error Processing Request');
        }

        $tcpIns = new Connection\Tcp();
        $tcpIns->serverSocket = $serverSocket;
        $tcpIns->acceptSocket = $acceptSocket;
        $tcpIns->remoteClient = $remoteClient;
        $tcpIns->onConnect    = $this->onConnect;
        $tcpIns->onMessage    = $this->onMessage;
        $tcpIns->onSend       = $this->onSend;
        $tcpIns->onClose      = $this->onClose;
        $tcpIns->onError      = $this->onError;
        $tcpIns->accept($event);
    }

}
