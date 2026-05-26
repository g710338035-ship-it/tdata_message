<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

use think\facade\Env;

return [
    // 应用地址
    'app_host'         => Env::get('app.host', ''),
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 是否启用事件
    'with_event'       => true,

    // 默认应用
    'default_app'      => 'Admin',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [
		//'manage'	=> 'admin',
		//'kefu'	=> 'kefu',
	],
	'domainurl'=>'https://jianting.telegmlisten.com',
	'mptoken'=>'7513636944:AAGwFF1nCShWovam1HDGwHMFEXJwv-KW58Y',//@bettests_bot
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [
		//'new.me'	=> 'admin',
		//'cms.xhadmin.com'	=> 'cms',
		//'api.xhadmin.com'	=> 'api',
        //'supplier.xhadmin.me'=> 'supp'
	],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],
	
    // 异常页面的模板文件
    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

	'dispatch_success_tmpl' => app()->getRootPath() . 'extend/tpl/dispatch_jump.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => true,
    
    'captcha' => [
        'codeSet'  => '0123456789', // 验证码字符集合
        'fontSize' => 20,            // 字体大小
        'length'   => 4,             // 验证码位数
        'useNoise' => true,          // 是否添加干扰
        'width' => 150,
        'height' => 50,
    ],


];
