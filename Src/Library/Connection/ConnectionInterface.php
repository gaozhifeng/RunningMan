<?php

namespace RunningMan\Library\Connection;

interface ConnectionInterface
{
    public $statistics = [
        'connection',
        'request',
        'response',
        'exception',
        'error',
    ];

}
