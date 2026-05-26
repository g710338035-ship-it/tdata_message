<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Banwords as BanwordsModel;
use think\facade\Db;

class Banwords extends Admin {


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
				'telegrambot'=>explode(',','bot_name'),
			];
			$res = BanwordsModel::where(formatWhere($where))->order('id desc')->withJoin($withJoin,'left')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	
		/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'word,bot_id,psid,duration,status';
		$data = $this->request->only(explode(',',$postField),'post',null);


        $data['create_time'] = time();
		try{
			$res = BanwordsModel::create($data);
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
		BanwordsModel::update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,word,bot_id,psid,duration,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
		try{
		    $datas=$data;
		    unset($datas['id']);
		    $datas['create_time'] = time();
			BanwordsModel::where('id',$data['id'])->update($datas);
			
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
		
		$res = BanwordsModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		BanwordsModel::destroy(['id'=>explode(',',$idx)],true);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = BanwordsModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = BanwordsModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

    public function getRobot_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = ['status'=>1];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select bot_id,bot_name from cd_telegrambot',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}


}

