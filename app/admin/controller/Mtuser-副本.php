<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mtuser as MtuserModel;
use think\facade\Db;
use app\service\TelegramTdata; // 引入tdata服务
use think\facade\Cache;
use think\facade\Log;
use think\facade\Queue;
use app\job\TelegramTask; 

class Mtuser extends Admin {


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
                $where['Mtuser.admin_id'] =$userid;
            }
			$where['Mtuser.account'] = $this->request->post('account', '', 'serach_in');
			$where['Mtuser.nickName'] = $this->request->post('nickName', '', 'serach_in');
			$where['Mtuser.status'] = $this->request->post('status', '', 'serach_in');
			$where['Mtuser.archive'] =1;
			$where['Mtuser.customid']=$this->request->post('customid', '', 'serach_in');
			$where['Mtuser.cateid']=$this->request->post('cateid', '', 'serach_in');
			
			$where['Mtuser.account_status']=$this->request->post('account_status', '', 'serach_in');
            $withJoin = [
				'Adminuser'=>explode(',','name'),
                'Mtcate'=>explode(',','class_name'),				
                'Mtcustom'=>explode(',','username'),
			];
			$res = MtuserModel::where(formatWhere($where))->order('id desc')->withJoin($withJoin,'left')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	
	/*
 	* @Description  修改
 	*/
	public function update(){
		// 接收所有提交的字段
		$postField = 'id,tdata_path,session_path,proxyip,current_password,new_password,first_name,last_name,username,bio,avatar,operate_type';
		$data = $this->request->only(explode(',',$postField),'post',null);
		log::info($data['proxyip']);
		//return json(['status'=>200,'msg'=>'操作成功']);
		if(empty($data['id'])){
			throw new ValidateException('参数错误：缺少会员ID');
		}
		
		// 获取会员信息
		$mtuser = MtuserModel::find($data['id']);
		if(empty($mtuser)){
			throw new ValidateException('会员不存在');
		}
		
		// 初始化Telegram服务
		$telegramService = new TelegramTdata();
		$tdataPath = $mtuser['session_path'];
		
		if(empty($tdataPath)){
			throw new ValidateException('缺少tdata路径信息，无法操作Telegram账号');
		}
		$status=200;
		$msg='修改成功';
		Db::startTrans();
		try{
			// 1. 更新基本会员信息
			$basicData = [
				'updatetime' => time()
			];
			// 2. 处理头像上传及更新
			if($data['avatar']&&$data['operate_type']=='avatar'){
	
				$avatarPath = $data['avatar'];
				
				// 更新Telegram头像
				$result =$telegramService->updateProfilePhoto(
					$tdataPath,
					$avatarPath,
					$data['proxyip'] ?? null
				);
				if($result['status']){
				// 保存头像路径到数据库
				MtuserModel::where('id', $data['id'])->update([
					'avatar_url' => $avatarPath
				]);
				}
			}elseif(!empty($data['current_password']) && !empty($data['new_password'])&&$data['operate_type']=='password'){
				$result =$telegramService->changePassword(
					$tdataPath,
					$data['current_password'],
					$data['new_password'],
					$data['proxyip'] ?? null
				);
			}elseif(!empty($data['first_name'])&&$data['operate_type']=='nickname'){
				$result =$telegramService->updateNickname(
					$tdataPath,
					$data['first_name'],
					$data['last_name'] ?? '',
					$data['proxyip'] ?? null
				);
				if($result['status']){
    				// 更新会员表中的昵称信息
    				MtuserModel::where('id', $data['id'])->update([
    					'nickName' => $data['first_name'].$data['last_name'] ?? '',
    				]);
				}
			}elseif(!empty($data['username']&&$data['operate_type']=='editusername')){
				$result = $telegramService->updateUsername(
					$tdataPath,
					$data['username'],
					$data['proxyip'] ?? null
				);
				
				if($result['status']){
					MtuserModel::where('id', $data['id'])->update([
						'username' => $data['username']
					]);
				}
			}elseif(isset($data['bio'])&&$data['operate_type']=='bio'){
				$result =$telegramService->updateBio(
					$tdataPath,
					$data['bio'],
					$data['proxyip'] ?? null
				);
				
			}
			log::info(json_encode($result));
			if(isset($result)&&!$result['status']){
			    $msg=$result['message'];
			    $status=400;
			    if(isset($result['data']['account_status'])){
			        MtuserModel::where('id', $data['id'])->update([
    					'status'=>0,
                        'remark'=>$result['message'],
                        'account_status'=> $result['data']['account_status'],
                        'account_status_desc'=> $result['data']['account_status_desc'],
    				]);
			    }
            }
			
			Db::commit();
		}catch(\Exception $e){
			Db::rollback();
			Log::error('会员信息更新失败：' . $e->getMessage() . '，会员ID：' . $data['id']);
			throw new ValidateException('更新失败：' . $e->getMessage());
		}
		
		return json(['status'=>$status,'msg'=>$msg]);
	}
	
   
	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getUpdateInfo(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MtuserModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}



 	/*
 	* @Description  归档
 	*/  
  
    public function archive(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$userIds = explode(',', $idx);
		$data['archive'] = 0;
		$res = MtuserModel::field('archive')->where(['id'=> $userIds])->update($data);
		if ($res) {
		    // 2. 批量更新缓存
            $this->updateUserArchiveCache($userIds);
		}
		
		return json(['status'=>200,'msg'=>'操作成功']);
	}
	

	public function archiveup(){
        $idx = $this->request->post('id', '', 'serach_in');
        if(empty($idx)) throw new ValidateException('参数错误');
        
        $userIds = explode(',', $idx);
        
        // 1. 获取这些用户的当前状态
        $userStatuses = MtuserModel::field('id, account_status')
            ->where(['id' => $userIds])
            ->select();
        
        // 2. 构建状态映射数组
        $statusMap = [];
        foreach($userStatuses as $user) {
            $statusMap[$user['id']] = $user['account_status'];
        }
        
        // 3. 筛选出状态正常的用户ID
        $validUserIds = [];
        foreach($userIds as $userId) {
            $status = isset($statusMap[$userId]) ? $statusMap[$userId] : '';
            // 只有状态为'正常'的用户才能被归档
            if($status === '正常'||$status === '代理异常') {
                $validUserIds[] = $userId;
            }
        }
        
        // 4. 如果没有符合条件的用户，直接返回成功（或者可以根据需求返回提示）
        if(empty($validUserIds)) {
            return json(['status' => 200, 'msg' => '操作成功']);
        }
        
        // 5. 只更新状态正常的用户
        $data['archive'] = 1;
        $res = MtuserModel::field('archive')
            ->where(['id' => $validUserIds])
            ->update($data);
        
        if ($res) {
            // 6. 批量更新缓存，只更新成功归档的用户
            $this->updateUserArchiveCache($validUserIds);
        }
        
        return json(['status' => 200, 'msg' => '操作成功']);
    }
	
	
	//上线
    public function accountup(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        $telegramTdata = new TelegramTdata(); 
        try {
            foreach ($mtusers as $mtuser) {
                $result = $telegramTdata->setOnline($mtuser->session_path,$mtuser->proxyip);
                log::info(json_encode($result));
                $data['online']=1;
                $data['remark']=$result['message'];
                $res = MtuserModel::where(['id'=>$mtuser['id']])->update($data);
            }
            
            return json(['status' => 200, 'msg' => '操作成功']);
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
		
		
		
		$data['online'] = 1;
		$res = MtuserModel::field('online')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}
	
	//检测
    public function checkAccount(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        $telegramTdata = new TelegramTdata(); 
        try {
            foreach ($mtusers as $mtuser) {
                // 调用独立函数检查tdata_path
                $checkResult = $this->checkTdataPathExists($mtuser);
                log::info(json_encode($checkResult,true));
                if(!$checkResult['exists']) {
                    // 标注账号异常
                    MtuserModel::where(['id' => $mtuser['id']])->update([
                        'remark' => $checkResult['message'],
                        'status'=>0
                    ]);
                    $errorAccounts[] = $mtuser['id'];
                    continue; // 跳过当前账号，处理下一个
                }
                
                $result = $telegramTdata->getAccountInfo($mtuser->session_path,$mtuser->proxyip);
                log::info(json_encode($result));
                if($result['status']){
                    $data['username']=$result['data']['username'];
                    $data['nickName']=$result['data']['nickname'];
                    $data['friends_count']=$result['data']['friends_count'];
                    $data['groups_count']=$result['data']['groups_count'];
                    $data['avatar_url']=$result['data']['avatar_url'];
                    if($result['data']['status']['is_online']){
                        $data['remark']='在线';
                    }else{
                        $data['remark']='离线';
                    }
                    $data['status']=1;
                    $data['account_status']= $result['data']['account_status'];
                    $data['account_status_desc']= $result['data']['account_status_desc'];
                }else{
                    $data['status']=0;
                    $data['remark']=$result['message'];
                    $data['account_status']= $result['data']['account_status'];
                    $data['account_status_desc']= $result['data']['account_status_desc'];
                }
                
                $res = MtuserModel::where(['id'=>$mtuser['id']])->update($data);
            }
            $userIds = explode(',', $idx);
    		
    		    // 2. 批量更新缓存
            $this->updateUserArchiveCache($userIds);
    		
            return json(['status' => 200, 'msg' => '操作成功']);
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
		
	}
	//删除好友逻辑
	public function deleteFriends(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        $telegramTdata = new TelegramTdata(); 
        try {
            foreach ($mtusers as $mtuser) {
                $result = $telegramTdata->deleteAllContacts($mtuser->session_path,$mtuser->proxyip);
               
                $data['friends_count']=0;
                $res = MtuserModel::where(['id'=>$mtuser['id']])->update($data);
            }
            
            return json(['status' => 200, 'msg' => '操作成功']);
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
	}
		//退出群聊逻辑
	public function exitGroups(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        $telegramTdata = new TelegramTdata(); 
        try {
            foreach ($mtusers as $mtuser) {
                $result = $telegramTdata->leaveAllGroups($mtuser->session_path,$mtuser->proxyip);
              
                $data['groups_count']=$result['data']['total']-$result['data']['left'];
                $res = MtuserModel::where(['id'=>$mtuser['id']])->update($data);
            }
            
            return json(['status' => 200, 'msg' => '操作成功']);
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
	}
		//退出其他设备逻辑
	public function exitOtherDevices(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        $telegramTdata = new TelegramTdata(); 
        try {
            foreach ($mtusers as $mtuser) {
                $result = $telegramTdata->logoutOtherSessions($mtuser->session_path,$mtuser->proxyip);
                log::info(json_encode($result));
            }
            
            return json(['status' => 200, 'msg' => '操作成功']);
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
	}
	//下线
    public function accountdown(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        $telegramTdata = new TelegramTdata(); 
        try {
            foreach ($mtusers as $mtuser) {
                $result = $telegramTdata->setOffline($mtuser->session_path,$mtuser->proxyip);
                log::info(json_encode($result));
                $data['online']=0;
                $data['remark']=$result['message'];
                $res = MtuserModel::where(['id'=>$mtuser['id']])->update($data);
            }
            
            return json(['status' => 200, 'msg' => '操作成功']);
        } catch (\Exception $e) {
            return json(['status' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
		
		
		
		$data['online'] = 1;
		$res = MtuserModel::field('online')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}
    /*
    转移分组*/
    public function transfer(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['cateid'] =  $this->request->post('cateid', '', 'serach_in');
		$res = MtuserModel::field('cateid')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}
	/*
    转移分组*/
    public function allocatecustom(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['customid'] =  $this->request->post('customid', '', 'serach_in');
		$res = MtuserModel::field('customid')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}
    /*
    删除客服*/
    public function delCustom(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['customid'] =  0;
		$res = MtuserModel::field('customid')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}
	/* 删除socket*/
	public function deleteSockets(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['socktsid'] =  0;
		$data['proxyip'] =  '';
		$res = MtuserModel::where(['id'=>explode(',',$idx)])->update($data);
		
		$userIds = explode(',', $idx);
		if ($res) {
		    // 2. 批量更新缓存
            $this->updateUserArchiveCache($userIds);
		}
		
		return json(['status'=>200,'msg'=>'操作成功']);
	}			
	public function allocateSockets()
    {
        
        
        // 获取请求数据
        $data = $this->request->post();
        
        // 验证必要参数
        if (empty($data['id'])) {
            return json(['status' => 400, 'msg' => '请选择要分配代理的账号']);
        }
        if (empty($data['socktsid'])) {
            return json(['status' => 400, 'msg' => '请选择代理分组']);
        }
        
        // 解析账号ID
        $accountIds = explode(',', $data['id']);
        
        // 开始事务
        Db::startTrans();
        
        try {
           
            if (!empty($data['socktswb'])) {
                // 按行分割自定义代理信息
                $lines = explode("\n", $data['socktswb']);
                
                foreach ($lines as $key => $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // 解析代理信息
                    $socketInfo = $this->parseSocketLine($line,$data['socktsid']);
                    
                    if (isset($accountIds[$key])) {
                        // 为每个账号分配解析后的代理
                        $accountId = $accountIds[$key];
                        
                        // 保存代理信息
                        $socketId = $this->saveSocketInfo($socketInfo);
                        
                        // 保存账号与代理的关联
                        $this->saveAccountSocket($accountId, ['id' => $socketId,'line'=>$line]);
                    }
                }
            }else{
				$sockets = Db::name('sockts')
                    ->where('skcateid', $data['socktsid'])
                    ->where('status', 1) // 状态为可用
                    ->select()
					->toArray(); 
                
                if (empty($sockets)) {
                    throw new \Exception('所选代理分组中没有可用的代理');
                }
                
                // 为每个账号分配随机代理
                foreach ($accountIds as $accountId) {
                    // 随机选择一个代理
                    $socket = $sockets[array_rand($sockets)];
                    $line=$socket['ip'] . ':' . $socket['port'] . '##' . $socket['username'] . '##' . $socket['password'];
                    // 保存账号与代理的关联
                    $this->saveAccountSocket($accountId, ['id' => $socket['id'],'line'=>$line]);
                }
			}
            
            // 提交事务
            Db::commit();
    		
    		  // 2. 批量更新缓存
            $this->updateUserArchiveCache($accountIds);
    		
            return json(['status' => 200, 'msg' => '代理分配成功']);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            
            // 记录错误日志
            Log::error('分配代理失败: ' . $e->getMessage());
            
            return json(['status' => 500, 'msg' => '分配代理失败: ' . $e->getMessage()]);
        }
    }
    
    // 解析单行代理信息
    private function parseSocketLine($line,$skcateid)
    {
        // 假设格式: IP:端口##用户名-区域-其他信息##密码
        $parts = explode('##', $line);
        
        $socketInfo = [
            'ip' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
            'zone' => '',
            'other_info' => '',
            'addtime' => time(),
            'updatetime' => time(),
            'status' => 1,
			'skcateid'=>$skcateid,
        ];
        
        // 解析IP和端口
        if (isset($parts[0])) {
            list($socketInfo['ip'], $socketInfo['port']) = explode(':', $parts[0], 2);
        }
        
        // 解析用户名和区域信息
        if (isset($parts[1])) {
            $userInfo = explode('-', $parts[1], 5);
            if (isset($userInfo[0])) $socketInfo['username'] = $userInfo[0];
            if (isset($userInfo[1])) $socketInfo['zone'] = $userInfo[1];
            if (isset($userInfo[2])) $socketInfo['other_info'] = implode('-', array_slice($userInfo, 2));
        }
        
        // 解析密码
        if (isset($parts[2])) {
            $socketInfo['password'] = $parts[2];
        }
        
        return $socketInfo;
    }
    
    // 保存代理信息到数据库
    private function saveSocketInfo($socketInfo)
    {
        // 检查是否已有相同IP和端口的代理
        $existSocket = Db::name('sockts')
            ->where('ip', $socketInfo['ip'])
            ->where('port', $socketInfo['port'])
            ->find();
        
        if ($existSocket) {
            // 更新现有代理
            Db::name('sockts')
                ->where('id', $existSocket['id'])
                ->update($socketInfo);
            
            return $existSocket['id'];
        } else {
            // 插入新代理
            return Db::name('sockts')->insertGetId($socketInfo);
        }
    }
    
    // 保存账号与代理的关联
    private function saveAccountSocket($accountId, $socket)
    {
                
        // 更新账号表中的代理信息
        Db::name('mtuser')
            ->where('id', $accountId)
            ->update([
				'socktsid'=>$socket['id'],
                'proxyip' => $socket['line'],
                'updatetime' => time()
            ]);
    }

    public function getRobot_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = ['status'=>1];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select id,phone from cd_Mtuser',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}
	
    private function getRedisCache()
    {
        return Cache::store('redis');
    }
    /**
     * 更新用户归档状态缓存
     * @param array $userIds 用户ID数组
     */
    private function updateUserArchiveCache(array $userIds)
    {
        if (empty($userIds)) {
            Log::info("没有需要更新归档缓存的用户ID");
            return;
        }
    
        // 统一处理用户ID，确保为整数类型
        $userIds = array_map(function($id) {
            return (int)$id;
        }, $userIds);
        $userIds = array_unique($userIds); // 去重避免重复操作
    
        $redis = $this->getRedisCache();
        $redisPrefix = 'telegram_task:';
        $expireTime = 86400; // 24小时过期
    
        // 强制从数据库批量获取最新数据（忽略现有缓存）
        $this->batchFetchAndUpdateFromDb($redis, $redisPrefix, $userIds, $expireTime);
    
        Log::info("用户归档缓存已强制从数据库更新，共处理 " . count($userIds) . " 个用户");
    }
    
 
    
    /**
     * 批量从数据库获取并更新缓存
     */
    private function batchFetchAndUpdateFromDb($redis, $prefix, array $userIds, $expireTime)
    {
        // 批量查询数据库，获取最新用户数据
        $users = MtuserModel::field('id, tdata_path,status,session_path, proxyip, archive')
            ->whereIn('id', $userIds)
            ->select()
            ->toArray();
    
        $userMap = array_column($users, null, 'id');
    
        foreach ($userIds as $userId) {
            $cacheKey = $prefix . 'user:' . $userId;
            
            if (isset($userMap[$userId])) {
                // 数据库中存在的用户：强制更新归档状态并覆盖缓存
                $userData = $userMap[$userId];
                //$userData['archive'] = 0; // 强制设置为归档状态（确保与数据库一致）
                $redis->set($cacheKey, json_encode($userData, JSON_UNESCAPED_UNICODE), $expireTime);
                Log::info("用户 {$userId} 已从数据库强制更新归档缓存");
            } else {
                // 数据库中不存在的用户：标记为删除（覆盖可能存在的旧缓存）
                $redis->set($cacheKey, json_encode(null), 600);
                Log::info("用户 {$userId} 数据库中不存在，强制标记为删除缓存");
            }
        }
    }
    
    
    /**
     * 检查tdata_path是否存在
     * @param object $mtuser mtuser模型实例
     * @return array 包含检查结果和消息
     */
    private function checkTdataPathExists($mtuser) {
        // 检查字段是否为空
        if(empty($mtuser->session_path)) {
            return [
                'exists' => false,
                'message' => '账号异常：session_path路径为空'
            ];
        }
        
        // 检查文件是否实际存在
        if(!file_exists($mtuser->session_path)) {
            return [
                'exists' => false,
                'message' => '账号异常：session_path文件不存在'
            ];
        }
        
        // 检查是否是有效的文件路径
        if(!is_file($mtuser->session_path) && !is_dir($mtuser->session_path)) {
            return [
                'exists' => false,
                'message' => '账号异常：session_path路径无效'
            ];
        }
        
        return [
            'exists' => true,
            'message' => 'session_path路径正常'
        ];
    }
}

