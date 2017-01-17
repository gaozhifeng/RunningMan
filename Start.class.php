<?php

/**
 * @brief        示例
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-1-15 12:22:18
 * @copyright    © RunningMan
 */

use RunningMan\RunningMan;

require __DIR__ . '/Src/RunningMan.class.php';

class Start {

    /**
     * 运行
     * @param  RunningMan $rm RM实例
     * @return void
     */
    public static function run(RunningMan $rm) {
        $rm->run();
    }
}

Start::run(new RunningMan());
