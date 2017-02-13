<?php

/**
 * @brief        TCP 协议
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-2-4 18:53:48
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Connection;

use RunningMan\Config;
use RunningMan\Library\Event;

class Tcp {

    const READ_BUFFER_SIZE = 8192;

    public $serverSocket = null;
    public $acceptSocket = null;
    public $remoteClient = '';

    public $onConnect = null;
    public $onMessage = null;
    public $onSend    = null;
    public $onClose   = null;
    public $onError   = null;

    /**
     * 接收连接
     * @param  object $event 处理事件
     * @return void
     */
    public function accept($event) {
        stream_set_blocking($this->acceptSocket, 0);

        // 执行回调
        $this->onConnect and call_user_func($this->onConnect, $this);

        // 监听事件
        $event->add($this->acceptSocket, Event\EventInterface::EV_READ,  [$this, 'read']);
    }

    /**
     * 读取数据
     * @param  resource $acceptSocket 接收Socket
     * @param  object   $event        事件处理
     * @return void
     */
    public function read($acceptSocket, $event) {
        $content = '';
        while (true) {
            // STREAM_PEEK 会导致 stream_select 不断循环
            $recv = stream_socket_recvfrom($acceptSocket, self::READ_BUFFER_SIZE);
            if ($recv === '' || !$recv || feof($acceptSocket)) {
                break;
            }
            $content .= $recv;
        }

        // 执行回调
        $this->onMessage and call_user_func($this->onMessage, $this, $content);

        return;
    }

    /**
     * 写入数据
     * @param  string $data 数据
     * @return void
     */
    public function write($data) {
        $content = $data;
        while (true) {
            $len = fwrite($this->acceptSocket, $content);
            if ($len == strlen($content)) {
                // 执行回调
                $this->onSend and call_user_func($this->onSend, $this);
                break;
            }
            $content = substr($content, $len);
        }

        return;
    }

    /**
     * 关闭连接
     * @return void
     */
    public function close() {
        fclose($this->serverSocket);
        fclose($this->acceptSocket);
    }

}
