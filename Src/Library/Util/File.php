<?php

/**
 * @brief        工具类
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-1-17 22:46:38
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Util;

class File
{
    /**
     * 写文件
     * @param  string $file 文件
     * @param  string $data 内容
     * @param  int    $flag 标志
     * @return int
     */
    public static function writeFile($file, $data, $flag = FILE_APPEND)
    {
        self::makeDirs(dirname($file));
        return file_put_contents($file, $data, $flag);
    }

    /**
     * 递归创建文件夹
     * @param  string  $name 名称
     * @param  integer $mode 权限
     * @return bool
     */
    public static function makeDirs( $name, $mode = 0777 )
    {
        return is_dir($name) or (self::makeDirs(dirname($name), $mode) and mkdir($name, $mode));
    }

}
