<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\model;
use think\Model;

class Telemessage extends Model {


	protected $connection = 'mysql';

 	protected $pk = 'id';

 	protected $name = 'telemessage';

function telegrambot(){
		return $this->hasOne(\app\admin\model\Telegrambot::class,'bot_id','bot_id');
	}


}

