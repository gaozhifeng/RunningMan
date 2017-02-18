<?php

/**
 * @brief        Select 轮询模型
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-2-4 18:52:01
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Event;

class Select implements EventInterface {

    /**
     * 监听事件
     * @var array
     */
    public $event = [];

    /**
     * 读事件
     * @var array
     */
    public $read = [];

    /**
     * 超时时间
     * @var integer
     */
    public $selectTimeOut = 100000000;

    /**
     * 添加事件
     * @param  resource $socket   监听socket
     * @param  int      $flag     事件类型
     * @param  string   $callback 回调
     * @return bool
     */
    public function add($socket, $flag, $callback) {
        switch ($flag) {
            case self::EV_READ:
                $sId                      = (int) $socket;
                $this->event[$sId][$flag] = [$callback, $socket, $this];
                $this->read[$sId]         = $socket;
                break;

            default:
                break;
        }

        return true;
    }

    /**
     * 删除事件
     * @param  resource $socket 监听socket
     * @param  int      $flag   事件类型
     * @return bool
     */
    public function delete($socket, $flag) {
        switch ($flag) {
            case self::EV_READ:
                $sId = (int) $socket;
                unset($this->event[$sId][$flag]);
                unset($this->read[$sId]);
                break;

            default:
                break;
        }

        return true;
    }

    /**
     * 轮询
     * @return void
     */
    public function loop() {
        while (true) {
            pcntl_signal_dispatch();

            $read   = $this->read;
            $write  = null;
            $accept = null;  // 非 null 将导致 stream_select 问题
            $ret = @stream_select($read, $write, $accept, 0, $this->selectTimeOut);

            if (!$ret) {
                continue;
            }

            // 读事件
            foreach ($read as $fd) {
                $sId = (int) $fd;
                if (isset($this->event[$sId][self::EV_READ])) {
                    call_user_func_array($this->event[$sId][self::EV_READ][0],
                        [$this->event[$sId][self::EV_READ][1], $this->event[$sId][self::EV_READ][2]]);
                }
            }
        }
    }

}
