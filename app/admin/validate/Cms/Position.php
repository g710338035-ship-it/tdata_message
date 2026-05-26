<?php 
/*
 module:		验证器
 create_time:	2021-04-18 11:55:33
 author:		
 contact:		
*/

namespace app\admin\validate\Cms;
use think\validate;

class Position extends validate {


	protected $rule = [
		'title'=>['require'],
	];

	protected $message = [
		'title.require'=>'名称不能为空',
	];

	protected $scene  = [
		'add'=>['title'],
		'update'=>['title'],
	];



}

