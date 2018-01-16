<?php

/**
 * @brief        异步 TCP 协议
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2018-01-16 16:57:53
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Connection;

use RunningMan\Library\Event;
use RunningMan\Library\Protocol;
use RunningMan\Library\Util;

class AsyncTcp extends TcpBase
{
    /**
     * 连接地址
     * @var string
     */
    public $domain = '';

    /**
     * 上下文
     * @var array
     */
    public $context = [];

    /**
     * 心跳包频率 单位s
     * @var int
     */
    public $heartbeatInterval = 3;

    /**
     * 构造器
     * @param $domain
     * @param array $context
     */
    public function __construct($domain, $context = [])
    {
        $this->domain   = $domain;
        $this->context  = $context;
        $this->event    = new Event\Libevent();
        $this->protocol = new Protocol\Text;

        // 定时器初始化
        Util\Timer::init(true);

        pcntl_signal(SIGALRM, SIG_IGN, false);
    }

    /**
     * 连接
     */
    public function connect()
    {
        $errNo        = 0;
        $errMsg       = '';
        $context      = stream_context_create($this->context);
        $this->socket = stream_socket_client($this->domain, $errNo, $errMsg, 3, STREAM_CLIENT_ASYNC_CONNECT, $context);
        stream_set_blocking($this->socket, 0);

        $this->event->add($this->socket, Event\EventInterface::EV_READ, [$this, 'read']);
        $this->event->add(SIGALRM, Event\EventInterface::EV_SIGNAL, [$this, 'signalProcessor']);

        // 心跳检测
        Util\Timer::add('heartbeat', [function () {
            $this->write('ping');
        }, null], true, $this->heartbeatInterval);
    }

    /**
     * 信号处理器
     * @param int $signal 信号
     */
    public function signalProcessor($signal)
    {
        switch ($signal) {
            case SIGALRM:
                Util\Timer::runTask();
                break;
            default:
                break;
        }
    }

    /**
     * 事件循环
     */
    public function loop()
    {
        $this->event->loop();
    }
}