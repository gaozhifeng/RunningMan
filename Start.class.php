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

$rm = new RunningMan('tcp://127.0.0.1:2345');
$rm->onConnect = function ($connection) {
    echo $connection->remoteClient . "已连接\n";
};
$rm->onMessage = function ($connection, $data) {
    $connection->write('ok ' . $data);
};

Start::run($rm);
