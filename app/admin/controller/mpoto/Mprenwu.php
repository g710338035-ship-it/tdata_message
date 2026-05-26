<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mprenwu as MprenwuModel;
use think\facade\Db;
use think\facade\Cache;
class Mprenwu extends Admin {


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
			$admin = session('admin');
            $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
          
            if($userid!=1){
                $where['monitorphone.admin_id'] =$userid;
            }
			$where['mprenwu.title'] = $this->request->post('username', '', 'serach_in');
		
			$where['mprenwu.status'] = $this->request->post('status', '', 'serach_in');
            $withJoin = [
				'mpgrouptag'=>explode(',','name'),
				'mpgroup'=>explode(',','group_id,group_name'),
			];
			$res = MprenwuModel::where(formatWhere($where))->order('id desc')->withJoin($withJoin,'left')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	
		/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'title,mpgt_id,mp_gid,note,status,filterValue,filterType';
		$data = $this->request->only(explode(',',$postField),'post',null);
		$mpgroup= Db::name('mpgroup')->where('id', $data['mp_gid'])->find();
        $data['push_chatid'] = $mpgroup['group_id'];
        $data['push_chatname'] = $mpgroup['group_name'];
        $admin = session('admin');
        $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
        $data['admin_id'] =$userid;
        $data['create_time'] = time();
		try{
			$res = MprenwuModel::create($data);
			$mp_id=Db::name('mpgrouptag')->where('id', $data['mpgt_id'])->value('mp_id');
			$accountName=Db::name('monitorphone')->where('id', $mp_id)->value('phone');
			 // 重新生成缓存
            $cacheKey = "monitor_tasks_{$accountName}";
            $tasks = Db::name('mprenwu')
                ->alias('t')
                ->join('cd_mpgrouptag mgt', 't.mpgt_id = mgt.id')
                ->join('cd_monitorphone mp', 'mgt.mp_id = mp.id')
                ->where('t.status', 1)
                ->where('mp.phone', $accountName)
                ->select();
            Cache::store('redis')->set($cacheKey, $tasks, 3600);
            
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
		MprenwuModel::update($data);
		
		$datas= MprenwuModel::where('id',$data['id'])->find();
		
		$mp_id=Db::name('mpgrouptag')->where('id', $datas['mpgt_id'])->value('mp_id');
		$accountName=Db::name('monitorphone')->where('id', $mp_id)->value('phone');
		 // 重新生成缓存
        $cacheKey = "monitor_tasks_{$accountName}";
        $tasks = Db::name('mprenwu')
            ->alias('t')
            ->join('cd_mpgrouptag mgt', 't.mpgt_id = mgt.id')
            ->join('cd_monitorphone mp', 'mgt.mp_id = mp.id')
            ->where('t.status', 1)
            ->where('mp.phone', $accountName)
            
            ->select();
        Cache::store('redis')->set($cacheKey, $tasks, 3600);
		
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,title,mpgt_id,mp_gid,note,status,filterValue,filterType';
		$data = $this->request->only(explode(',',$postField),'post',null);
		try{
		    $datas=$data;
		    unset($datas['id']);
		    $mpgroup= Db::name('mpgroup')->where('id', $datas['mp_gid'])->find();
            $datas['push_chatid'] = $mpgroup['group_id'];
            $datas['push_chatname'] = $mpgroup['group_name'];
		    $datas['create_time'] = time();
			MprenwuModel::where('id',$data['id'])->update($datas);
			
			$mp_id=Db::name('mpgrouptag')->where('id', $data['mpgt_id'])->value('mp_id');
			$accountName=Db::name('monitorphone')->where('id', $mp_id)->value('phone');
			 // 重新生成缓存
            $cacheKey = "monitor_tasks_{$accountName}";
            $tasks = Db::name('mprenwu')
                ->alias('t')
                ->join('cd_mpgrouptag mgt', 't.mpgt_id = mgt.id')
                ->join('cd_monitorphone mp', 'mgt.mp_id = mp.id')
                ->where('t.status', 1)
                ->where('mp.phone', $accountName)
               
                ->select();
            Cache::store('redis')->set($cacheKey, $tasks, 3600);
            
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
		
		$res = MprenwuModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		MprenwuModel::destroy(['id'=>explode(',',$idx)],true);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MprenwuModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = MprenwuModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
		
		
		return json(['status'=>200,'msg'=>'操作成功']);
	}
	
      public function getGrouptag_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = ['status'=>1];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select id,name from cd_mpgrouptag',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}
}

