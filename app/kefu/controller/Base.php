<?php 
namespace app\kefu\controller;
use think\exception\ValidateException;
use app\admin\model\Files as FileModel;
use app\admin\model\Adminuser as AdminuserModel;
use think\facade\Db;
use think\facade\Log;
class Base extends Baseinfo {

	
	
	
	/*
 	* @Description 图片管理列表
 	*/
	function fileList(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = [];
       // if(session('admin.user_id')!=1)		$where['admin_id']=session('admin.user_id');
		$field = 'id,filepath,hash,create_time';

		$res = FileModel::where(formatWhere($where))->field($field)->order('id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

		$data['status'] = 200;
		$data['data'] = $res;
		return json($data);
	}




}

