<?php

namespace RunningMan\Library\Connection;

interface ConnectionInterface
{
    public function read();

    public function write($data);

    public function close();
}
