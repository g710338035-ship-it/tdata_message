<?php 
/*
 module:		角色管理控制器
 create_time:	2021-10-13 23:07:27
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mpgrouptag as MpgrouptagModel;
use app\admin\model\Monitorphone as MonitorphoneModel;
use think\facade\Db;

class Mpgrouptag extends Admin {


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
                $where['admin_id'] =$userid;
            }
            
			$where['id'] = $this->request->post('id', '', 'serach_in');
			$where['name'] = $this->request->post('name', '', 'serach_in');
			$where['status'] = $this->request->post('status', '', 'serach_in');
            $phoneid = $this->request->post('phone', '', 'serach_in');
            if($phoneid){
                $where['mp_id'] =$phoneid;
            }
		

			$res = MpgrouptagModel::where(formatWhere($where))->order('id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	/*
 	* @Description  修改排序开关
 	*/
	function updateExt(){
		$postField = 'id,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(!$data['id']) throw new ValidateException ('参数错误');
		MpgrouptagModel::update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

	/*start*/
	/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'name,status,description,access,mp_id';
		$data = $this->request->only(explode(',',$postField),'post',null);

		
		$data['access'] = implode(',',$data['access']);
        $admin = session('admin');
        $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
        $data['admin_id'] =$userid;
		$res = MpgrouptagModel::create($data);
		return json(['status'=>200,'data'=>$res->id,'msg'=>'添加成功']);
	}
	/*end*/

	/*start*/
	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,name,status,description,access,mp_id';
		$data = $this->request->only(explode(',',$postField),'post',null);

	
		
		$data['access'] = implode(',',$data['access']);

		MpgrouptagModel::update($data);
		return json(['status'=>200,'msg'=>'修改成功']);
	}
	
	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getUpdateInfo(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) $this->error('参数错误');
		$field = 'id,name,status,description,access,mp_id';
		$res = MpgrouptagModel::field($field)->find($id);
		$res['access'] = explode(',',$res['access']);
		return json(['status'=>200,'data'=>$res]);
	}
	/*end*/

	/*start*/
	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		MpgrouptagModel::destroy(['id'=>explode(',',$idx)],true);
		return json(['status'=>200,'msg'=>'操作成功']);
	}
	/*end*/
    

    public function getGrouplist(){
        $mpid =  $this->request->post('newValue');
		$menu = $this->getGrouplists($mpid);
		$order_array = array_column($menu, 'id');			//数组排序 根据sortid 正序
		array_multisort($order_array,SORT_ASC,$menu );

		return json(['status'=>200,'menus'=>$menu,'mpid'=>$mpid]);
	}
	
	
	//权限系统获取菜单
	private function getGrouplists($mpid){
		$field = 'id,group_id,group_name,phone';
		$admin = session('admin');
        $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
        
       $phone = MonitorphoneModel::where('id',$mpid)->value('phone');
		$list = Db::name('mpgroup')->field($field)->where('admin_id',$userid)->where('phone',$phone)->order('id asc')->select()->toArray();
		if($list){
			foreach($list as $key=>$val){
				$menus[$key]['id'] = $val['id'];
				$menus[$key]['access'] = $val['group_id'];
				$menus[$key]['group_name'] = $val['group_name'];
			}
			return array_values($menus);
		}else{
		    $menus=[];
		    return $menus;
		}
	}

}

