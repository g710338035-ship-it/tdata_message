<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\model;
use think\Model;

class Sockts extends Model {


	protected $connection = 'mysql';

 	protected $pk = 'id';

 	protected $name = 'sockts';

    function socktscate(){
		return $this->hasOne(\app\admin\model\Socktscate::class,'class_id','skcateid');
	}


}

