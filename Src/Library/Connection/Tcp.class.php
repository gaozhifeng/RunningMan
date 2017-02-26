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

    /**
     * 读取buffer
     */
    const READ_BUFFER_SIZE = 8192;

    /**
     * 事件句柄
     * @var null
     */
    public $eventHandler = null;

    /**
     * 接收socket
     * @var null
     */
    public $acceptSocket = null;

    /**
     * 远程地址
     * @var string
     */
    public $remoteClient = '';

    /**
     * 读Buffer
     * @var string
     */
    public $recvBuffer = '';

    /**
     * 交换协议
     * @var string
     */
    public $protocol = null;

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
     * 统计
     * @var array
     */
    public static $statistic = [
        'connect' => 0,
        'recv'    => 0,
        'send'    => 0,
        'close'   => 0,
        'error'   => 0,
    ];

    /**
     * 接收连接
     * @return void
     */
    public function accept() {
        ++ self::$statistic['connect'] ;

        stream_set_blocking($this->acceptSocket, 0); // 非阻塞

        // 执行回调
        $this->onConnect and call_user_func($this->onConnect, $this);

        // 监听事件
        $this->eventHandler->add($this->acceptSocket, Event\EventInterface::EV_READ,  [$this, 'read']);
    }

    /**
     * 读取数据
     * @param  resource $acceptSocket 接收Socket
     * @return void
     */
    public function read($acceptSocket) {
        // STREAM_PEEK 会导致 stream_select 不断循环
        $this->recvBuffer .= stream_socket_recvfrom($acceptSocket, self::READ_BUFFER_SIZE);
        $flag = true;
        while ($flag) {
            // 客户端断开会导致读为空
            if ($this->recvBuffer === '' || $this->recvBuffer === false || feof($acceptSocket)) {
                $this->close();
                break;
            }

            $strpos = $this->protocol->unPackPos($this->recvBuffer);
            if (!empty($strpos)) {
                if (strlen($this->recvBuffer) - 1 == $strpos) {
                    $flag = false;
                }
                $recvPack = substr($this->recvBuffer, 0, $strpos);
                $this->recvBuffer = substr($this->recvBuffer, $strpos + 1);

                ++ self::$statistic['recv'];

                // 执行回调
                $this->onRecv and call_user_func($this->onRecv, $this, $recvPack);
            } else {
                $flag = false;
                break;
            }
        }

        return;
    }

    /**
     * 写入数据
     * @param  string $data 数据
     * @return void
     */
    public function write($data) {
        ++ self::$statistic['send'];

        $ret = true;
        do {
            if (strlen($data) <= 0 or !is_resource($this->acceptSocket)) {
                $ret = false;
                break;
            }

            $content = $this->protocol->pack($data);
            while (true) {
                $len = stream_socket_sendto($this->acceptSocket, $content);
                if ($len < 1) {
                    ++ self::$statistic['error'];
                    $this->onError and call_user_func($this->onError, $this);
                    $this->close();
                    break 2;
                }
                if ($len == strlen($content)) {
                    // 执行回调
                    $this->onSend and call_user_func($this->onSend, $this, $data);
                    break 2;
                }
                $content = substr($content, $len);
            }
        } while (0);

        return $ret;
    }

    /**
     * 关闭连接
     * @return void
     */
    public function close() {
        ++ self::$statistic['close'];

        // 关闭连接
        is_resource($this->acceptSocket) and fclose($this->acceptSocket);
        // 删除事件
        $this->eventHandler->delete($this->acceptSocket, Event\EventInterface::EV_READ);
        $this->eventHandler->delete($this->acceptSocket, Event\EventInterface::EV_SIGNAL);
        // 执行回调
        $this->onClose and call_user_func($this->onClose, $this);
    }

}
