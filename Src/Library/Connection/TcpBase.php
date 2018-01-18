<?php

/**
 * @brief        TCP 基类
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2018-01-15 18:46:41
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Connection;

use RunningMan\Library\Event;

class TcpBase implements ConnectionInterface
{
    /**
     * 读取buffer
     */
    const READ_BUFFER_SIZE = 8192;

    /**
     * 事件句柄
     * @var object|null
     */
    public $event = null;

    /**
     * 接收socket
     * @var resource|null
     */
    public $socket = null;

    /**
     * 客户端
     * @var resource|null
     */
    public $client = null;

    /**
     * 读Buffer
     * @var string
     */
    public $recvBuffer = '';

    /**
     * 序列化协议
     * @var object|null
     */
    public $protocol = null;

    /**
     * 连接回调
     * @var object|null
     */
    public $onConnect = null;

    /**
     * 消息回调
     * @var object|null
     */
    public $onReceive = null;

    /**
     * 发送回调
     * @var object|null
     */
    public $onSend = null;

    /**
     * 关闭回调
     * @var object|null
     */
    public $onClose = null;

    /**
     * 错误回调
     * @var object|null
     */
    public $onError = null;

    /**
     * 最后活跃时间
     * @var int
     */
    public $activeTime = 0;

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
     * 统计
     * @var array
     */
    public static $statistic = [
        'connect'   => 0,
        'receive'   => 0,
        'heartbeat' => 0,
        'send'      => 0,
        'close'     => 0,
        'error'     => 0,
    ];

    /**
     * 读取数据
     * @return void
     */
    public function read()
    {
        // 如果有未读内容，read 会不断被调用，直到读取完毕

        // 更新活跃时间
        $this->activeTime = time();

        // STREAM_PEEK 会导致 stream_select 不断循环
        $this->recvBuffer .= stream_socket_recvfrom($this->socket, self::READ_BUFFER_SIZE);
        $flag             = true;
        while ($flag) {
            // 客户端断开会导致读为空
            if ($this->recvBuffer === '' or $this->recvBuffer === false or !is_resource($this->socket) or feof($this->socket)) {
                $this->close();
                break;
            }

            $strpos = $this->protocol->unPackPos($this->recvBuffer);
            if (!empty($strpos)) {
                if (strlen($this->recvBuffer) - 1 == $strpos) {
                    $flag = false;
                }
                $recvPack         = substr($this->recvBuffer, 0, $strpos);
                $this->recvBuffer = substr($this->recvBuffer, $strpos + 1);

                // 执行回调
                if ($recvPack === $this->ping) {
                    ++ self::$statistic['heartbeat'];
                    $this->pong and $this->write($this->pong);
                } else {
                    ++ self::$statistic['receive'];
                    $this->onReceive and call_user_func($this->onReceive, $this, $recvPack);
                }
            } else {
                break;
            }
        }

        return;
    }

    /**
     * 写入数据
     * @param  string $data 数据
     * @return bool
     */
    public function write($data)
    {
        if ($data !== $this->pong) {
            ++ self::$statistic['send'];
        }

        $ret = true;
        do {
            if (strlen($data) <= 0 or !is_resource($this->socket)) {
                $ret = false;
                break;
            }

            $content = $this->protocol->pack($data);
            while (true) {
                $len = stream_socket_sendto($this->socket, $content);
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
    public function close()
    {
        if (is_resource($this->socket)) {
            -- self::$statistic['connect'];
            ++ self::$statistic['close'];

            fclose($this->socket);

            // 删除事件
            $this->event->delete($this->socket, Event\EventInterface::EV_READ);
            $this->event->delete($this->socket, Event\EventInterface::EV_SIGNAL);
            // 执行回调
            $this->onClose and call_user_func($this->onClose, $this);
        }
    }
}