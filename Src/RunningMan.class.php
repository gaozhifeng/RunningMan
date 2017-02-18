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
    public $dir = '';

    /**
     * 运行状态
     * @var int
     */
    public $status = 0;

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
     * @var stream
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
    public $onRecv = null;

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
     * 全局统计
     * @var array
     */
    public static $statistic = [];

    /**
     * 构造器
     */
    public function __construct($domain, $context = []) {
        $this->pidFile = RM_RUNTIME . '/Log/RM.pid';
        $this->logFile = RM_RUNTIME . '/Log/RM.log';

        self::$statistic['start_time'] = time();

        $this->localDomain = $domain;

        $streamContext['socket']['backlog'] = self::BACKLOG;
        $streamContext = array_merge($streamContext, $context); // 注意顺序，后面覆盖前面
        $this->streamContext = stream_context_create($streamContext);

        $this->onConnect = function () {};
        $this->onRecv    = function () {};
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
                #case 'restart':
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

                case 'reload':
                    $this->reloadProcess();
                    break;

                case 'status';
                    $this->statusProcess();
                    break;

                default:
                    break;
            }
        } catch (\Exception $e) {
            $this->print(sprintf('Exception [%s] %s %s:%s', $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine()));
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
        $this->status = self::STATUS_START;
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
            $this->signalSubReg();

            // 事件处理
            $this->eventLoop();
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
            SIGINT,
            SIGUSR1,
            SIGUSR2,
        ];

        foreach ($signalList as $signal) {
            pcntl_signal($signal, function ($s) {
                switch ($s) {
                    case SIGINT:
                        // 应该是 tcp 关闭
                        #$this->connect and $this->connect->close($this->acceptSocket);
                        exit;

                    case SIGUSR1:
                        echo 'SIGUSR1';
                        break;

                    case SIGUSR2:
                        $this->statusSubProcess();
                        break;

                    default:
                        break;
                }
            });
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

                // 非正常退出
                if ($this->status != self::STATUS_STOP) {
                    $this->forkProcess();
                } else {
                    if (count($this->pidMap[$this->masterPid]) === 0) {
                        fclose($this->serverSocket);
                        unlink($this->pidFile);
                        $this->print("\33[42;37;5m Stop success. \33[0m");
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
     * @throws Exception 异常
     */
    public function stopProcess() {
        $this->status = self::STATUS_STOP;
        // ctrl c 或 signal
        if ($this->masterPid == posix_getpid()) {
            $this->print('Stopping...');
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
        $this->status = self::STATUS_RELOAD;
    }

    /**
     * 进程状态
     * @return void
     */
    public function statusProcess() {
        // signal
        if ($this->masterPid == posix_getpid()) {
            $rmVersion  = Config\Config::VERSION;
            $phpVersion = PHP_VERSION;
            if ($this->daemon) {
                $mode = 'Daemon';
            } else {
                $mode = 'Terminal';
            }

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

            $pidName     = str_pad('PID', 10, ' ');
            $userName    = str_pad('Group-User', 14, ' ');
            $listenName  = str_pad('Listen', 24, ' ');
            $memoryName  = str_pad('Memory', 10, ' ');
            $connectName = str_pad('Conn', 8, ' ');
            $recvName    = str_pad('Recv', 8, ' ');
            $sendName    = str_pad('Send', 8, ' ');
            $closeName   = str_pad('Close', 8, ' ');
            $errorName   = 'Error';
echo <<<EOF
___________________________________________\33[47;30m RunningMan \33[0m__________________________________________
Master Process：
 RM Version: ${rmVersion}    PHP Version: ${phpVersion}    Mode: ${mode}
 PID: ${pid}    Loadavg: ${loadavg}    RunTime: ${startTime} (${runTime})    Memory: ${memory}M

Worker Process：
\33[47;30m ${pidName}${userName}${listenName}${memoryName}${connectName}${recvName}${sendName}${closeName}${errorName} \33[0m

EOF;
            foreach ($this->pidMap[$this->masterPid] as $pid) {
                posix_kill($pid, SIGUSR2);
            }
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
    public function statusSubProcess() {
        $connect = str_pad(Connection\Tcp::$statistic['connect'], 8, ' ');
        $recv    = str_pad(Connection\Tcp::$statistic['recv'], 8, ' ');
        $send    = str_pad(Connection\Tcp::$statistic['send'], 8, ' ');
        $close   = str_pad(Connection\Tcp::$statistic['close'], 8, ' ');
        $error   = Connection\Tcp::$statistic['error'];

        $pid    = str_pad(posix_getpid(), 10, ' ');
        $user   = str_pad(sprintf('%s %s', $this->group, $this->user), 14, ' ');
        $local  = str_pad($this->localDomain, 24, ' ');
        $memory = str_pad(round(memory_get_usage(true) / 1024 / 1024, 2) . 'M', 10, ' ');

echo <<<EOF
 ${pid}${user}${local}${memory}${connect}${recv}${send}${close}${error}

EOF;
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
        $dir1 = isset($argv[1]) ? $argv[1] : null;
        $dir2 = isset($argv[2]) ? $argv[2] : null;

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
        // 设置权限
        chmod($this->logFile, 0777);
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
        $l2 = strlen($this->localDomain) - strlen('Listen') + 2;

        $f1 = 'User' . str_pad(' ', $l1);
        $f2 = 'Listen' . str_pad(' ', $l2);
        $f3 = 'Process';

$note = <<<EOF

----------------\33[47;30m RunningMan \33[0m----------------
RM Version [$rmVersion]      PHP Version [$phpVersion]
MODE [$mode]         Master PID [$this->masterPid]

\33[47;30m ${f1} ${f2} ${f3} \33[0m
$this->group $this->user   $this->localDomain   $this->worker

\33[44;37;5m Start success. \33[0m  $tips
EOF;
        $this->print($note);
    }

    /**
     * 监听
     * @return void
     */
    public function listen() {
        list($transport) = explode('://', $this->localDomain, 2);
        $errno  = 0;
        $errmsg = '';
        $flag   = $transport === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

        $this->serverSocket = stream_socket_server($this->localDomain, $errno, $errmsg, $flag, $this->streamContext);
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
        $eventIns = new Event\Select();
        $eventIns->add($this->serverSocket, Event\EventInterface::EV_READ, [$this, 'accept']);
        $eventIns->loop();
    }

    /**
     * 接收连接
     * @param  resource $serverSocket 服务器Socket
     * @param  object   $eventHandler 事件对象
     * @return void
     */
    public function accept($serverSocket, $eventHandler) {
        // 多个进程会造成惊群，没有accept 成功的进程会报错误
        $acceptSocket = @stream_socket_accept($serverSocket, 0, $remoteClient);
        if ($acceptSocket) {
            $tcpIns = new Connection\Tcp();
            $tcpIns->acceptSocket = $acceptSocket;
            $tcpIns->remoteClient = $remoteClient;
            $tcpIns->eventHandler = $eventHandler;
            $tcpIns->onConnect    = $this->onConnect;
            $tcpIns->onRecv       = $this->onRecv;
            $tcpIns->onSend       = $this->onSend;
            $tcpIns->onClose      = $this->onClose;
            $tcpIns->onError      = $this->onError;
            $tcpIns->accept();
        }
    }

}
