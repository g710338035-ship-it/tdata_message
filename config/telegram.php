<?php
return [
    'bot_token' => '8080455546:AAHH_c0gRfhGGLqNzAPfQPto7f4rWSYv2R0',  // 替换为你的机器人 Token
    'api_id' => '2040',
    'api_hash' => 'b18441a1ff607e10a989891a5462e627',
    'cdn_domain' => '/www/wwwroot/tdata.tgbota.top/public',
    'python_service_url'=>'http://127.0.0.1',
 
    // 节点健康检查配置
    'node_check' => [
        'timeout' => 3,           // 健康检查超时时间（秒）
        'heartbeat_expire' => 30, // 心跳过期时间（秒）
    ],
    // 任务配置
    'batch_size' => 150,          // 每批处理账号数
    'async_concurrency' => 1,    // 单节点并发数
    'session_expire' => 86400 * 7, // 登录状态有效期（秒）
    'retry_max' => 3,            // 失败重试次数
];
