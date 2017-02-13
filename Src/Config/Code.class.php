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

    const ERR_SYSTEM    = 5001;
    const ERR_MODE      = 5002;
    const ERR_DIRECTIVE = 5003;
    const ERR_FORK      = 5004;
    const ERR_NOT_RUN   = 5005;
    const ERR_RUNNING   = 5006;

    const ERR_SOCKET    = 6001;

    public static $msg = [
        self::ERR_SYSTEM  => 'System error',
        self::ERR_MODE    => 'Please run in CLI mode',
        self::ERR_FORK    => 'Fork process failed',
        self::ERR_NOT_RUN => 'Not run',
        self::ERR_RUNNING => 'Already running',

        self::ERR_SOCKET  => 'Socket Server failed',
    ];

}
