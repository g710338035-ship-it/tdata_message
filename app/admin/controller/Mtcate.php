<?php 
/*
 module:		商品分类控制器
 create_time:	2021-10-13 23:06:48
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mtcate as MtcateModel;
use app\admin\model\Mtuser as MtuserModel;
use think\facade\Db;

class Mtcate extends Admin {


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
			$where['class_id'] = $this->request->post('class_id', '', 'serach_in');
			
            $admin = session('admin');
            $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
          
            if($userid!=1){
                $where['admin_id'] =$userid;
            }
            
			$field = 'class_id,class_name,status,sortid,pid,task_num';

			$res = MtcateModel::where(formatWhere($where))->field($field)->order('class_id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			foreach ($res['data'] as $key => $value) {
				$res['data'][$key]['user_count'] = MtuserModel::where('cateid',$value['class_id'])->count();
			}

			$data['status'] = 200;
			$data['data'] = $res;
			$page == 1 && $data['sql_field_data'] = $this->getSqlField('pid');
			return json($data);
		}
	}
	/*
 	* @Description  修改排序开关
 	*/
	function updateExt(){
		$postField = 'class_id,status,sortid';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(!$data['class_id']) throw new ValidateException ('参数错误');
		MtcateModel::update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

	/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'class_name,pid,status,sortid,task_num';
		$data = $this->request->only(explode(',',$postField),'post',null);
        
		$this->validate($data,\app\admin\validate\Mtcate::class);

		try{
		   
		    $data['admin_id']=session('admin.user_id');
			$res = MtcateModel::create($data);
			if($res->class_id && empty($data['sortid'])){
				 MtcateModel::update(['sortid'=>$res->class_id,'class_id'=>$res->class_id]);
			}
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'data'=>$res->class_id,'msg'=>'添加成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'class_id,class_name,pid,status,sortid,task_num';
		$data = $this->request->only(explode(',',$postField),'post',null);

		$this->validate($data,\app\admin\validate\Mtcate::class);

		try{
			MtcateModel::update($data);
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'msg'=>'修改成功']);
	}


	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getUpdateInfo(){
		$id =  $this->request->post('class_id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		$field = 'class_id,class_name,pid,status,sortid,task_num';
		$res = MtcateModel::field($field)->find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('class_id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		if(MtuserModel::where(['cateid'=>$idx])->count()){
		    return json(['status'=>400,'msg'=>'该分类下有账户']);
		}

		MtcateModel::destroy(['class_id'=>explode(',',$idx)],true);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('class_id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		$field = 'class_id,class_name,status,sortid';
		$res = MtcateModel::field($field)->find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  获取定义sql语句的字段信息
 	*/
	function getFieldList(){
		return json(['status'=>200,'data'=>$this->getSqlField('pid')]);
	}

	/*
 	* @Description  获取定义sql语句的字段信息
 	*/
	private function getSqlField($list){
		$data = [];
		if(in_array('pid',explode(',',$list))){
			$data['pids'] = _generateSelectTree($this->query('select class_id,class_name,pid,task_num from pre_mtcate'));
		}
		return $data;
	}
	/*public function getRobot_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');
        
		$where = ['status'=>1,'admin_id'=>session('admin.user_id')];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select class_id,class_name from cd_mtcate',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}
    */
    public function getRobot_id(){
        $adminId = session('admin.user_id');
        $where = ['status'=>1, 'admin_id'=>$adminId];
    
        // 获取所有分类
        $list = MtcateModel::field('class_id, class_name,task_num')
            ->where($where)
            ->select()
            ->toArray();
        
        $resultData = [];
        
        foreach ($list as $item) {
            $cid = $item['class_id'];
            
            // 统计该分类下用户状态
            $normalCount = MtuserModel::where('cateid', $cid)
                ->where('archive',1)
                ->where('account_status', '正常')  // 正常状态
                ->count();
                
            $abnormalCount = MtuserModel::where('cateid', $cid)
                ->where('archive',0)
               // ->where('account_status', '<>', '正常')  // 非正常状态
                ->count();
            
            $key = sprintf(
                '%s_%s【%d/%d】', 
                $item['class_name'],
                $item['task_num'],
                $normalCount,
                $abnormalCount
            );
            
            $resultData[] = [
                'key' => $key,
                'val' => $cid
            ];
        }
        
        return json([
            'status' => 200,
            'data' => [
                'data' => $resultData,
                'total' => count($resultData)
            ]
        ]);
    }

}

