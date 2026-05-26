<?php 
/*
 module:		验证器
 create_time:	2021-04-17 22:55:04
 author:		
 contact:		
*/

namespace app\admin\validate\Cms;
use think\validate;

class Catagory extends validate {


	protected $rule = [
		'class_name'=>['require'],
	];

	protected $message = [
		'class_name.require'=>'栏目名称不能为空',
	];

	protected $scene  = [
		'add'=>['class_name'],
		'update'=>['class_name'],
	];



}

