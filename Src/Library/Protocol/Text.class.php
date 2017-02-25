<?php

/**
 * @brief        文本协议
 *
 * @author       Feng <mail.gzf@foxmail.com>
 * @since        2017-2-25 19:31:59
 * @copyright    © RunningMan
 */

namespace RunningMan\Library\Protocol;

class Text {

    /**
     * 封包
     * @param  string $data 包数据
     * @return string
     */
    public function pack($data) {
        return $data . "\n";
    }

    /**
     * 解包位置
     * @param  string $data 包数据
     * @return mixed
     */
    public function unPackPos($data) {
        $ret = false;

        do {
            $pos = stripos($data, "\n");
            if ($pos === false) {
                break;
            }

            $ret = $pos;
        } while (0);

        return $ret;
    }

}
