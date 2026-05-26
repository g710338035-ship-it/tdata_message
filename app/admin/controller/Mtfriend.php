<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mtfriend as MtfriendModel;
use think\facade\Db;
use think\facade\Cache;

class Mtfriend extends Admin {


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
		
			$where['username'] = $this->request->post('username', '', 'serach_in');
			$where['status'] = $this->request->post('status', '', 'serach_in');



			$res = MtfriendModel::where(formatWhere($where))->order('friend_id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	/*
 	* @Description  修改排序开关
 	*/
	function updateExt(){
		$postField = 'friend_id,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(!$data['friend_id']) throw new ValidateException ('参数错误');
		MtfriendModel::update($data);
		
		
		$cacheMtfriend="telegram_Mtfriend";
       // if (!Cache::store('redis')->has($cacheMtfriend)) {
       
           $rs = Db::name('Mtfriend')->where('status', 1)
            ->select()->toArray();
           Cache::store('redis')->set($cacheMtfriend, $rs, 3600);
        //}
		return json(['status'=>200,'msg'=>'操作成功']);
	}

	/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'user_id,status';
		$data = $this->request->only(explode(',',$postField),'post',null);

		$this->validate($data,\app\admin\validate\Mtfriend::class);

		try{
			$res = MtfriendModel::create($data);
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'data'=>$res->friend_id,'msg'=>'添加成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'friend_id,user_id,status';
		$data = $this->request->only(explode(',',$postField),'post',null);

		$this->validate($data,\app\admin\validate\Mtfriend::class);
	
		try{
			MtfriendModel::update($data);
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'msg'=>'修改成功']);
	}


	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getUpdateInfo(){
		$id =  $this->request->post('friend_id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		$field = 'friend_id,user_id,status';
		$res = MtfriendModel::field($field)->find($id);
		$res['ssq'] = explode('-',$res['ssq']);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('friend_id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		MtfriendModel::destroy(['friend_id'=>explode(',',$idx)],true);
		
		/*$cacheMtfriend="telegram_Mtfriend";
        if (!Cache::store('redis')->has($cacheMtfriend)) {
       
           $rs = Db::name('Mtfriend')->where('status', 1)
            ->select()->toArray();
           Cache::store('redis')->set($cacheMtfriend, $rs, 3600);
        }*/
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('friend_id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		$field = 'friend_id,user_id,status';
		$res = MtfriendModel::field($field)->find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  导入
 	*/
	public function importData(){
		$data = $this->request->post();
		$list = [];
		foreach($data as $key=>$val){
			$list[$key]['user_id'] = $val['用户名'];
		}
		(new MtfriendModel)->saveAll($list);
		return json(['status'=>200]);
	}


	/*
 	* @Description  导出
 	*/
	function dumpdata(){
		$page = $this->request->post('page', 1, 'intval');
		$limit = config('my.dumpsize') ? config('my.dumpsize') : 1000;

		$where = [];
		$where['friend_id'] = ['in',$this->request->post('friend_id', '', 'serach_in')];
		$where['user_id'] = $this->request->post('user_id', '', 'serach_in');
	

		$field = 'friend_id,username,sex,mobile,pic,email,password,status,amount,ssq,create_time';

		$res = MtfriendModel::where(formatWhere($where))->field($field)->order('friend_id desc')->limit(($page-1)*$limit,$limit)->select()->toArray();

		foreach($res as $key=>$val){
			$res[$key]['sex'] = getItemVal($val['sex'],'[{"key":"男","val":"1","label_color":""},{"key":"女","val":"2","label_color":""}]');
			$res[$key]['status'] = getItemVal($val['status'],'[{"key":"开启","val":"1"},{"key":"关闭","val":"0"}]');
			$res[$key]['create_time'] = date('Y-m-d',$val['create_time']);
		}

		$data['status'] = 200;
		$data['header'] = explode(',','编号,用户名,性别,手机号,头像,邮箱,密码,状态,积分,省市区,创建时间');
		$data['percentage'] = ceil($page * 100/ceil(MtfriendModel::where(formatWhere($where))->count()/$limit));
		$data['filename'] = '会员管理.'.config('my.dump_extension');
		$data['data'] = $res;
		return json($data);
	}


	/*
 	* @Description  重置密码
 	*/
	public function resetPwd(){
		$postField = 'friend_id,password';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(empty($data['friend_id'])) throw new ValidateException ('参数错误');
		if(empty($data['password'])) throw new ValidateException ('密码不能为空');

		$data['password'] = md5($data['password'].config('my.password_secrect'));
		$res = MtfriendModel::update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  数值加
 	*/
	public function jia(){
		$postField = 'friend_id,amount';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(empty($data['friend_id'])) throw new ValidateException ('参数错误');
		if(empty($data['amount'])) throw new ValidateException ('值不能为空');
		$res = MtfriendModel::field('amount')->where('friend_id',$data['friend_id'])->inc('amount',$data['amount'])->update();
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  数值减
 	*/
	public function jian(){
		$postField = 'friend_id,amount';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(empty($data['friend_id'])) throw new ValidateException ('参数错误');
		if(empty($data['amount'])) throw new ValidateException ('值不能为空');

		if($data['amount'] > MtfriendModel::where('friend_id',$data['friend_id'])->value('amount')){
			throw new ValidateException('数据不足');
		}
		$res = MtfriendModel::field('amount')->where('friend_id',$data['friend_id'])->dec('amount',$data['amount'])->update();
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('friend_id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');

		$data['status'] = '0';
		$res = MtfriendModel::field('status')->where(['friend_id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}




}

