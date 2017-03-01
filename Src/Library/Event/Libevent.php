<?php

/**
 * @brief        Libevent 事件驱动模型
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-2-20 18:07:11
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Event;

class Libevent implements EventInterface
{
    public $event     = null;
    public $eventBase = null;

    /**
     * 构造器
     */
    public function __construct()
    {
        $this->eventBase = event_base_new();
    }

    /**
     * 添加事件
     * @param  resource $socket   监听socket
     * @param  int      $flag     事件类型
     * @param  string   $callback 回调
     * @return bool
     */
    public function add($fd, $flag, $callback)
    {
        switch ($flag) {
            case self::EV_SIGNAL:
                $sId = (int) $fd;

                $event = event_new();
                event_set($event, $fd, EV_SIGNAL | EV_PERSIST, $callback, $this);
                event_base_set($event, $this->eventBase);
                event_add($event);

                $this->event[$sId][$flag] = $event;
                break;

            case self::EV_READ:
                $sId = (int) $fd;

                $event = event_new();
                // EV_PERSIST 表明是一个永久事件
                // event_set 回调第一个参数是fd 第二个参数是 事件类型，之后才是args
                event_set($event, $fd, EV_READ | EV_PERSIST, $callback, $this);
                event_base_set($event, $this->eventBase);
                event_add($event);

                $this->event[$sId][$flag] = $event;    // 有资料说这里要赋值一个全局变量，稍后详查，没有赋值时确实会造成进程退出
                break;

            default:
                break;
        }
    }

    /**
     * 删除事件
     * @param  resource $fd   监听socket
     * @param  int      $flag 事件类型
     * @return bool
     */
    public function delete($fd, $flag)
    {
        $sId = (int) $fd;
        unset($this->event[$sId][$flag]);
        event_del($this->event[$sId][$flag]);
    }

    /**
     * 轮询
     * @return void
     */
    public function loop()
    {
        event_base_loop($this->eventBase);
    }

}
