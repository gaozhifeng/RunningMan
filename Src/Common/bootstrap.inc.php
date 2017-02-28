<?php

/**
 * @brief        引导文件
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-1-16 23:01:40
 * @copyright    © RunningMan
 */

// 定义目录
define('RM_COMMON',  __DIR__);
define('RM_ROOT',    dirname(RM_COMMON));
define('RM_RUNTIME', RM_ROOT . '/Runtime');


// 自动加载
require RM_COMMON . '/Autoload.php';


// 命名空间
use RunningMan\Config;


// 错误显示
if (Config\Config::DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
