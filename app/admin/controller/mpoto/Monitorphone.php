<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Monitorphone as MonitorphoneModel;
use think\facade\Db;
use app\service\TelegramService;
use think\facade\Cache;
use think\facade\Log;
class Monitorphone extends Admin {


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
			$where['phone|title'] = $this->request->post('username', '', 'serach_in');
			$where['monitorphone.status'] = $this->request->post('status', '', 'serach_in');
            $withJoin = [
				'Adminuser'=>explode(',','name'),
			];
			$res = MonitorphoneModel::where(formatWhere($where))->order('id desc')->withJoin($withJoin,'left')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	
		/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'title,phone';
		$data = $this->request->only(explode(',',$postField),'post',null);

        $admin = session('admin');
        $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
        $data['admin_id'] =$userid;
        $data['create_time'] = time();
		try{
			$res = MonitorphoneModel::create($data);
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
		MonitorphoneModel::update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,title,phone';
		$data = $this->request->only(explode(',',$postField),'post',null);
		try{
		    $datas=$data;
		    unset($datas['id']);
		    $datas['create_time'] = time();
			MonitorphoneModel::where('id',$data['id'])->update($datas);
			
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'msg'=>'修改成功']);
	}
	
    public function sendCode()
        {
            $id = $this->request->post('id', '', 'serach_in');
           
            if (!$id) {
                throw new ValidateException('参数错误');
            }
    
            $res = MonitorphoneModel::find($id);
            $phoneNumber = $res['phone'];
            
            
            if (empty($phoneNumber)) {
                return json(['status' => 400, 'msg' => '手机号不能为空']);
            }
    
            // 频率限制检查（5分钟内只能发送一次）
            /*if (Cache::has('telegram_code_cooldown_' . $phoneNumber)) {
                return json(['status' => 400, 'msg' => '请求过于频繁，请5分钟后再试']);
            }*/
            
            try {
                $telegramService = new TelegramService();
               
                $result = $telegramService->sendLoginCode($phoneNumber);
              
                if ($result['status']) {
                    $cacheKey = 'telegram_code_' . $phoneNumber;
                    $phoneCodeHash = $result['phone_code_hash'];
                    // 将 phone_code_hash 存入缓存，有效期 5 分钟（300 秒）
                    Cache::store('redis')->set($cacheKey, $phoneCodeHash, 300);
                    
                    $res['code']='';    
                    return json(['status' => 200, 'msg' => '验证码已发送','data'=>$res]);
                } else {
                    return json(['status' => 500, 'msg' => $result['message']]);
                }
            } catch (\Exception $e) {
                // return json(['status'=>500,'msg'=>$phoneNumber]);
                return json(['status' => 500, 'msg' => '验证码发送失败: ' . $e->getMessage()]);
            }
        }
    public function monitorlogout()
    {
        $id = $this->request->post('id', '', 'serach_in');
    
        if (!$id) {
            throw new ValidateException('参数错误');
        }
    
        $res = MonitorphoneModel::find($id);
        $phoneNumber = $res['phone'];
    
        if (empty($phoneNumber)) {
            return json(['status' => 400, 'msg' => '手机号不能为空']);
        }
  
    
        try {
           // $telegramService = new TelegramService();
           // $result = $telegramService->logout($phoneNumber);
    
           // if ($result['status']) {
                // 存储验证码信息并设置冷却时间
                
                $mpwebhook = new \app\mproto\controller\Mpapi();
                $rshook=$mpwebhook->stopWebhook($phoneNumber);
                if($rshook){
                    $data['status'] = 0;
                    $res = MonitorphoneModel::field('status')->where(['id' => $id])->update($data);
                    return json(['status' => 200, 'msg' => '停止监控']);}else{
                  return json(['status' => 500, 'msg' => '停止监控失败']);  
                }
                
            /*} else {
                return json(['status' => 500, 'msg' => $result['message']]);
            }*/
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '退出失败: ' . $e->getMessage()]);
        }
    }
    public function monitorstarthook()
    {
        $id = $this->request->post('id', '', 'serach_in');
    
        if (!$id) {
            throw new ValidateException('参数错误');
        }
    
        $res = MonitorphoneModel::find($id);
        $phoneNumber = $res['phone'];
    
        if (empty($phoneNumber)) {
            return json(['status' => 400, 'msg' => '手机号不能为空']);
        }
  
    
        try {
            //$telegramService = new TelegramService();
            //$result = $telegramService->logout($phoneNumber);
    
           //if ($result['status']) {
                // 存储验证码信息并设置冷却时间
               
                $mpwebhook = new \app\mproto\controller\Mpapi();
                $rshook=$mpwebhook->startWebhook($phoneNumber);
                if($rshook){
                    $data['status'] = 1;
                    $res = MonitorphoneModel::field('status')->where(['id' => $id])->update($data);
                    return json(['status' => 200, 'msg' => '开始监控']);
                     
                }else{
                  return json(['status' => 500, 'msg' => '开始监控失败']);  
                }
           // } else {
              //  return json(['status' => 500, 'msg' => $result['message']]);
            //}
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '开始失败: ' . $e->getMessage()]);
        }
    } 
    public function verifyCode()
    {
        $id = $this->request->post('id', '', 'serach_in');
        $code = $this->request->post('code', '', 'serach_in');
        $pwd = $this->request->post('pwd2', '', 'serach_in');
        if (!$id || !$code) {
            throw new ValidateException('参数错误');
        }
    
        $res = MonitorphoneModel::find($id);
        $phoneNumber = $res['phone'];
    
        if (empty($phoneNumber)) {
            return json(['status' => 400, 'msg' => '手机号不能为空']);
        }
    
        // 从缓存中获取 phone_code_hash
        $cacheKey = 'telegram_code_' . $phoneNumber;
        $phoneCodeHash = Cache::store('redis')->get($cacheKey);

        if (empty($phoneCodeHash)) {
            return json(['status' => 400, 'msg' => '验证码已过期，请重新获取']);
        }
    
        try {
            $telegramService = new TelegramService();
            $result = $telegramService->verifyLoginCode($phoneNumber, $code, $phoneCodeHash,$pwd);
    
            if ($result) {
                $data['status'] = 1;
		        $res = MonitorphoneModel::field('status')->where(['id'=>$id])->update($data);
		        
		        $mpwebhook = new \app\mproto\controller\Mpapi();
                $mpwebhook->startWebhook($phoneNumber);
		        
                
                // 验证成功，可以进行后续操作，如更新数据库状态等
                return json(['status' => 200, 'msg' => '验证成功']);
            } else {
                return json(['status' => 500, 'msg' => '验证码验证失败']);
            }
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '验证码验证失败: ' . $e->getMessage()]);
        }
    }    
    /*
 	* @Description  登录协议号
 	*/
	function monitorlogin(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MonitorphoneModel::find($id);
		
		return json(['status'=>200,'data'=>$res]);
	}
	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getUpdateInfo(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MonitorphoneModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MonitorphoneModel::find($id);
        $phoneNumber = $res['phone'];
        
            
        if (empty($phoneNumber)) {
            return json(['status' => 400, 'msg' => '手机号不能为空']);
        }

        
        try {
            $telegramService = new TelegramService();
           
            $result = $telegramService->logout($phoneNumber);
              Log::info("手机号  ".$result['status']); 
            if (is_array($result) && $result['status'] == 200) {
                $mpwebhook = new \app\mproto\controller\Mpapi();
                $mpwebhook->stopWebhook($phoneNumber);
                MonitorphoneModel::destroy(['id'=>explode(',',$id)],true);
                return json(['status' => 200, 'msg' => $result['msg']]);
            } else {
                MonitorphoneModel::destroy(['id'=>explode(',',$id)],true);
               $errorMsg = isset($result['msg']) ? $result['msg'] : '未知错误';
               return json(['status' => 500, 'msg' => $errorMsg]);
            }
        } catch (\Exception $e) {
            // return json(['status'=>500,'msg'=>$phoneNumber]);
            return json(['status' => 500, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
	

	}


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MonitorphoneModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = MonitorphoneModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

    public function getRobot_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = ['status'=>1];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select id,phone from cd_monitorphone',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}


}

