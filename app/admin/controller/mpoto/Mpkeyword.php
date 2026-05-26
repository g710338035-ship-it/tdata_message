<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mpkeyword as MpkeywordModel;
use think\facade\Db;
use think\facade\Cache;
class Mpkeyword extends Admin {


	/*
 	* @Description  数据列表
 	*/
	function index(){
		if (!$this->request->isPost()){
			return view('index');
		}else{
			$limit  = $this->request->post('limit', 20, 'intval');
			$page = $this->request->post('page', 1, 'intval');

			$where = [];
			
			//$where['username'] = $this->request->post('username', '', 'serach_in');
		

			$create_time = $this->request->post('create_time', '', 'serach_in');
			$where['create_time'] = ['between',[strtotime($create_time[0]),strtotime($create_time[1])]];

			
            $withJoin = [
				'monitorphone'=>explode(',','phone'),
				'mpgroup'=>explode(',','group_id,group_name'),
			];
			$res = MpkeywordModel::where(formatWhere($where))->order('id desc')->withJoin($withJoin,'left')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	
		/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'title,mp_id,mp_gid,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
        $mpgroup= Db::name('mpgroup')->where('id', $data['mp_gid'])->find();
        $data['push_chatid'] = $mpgroup['group_id'];
        $data['push_chatname'] = $mpgroup['group_name'];
        $admin = session('admin');
        $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
        $data['admin_id'] =$userid;
        $data['create_time'] = time();
		try{
			$res = MpkeywordModel::create($data);
			 if ($res) {
			     $mp_id = $data['mp_id'];
			     $cacheKey = "mp_keywords_{$mp_id}";
                $keywords = Db::name('mpkeyword')->where('mp_id', $mp_id)->where('status', 1)->select();
                Cache::store('redis')->set($cacheKey, $keywords, 3600);
			 }
			
			
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'data'=>$res->id,'msg'=>'添加成功']);
	}
	
	/*
 	* @Description  修改排序开关
 	*/
	function updateExt(){
		$postField = 'id,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(!$data['id']) throw new ValidateException ('参数错误');
		$res =MpkeywordModel::update($data);
		 
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,title,mp_id,mp_gid,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
		try{
		    $datas=$data;
		    unset($datas['id']);
		    $mpgroup= Db::name('mpgroup')->where('id', $datas['mp_gid'])->find();
            $datas['push_chatid'] = $mpgroup['group_id'];
            $datas['push_chatname'] = $mpgroup['group_name'];
		    $datas['create_time'] = time();
			$res=MpkeywordModel::where('id',$data['id'])->update($datas);
			if ($res) {
			     $mp_id = $data['mp_id'];
			     $cacheKey = "mp_keywords_{$mp_id}";
                $keywords = Db::name('mpkeyword')->where('mp_id', $mp_id)->where('status', 1)->select();
                Cache::store('redis')->set($cacheKey, $keywords, 3600);
			 }
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'msg'=>'修改成功']);
	}


	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getUpdateInfo(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MpkeywordModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$id = $this->request->param('id');
        $mp_id = Db::name('mpkeyword')->where('id', $id)->value('mp_id');
    
        $res = Db::name('mpkeyword')->where('id', $id)->delete();
		if ($res) {
			     $mp_id = $data['mp_id'];
			     $cacheKey = "mp_keywords_{$mp_id}";
                $keywords = Db::name('mpkeyword')->where('mp_id', $mp_id)->where('status', 1)->select();
                Cache::store('redis')->set($cacheKey, $keywords, 3600);
			 }
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MpkeywordModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = MpkeywordModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

}

