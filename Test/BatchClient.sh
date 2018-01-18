#!/bin/sh

path=`pwd`
file=$path/Client.php

# `ps aux | grep Client | grep -v grep | awk '{print $2}' | xargs kill -9`

for i in {1..100}
do
    `php $file >> /tmp/RunningManClient.log 2>&1 &`
done