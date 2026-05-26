<?php 
/*
 module:		验证器
 create_time:	2021-04-18 11:55:30
 author:		
 contact:		
*/

namespace app\admin\validate\Cms;
use think\validate;

class Frament extends validate {


	protected $rule = [
		'title'=>['require'],
		'content'=>['require'],
	];

	protected $message = [
		'title.require'=>'碎片标题不能为空',
		'content.require'=>'内容不能为空',
	];

	protected $scene  = [
		'add'=>['title','content'],
		'update'=>['title','content'],
	];



}

