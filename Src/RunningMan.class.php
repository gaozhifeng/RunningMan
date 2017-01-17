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

require __DIR__ . '/Common/bootstrap.inc.php';

class RunningMan {

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
    public $user   = 'www';

    /**
     * 运行用户组
     * @var string
     */
    public $group  = 'www';

    /**
     * 指令集合
     * @var array
     */
    public $dirList = [];

    /**
     * 指令
     * @var string
     */
    public $dir     = '';

    /**
     * 主进程Id
     * @var integer
     */
    public $masterPid = 0;

    /**
     * 进程Id
     * @var array
     */
    public $pidMap    = [];

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
     * 构造器
     */
    public function __construct() {
        $this->dirList = [
            'start',
            'stop',
            'restart',
            'reload',
            'status',
        ];

        $this->pidFile = RM_RUNTIME . '/log/RM.pid';
        $this->logFile = RM_RUNTIME . '/log/RM.log';
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

                default:
                    break;
            }
        } catch (\Exception $e) {
            $this->print(sprintf('[%s] %s', $e->getCode(),$e->getMessage()));
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

            // 业务逻辑
            sleep(300);
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
        ];

        foreach ($signalList as $signal) {
            pcntl_signal($signal, function ($s) {
                switch ($s) {
                    case SIGINT:
                        $this->stopProcess();
                        break;

                    default:
                        break;
                }
            }, false); // 这个 false 很重要，会影响主进程信号执行
                       // 不是 false 主进程接收到信号但无反应
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
        if (in_array($this->dir, ['stop', 'restart', 'reload']) and (!$masterPid or !posix_kill($masterPid, 0))) {
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
            $tips = '';
        } else {
            $mode = 'Terminal';
            $tips = 'Press Ctrl+C to quit.' . "\n\n";
        }
$text = <<<EOF

----------------\33[47;30m RunningMan \33[0m----------------
RM Version [$rmVersion]      PHP Version [$phpVersion]
MODE [$mode]         Master PID [$this->masterPid]
\33[44;37;5m Start success. \33[0m

$tips
EOF;
        $this->print($text);
    }

}
