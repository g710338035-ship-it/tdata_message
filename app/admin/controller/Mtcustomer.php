<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mtcustomer as MtcustomerModel;
use app\admin\model\Mtuser as MtuserModel;
use think\facade\Db;
use app\service\TelegramService;
use think\facade\Cache;
use think\facade\Log;
class Mtcustomer extends Admin {


	/*
 	* @Description  数据列表
 	*/
	function index(){
        if (!$this->request->isPost()){
            return view('index');
        }else{
            $limit  = $this->request->post('limit', 20, 'intval');
            $page = $this->request->post('page', 1, 'intval');
            $username = $this->request->post('username', '', 'serach_in');
            $where = [];
            $admin = session('admin');
            $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
            
            if($userid!=1){
                $where['admin_id'] =$userid;
            }
            if($username) $where['username'] = ['like',"%{$username}%"];
            $where['status'] = $this->request->post('status', '', 'serach_in');
             $withJoin = [
				'Adminuser'=>explode(',','name'),
			];
            $res = MtcustomerModel::where(formatWhere($where))->withJoin($withJoin,'left')->order('id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();
            
            // 获取所有客户的ID
            $customerIds = array_column($res['data'], 'id');
            
            if(!empty($customerIds)){
                // 查询每个客户的用户状态统计
                $statusStats = MtuserModel::whereIn('customid', $customerIds)
                    ->field('customid, account_status, COUNT(*) as count')
                    ->group('customid, account_status')
                    ->select()
                    ->toArray();
                
                // 按客户ID组织统计数据
                $customerStats = [];
                foreach($statusStats as $stat){
                    $customerStats[$stat['customid']][$stat['account_status']] = $stat['count'];
                }
                
                // 填充到客户数据中
                foreach($res['data'] as &$customer){
                    $customerId = $customer['id'];
                    
                    // 获取该客户的状态统计
                    $stats = $customerStats[$customerId] ?? [];
                    $customer['status_stats'] = $stats;
                    
                    // 计算总数
                    $total = array_sum($stats);
                    $customer['accoutnum'] = $total;
                    
                    // 生成状态摘要
                    if(!empty($stats)){
                        $summary = [];
                        foreach($stats as $status => $count){
                            $summary[] = $status . ':' . $count;
                        }
                        $customer['status_summary'] = implode(', ', $summary);
                    } else {
                        $customer['status_summary'] = '';
                    }
                    
                    // 按类型统计
                    $typeSummary = [
                        'success_count' => 0,
                        'danger_count' => 0,
                        'warning_count' => 0,
                        'info_count' => 0
                    ];
                    
                    $typeMap = [
                        '正常' => 'success_count',
                        '冻结' => 'danger_count',
                        '封号' => 'warning_count',
                        '异常' => 'info_count',
                        '空号' => 'warning_count',
                        '退出' => 'warning_count',
                        '注销' => 'info_count',
                        '未授权' => 'info_count',
                        '代理异常' => 'warning_count'
                    ];
                    
                    foreach($stats as $status => $count){
                        if(isset($typeMap[$status])){
                            $typeSummary[$typeMap[$status]] += $count;
                        }
                    }
                    
                    $customer['status_type_summary'] = $typeSummary;
                }
            } else {
                // 如果没有客户数据，初始化空值
                foreach($res['data'] as &$customer){
                    $customer['accoutnum'] = 0;
                    $customer['status_stats'] = [];
                    $customer['status_summary'] = '';
                    $customer['status_type_summary'] = [
                        'success_count' => 0,
                        'danger_count' => 0,
                        'warning_count' => 0,
                        'info_count' => 0
                    ];
                }
            }
            
            $data['status'] = 200;
            $data['data'] = $res;
            return json($data);
        }
    }
	
		/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'username,password,nickname';
		$data = $this->request->only(explode(',',$postField),'post',null);

        $admin = session('admin');
        $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
        $data['admin_id'] =$userid;
        $data['addtime'] = time();
        $data['password'] = md5($data['password'].config('my.password_secrect'));
		try{
			$res = MtcustomerModel::create($data);
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
		MtcustomerModel::update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,password';
		$data = $this->request->only(explode(',',$postField),'post',null);
		try{
		    $datas=$data;
		    unset($datas['id']);		   
            $datas['password'] = md5($datas['password'].config('my.password_secrect'));
			MtcustomerModel::where('id',$data['id'])->update($datas);
			
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
		$field = 'id,status';
		$res = MtcustomerModel::field($field)->find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
	
        
        try {
             MtcustomerModel::destroy(['id'=>$id],true);
             return json(['status' => 500, 'msg' => $errorMsg]);
             MtuserModel::where(['id'=>$id])->update([
						'customid' => ''
					]);
        } catch (\Exception $e) {
            // return json(['status'=>500,'msg'=>$phoneNumber]);
            return json(['status' => 500, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
	

	}



    public function getRobot_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = ['status'=>1,'admin_id'=>session('admin.user_id')];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select id,username from cd_Mtcustomer',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}


}

