<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

return [
    'default'     => 'redis', // 选择适合的驱动
    'connections' => [
        'sync' => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            'table'      => 'jobs',
            'connection' => null,
        ],
        'redis' => [
            'type'       => 'redis',
            'queue'      => 'default',
            'host'       => '127.0.0.1',
            'port'       => 6379,
            'password'   => '6kE3zkytdzaKeGz7',
            'select'     => 10,
            'timeout'    => 0,
            'persistent' => false,
        ],
        'swoole' => [
            'driver' => 'swoole',
            'queue' => 'default',
        ],
    ],
    'failed' => [
        'type'  => 'database',
        'table' => 'failed_jobs',
    ],
];
