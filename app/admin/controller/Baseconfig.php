<?php 
/*
 module:		基本配置控制器
 create_time:	2021-10-10 13:36:05
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Baseconfig as BaseconfigModel;
use think\facade\Db;

class Baseconfig extends Admin {


	/*
 	* @Description  基本配置
 	*/
	public function index(){
		if (!$this->request->isPost()){
			return view('index');
		}else{
			$data = $this->request->post();
			$this->validate($data,\app\admin\validate\Baseconfig::class);
		
			$data['starttime']=$data['starttime'];
            $data['endtime'] = $data['endtime'];
			$info = BaseconfigModel::column('data','name');
			foreach ($data as $key => $value) {
				if(array_key_exists($key,$info)){
					BaseconfigModel::field('data')->where(['name'=>$key])->update(['data'=>$value]);
				}else{
					BaseconfigModel::create(['name'=>$key,'data'=>$value]);
				}
			}
			return json(['status'=>200,'msg'=>'操作成功']);
		}
	}


	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getInfo(){
		$res = BaseconfigModel::column('data','name');		
		$res['domain'] = request()->domain();
		$data['status'] = 200;
		$data['data'] = $res;
		return json($data);
	}




}

