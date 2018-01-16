<?php

/**
 * @brief        客户端程序
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2018-01-09 15:34:31
 * @copyright    © RunningMan
 */

use RunningMan\Library\Protocol;
use RunningMan\Library\Connection;

require dirname(__DIR__) . '/Src/Common/bootstrap.inc.php';

/*$errNo  = 0;
$errMsg = '';
$socket = stream_socket_client('tcp://127.0.0.1:7266', $errNo, $errMsg, 30);
if (!$socket) {

} else {
    $msg = 'Hello World' . "\n";
    $len = stream_socket_sendto($socket, $msg);
    var_dump($len);

    $recvMsg = '';
    $flag = true;
    while ($flag) {
        $recvData = stream_socket_recvfrom($socket, 8192);
        if ($recvData === '' or $recvData === false or feof($socket)) {
            break;
        }
        $recvMsg .= $recvData;
        $protocolIns = new Protocol\Text();
        $strPos = $protocolIns->unPackPos($recvMsg);
        if ($strPos === false) {
            continue;
        }

        $recv = substr($recvMsg, 0, $strPos);
        echo $recv;
    }

    socket_close($socket);
}*/

$asyncTcp            = new Connection\AsyncTcp('tcp://127.0.0.1:7266');
$asyncTcp->onReceive = function ($connection, $data) {
    echo $data;
};
$asyncTcp->onClose   = function () {
    var_dump('close');
};
$asyncTcp->connect();
$asyncTcp->loop();