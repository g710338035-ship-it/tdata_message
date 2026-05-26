<?php 
/*
 module:		节点管理控制器
 create_time:	2021-10-21 13:33:12
 author:		
 contact:		
*/

namespace app\admin\validate;
use think\validate;

class Node extends validate {


	protected $rule = [
		'title'=>['require'],
	];

	protected $message = [
		'title.require'=>'节点名称不能为空',
	];

	protected $scene  = [
		'add'=>['title'],
		'update'=>['title'],
	];



}

