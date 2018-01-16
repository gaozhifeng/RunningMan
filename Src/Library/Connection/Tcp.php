<?php

/**
 * @brief        TCP 协议
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-2-4 18:53:48
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Connection;

use RunningMan\Library\Event;

class Tcp extends TcpBase
{
    /**
     * 接收连接
     * @return void
     */
    public function accept()
    {
        ++ self::$statistic['connect'];

        // 更新活跃时间
        $this->activeTime = time();

        stream_set_blocking($this->socket, 0); // 非阻塞

        // 执行回调
        $this->onConnect and call_user_func($this->onConnect, $this);

        // 监听事件
        $this->event->add($this->socket, Event\EventInterface::EV_READ, [$this, 'read']);
    }
}
