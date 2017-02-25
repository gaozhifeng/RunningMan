<?php

/**
 * @brief        操作码
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-1-17 22:46:14
 * @copyright    © RunningMan
 */

namespace RunningMan\Config;

class Code {

    const ERR_SYSTEM    = 1001;
    const ERR_MODE      = 1002;
    const ERR_EXTENSION = 1003;
    const ERR_DIRECTIVE = 1004;
    const ERR_FORK      = 1005;
    const ERR_NOT_RUN   = 1006;
    const ERR_RUNNING   = 1007;

    const ERR_CONNECT_SERVER = 2001;
    CONST ERR_EVENT          = 3001;
    const ERR_PROTOCOL       = 4001;

    public static $msg = [
        self::ERR_SYSTEM    => 'System error',
        self::ERR_MODE      => 'Please run in CLI mode',
        self::ERR_EXTENSION => 'Extension error',
        self::ERR_DIRECTIVE => 'Directive error',
        self::ERR_FORK      => 'Fork process failed',
        self::ERR_NOT_RUN   => 'Not run',
        self::ERR_RUNNING   => 'Already running',

        self::ERR_CONNECT_SERVER => 'Socket Server failed',
        self::ERR_EVENT          => 'Error event',
        self::ERR_PROTOCOL       => 'Error protocol',
    ];

}
