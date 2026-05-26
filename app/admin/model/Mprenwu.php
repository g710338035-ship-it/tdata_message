<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\model;
use think\Model;

class Mprenwu extends Model {


	protected $connection = 'mysql';

 	protected $pk = 'id';

 	protected $name = 'mprenwu';

    	function mpgrouptag(){
		    return $this->hasOne(\app\admin\model\Mpgrouptag::class,'id','mpgt_id');
	    }
      function mpgroup(){
		    return $this->hasOne(\app\admin\model\Mpgroup::class,'id','mp_gid');
	    }

}

