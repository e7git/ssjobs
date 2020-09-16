<?php

namespace Sayhey\Jobs\Common;

/**
 * 配置类
 *
 */
class Config
{

    private static $_config = [];           // 配置内容

    /**
     * 设置配置
     * @param array $config
     */
    public static function init(array $config)
    {
        self::$_config = $config;
    }

    /**
     * 获取配置
     * @param string $section 区域，不填时默认返回全部配置信息
     * @param string $key 配置项键，不填时默认返回对应区域全部配置信息
     * @param mixed $default 默认返回值，当配置项不存在时返回该参数值
     * @return mixed
     */
    public static function get(string $section = '', string $key = '', $default = null)
    {
        if ('' === $section) {
            return self::$_config;
        }

        if ('' === $key) {
            return self::$_config[$section] ?? $default;
        }

        return self::$_config[$section][$key] ?? $default;
    }

}
