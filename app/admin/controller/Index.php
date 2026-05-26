<?php
namespace app\admin\controller;
use think\facade\Db;
use app\admin\model\Mtuser as MtuserModel;
use app\admin\model\Mttask as MttaskModel;
class Index extends Admin {
	
	
	public function index(){
		return view('index');
	}
	
	
	//后台首页主体内容
	public function main(){
		if(!$this->request->isPost()){

			return view('main');
		}else{		
		
			$data['card_data'] = $this->getCardData();
			$data['status'] = 200;
			return json($data);
		}
	}
	
	
	//头部提示消息
	function getNotice(){
		$data = [
			[
				'num'=>5,
				'title'=>'条评论待回复',
				'url'=>(string)url('admin/Membe/index'),
			],
			[
				'num'=>12,
				'title'=>'条订单待处理',
				'url'=>(string)url('admin/Map/index'),
			],
			[
				'num'=>50,
				'title'=>'条私信待处理',
				'url'=>(string)url('admin/Membe/index'),
			],
		];
		
		return json(['status'=>200,'data'=>$data]);
	}
	
	//首页统计数据
	private function getCardData(){
	    $adminId = session('admin.user_id');
        $query = MtuserModel::query();
        $tsquery = MttaskModel::query();
        // 如果 admin_id 不为 1，则添加 where 条件
        if ($adminId != 1) {
            $query->where('admin_id', $adminId);
            $tsquery->where('admin_id', $adminId);
        }
        
        $userNum = $query->count();
        $groupNum = $query->sum('groups_count');
        $phoneNum = $query->sum('friends_count');
        
	    $renwuNum=$tsquery->count();
		$card_data = [	//头部统计数据
			[
			  'title_icon'=>"el-icon-user",
			  'card_title'=> "账号",
			  'card_cycle'=> "",
			  'card_cycle_back_color'=> "#409EFF",
		
			  'vist_num'=> $userNum,
			  
			  'vist_all_icon'=> "el-icon-trophy",
			],
			[
			  'title_icon'=> "el-icon-download",
			  'card_title'=> "群组",
			  'card_cycle'=> "",
			  'card_cycle_back_color'=> "#67C23A",
			 
			  'vist_num'=> $groupNum,
			 
			  'vist_all_icon'=> "el-icon-download",
			],
			[
			  'title_icon'=> "el-icon-wallet",
			  'card_title'=> "用户",
			  'card_cycle'=> "",
			  'card_cycle_back_color'=> "#F56C6C",
		
			  'vist_num'=> $phoneNum,
			  
			  'vist_all_icon'=> "el-icon-coin",
			],
			[
			  'title_icon'=> "el-icon-coordinate",
			  'card_title'=> "任务数",
			  'card_cycle'=> "",
			  'card_cycle_back_color'=> "#E6A23C",
			  
			  'vist_num'=> $renwuNum,
			 
			  'vist_all_icon'=> "el-icon-data-line",
			],
		];
		
		return $card_data;
	}
	
	
	
	
}