<?php

/**
 * @brief        定时器
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-3-27 18:39:12
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Util;

class Timer
{
    /**
     * 任务列表
     * @var array
     */
    private static $task = [];

    /**
     * 初始化
     * @return void
     */
    public static function init($event = false)
    {
        if (!$event) {
            pcntl_signal(SIGALRM, function () {
                // 每次对 pcntl_alarm() 的调用都会取消之前设置的alarm信号，重新设置
                pcntl_alarm(1);
                self::runTask();
            }, false);
        }
    }

    /**
     * 添加任务
     * @param string  $taskName    任务名
     * @param array   $callback    回调方法
     * @param bool    $persistence 持久化
     * @param integer $interval    间隔s
     */
    public static function add($taskName, $callback, $persistence, $interval = 0)
    {
        if (empty(self::$task)) {
            pcntl_alarm(1);
        }

        self::$task[$taskName] = [
            'callback'    => (array) $callback,
            'persistence' => (bool)  $persistence,
            'interval'    => (int)   $interval,
            'runtime'     => 0,
        ];
    }

    /**
     * 删除任务
     * @param  string $taskName 任务名
     * @return void
     */
    public static function delete($taskName)
    {
        unset(self::$task[$taskName]);
    }

    /**
     * 执行任务
     * @return void
     */
    public static function runTask()
    {
        do {
            if (empty(self::$task)) {
                pcntl_alarm(0);
                break;
            } else {
                pcntl_alarm(1);
            }

            foreach (self::$task as $taskName => &$task) {
                if ($task['interval'] <= 0 || !$task['persistence']) {
                    self::delete($taskName);
                } else {
                    if (time() - $task['runtime'] < $task['interval']) {
                        continue;
                    }
                }

                list($callFunc, $callArgs) = $task['callback'];
                call_user_func($callFunc, $callArgs);

                $task['runtime'] = time();
            }
        } while (0);
    }

}
