<?php

/**
 * @brief        示例
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-1-15 12:22:18
 * @copyright    © RunningMan
 */

use RunningMan\RunningMan;

require __DIR__ . '/Src/RunningMan.php';

class Start
{

    /**
     * 运行
     * @param  RunningMan $rm RM实例
     * @return void
     */
    public static function run(RunningMan $rm)
    {
        $rm->run();
    }
}

$rmIns = new RunningMan('tcp://0.0.0.0:7266');

$rmIns->onRecv = function ($connection, $data) {
    $msg = sprintf('[%s] Server To %s: %s', date('Y-m-d H:i:s'), $connection->remoteClient, $data);
    /*$time = date('Y-m-d H:i:s');
    $body = "Hello World 北京 ${time}";
    $chunk = dechex(strlen($body));

    $msg = "HTTP/1.1 200 OK\r\nContent-type: text/html\r\nServer: RunningMan 0.0.1\r\nTransfer-Encoding: chunked\r\nConnection: keep-alive\r\n\r\n${chunk}\r\n$body\r\n0\r\n\r\n";*/
    $connection->write($msg);

    // 短连接调用关闭
    // $connection->close();
};

/* Example
$rmIns->onConnect = function ($connection) {
    var_dump('onConnect');
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
};*/

Start::run($rmIns);
