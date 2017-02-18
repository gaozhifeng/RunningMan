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

$rmIns = new RunningMan('tcp://0.0.0.0:2345');
$rmIns->onConnect = function ($connection) {
    var_dump('onConnect');
};

$rmIns->onRecv = function ($connection, $data) {
    $connection->write('ok ' . $data);

    // 短连接调用关闭
    // $connection->close();
};

$rmIns->onSend = function ($connection, $data) {
    var_dump('onSend');
};
$rmIns->onClose = function () {
    var_dump('onClose');
};
$rmIns->onError = function () {
    $errMsg = socket_strerror(socket_last_error());
    var_dump($errMsg);
    var_dump('onError');
};

Start::run($rmIns);
