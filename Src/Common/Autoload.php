<?php

/**
 * @brief        自动加载
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-1-15 12:22:40
 * @copyright    © RunningMan
 */

namespace RunningMan\Common;

class Autoload
{

    /**
     * 运行
     * @param  boolean $enabled 加载
     * @return void
     */
    public static function run($enabled = true)
    {
        if ($enabled) {
            spl_autoload_register([__CLASS__, 'loadclass']);
        } else {
            spl_autoload_unregister([__CLASS__, 'loadclass']);
        }
    }

    /**
     * 加载类
     * @param  string $class 类名
     * @return void
     */
    public static function loadclass($class)
    {
        $classPath = str_replace(['\\'], '/', $class);
        $classFile = $classPath . '.php';

        $namespaceMap = [
            'RunningMan' => RM_ROOT,
        ];

        list($namespace) = explode('/', $classPath);
        if (array_key_exists($namespace, $namespaceMap)) {
            $classFile = str_replace($namespace, $namespaceMap[$namespace], $classFile);
        }

        if (is_file($classFile) and is_readable($classFile)) {
            require $classFile;
        }
    }

}

Autoload::run();
