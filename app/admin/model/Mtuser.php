<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\model;
use think\Model;

class Mtuser extends Model {


	protected $connection = 'mysql';

 	protected $pk = 'id';

 	protected $name = 'Mtuser';

	 function Adminuser(){
		return $this->hasOne(\app\admin\model\Adminuser::class,'user_id','admin_id');
	}
	function Mtcate(){
		return $this->hasOne(\app\admin\model\Mtcate::class,'class_id','cateid');
	}
	function Mtcustom(){
		return $this->hasOne(\app\admin\model\Mtcustomer::class,'id','customid');
	}



}

