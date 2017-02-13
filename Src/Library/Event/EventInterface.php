<?php

/**
 * @brief        事件接口
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-2-4 18:52:51
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Event;

interface EventInterface {

    const EV_READ   = 1;

    public function add($socket, $flag, $callback);

    public function loop();

}
