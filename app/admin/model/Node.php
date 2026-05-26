<?php 
/*
 module:		节点管理控制器
 create_time:	2021-10-21 13:33:12
 author:		
 contact:		
*/

namespace app\admin\model;
use think\Model;

class Node extends Model {


	protected $connection = 'mysql';

 	protected $pk = 'node_id';

 	protected $name = 'node';




}

