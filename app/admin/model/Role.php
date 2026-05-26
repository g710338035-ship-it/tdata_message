<?php 
/*
 module:		角色管理控制器
 create_time:	2021-10-13 23:07:27
 author:		
 contact:		
*/

namespace app\admin\model;
use think\Model;

class Role extends Model {


	protected $connection = 'mysql';

 	protected $pk = 'role_id';

 	protected $name = 'role';




}

