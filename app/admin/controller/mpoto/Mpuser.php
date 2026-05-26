<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mpuser as MpuserModel;
use app\admin\model\Monitorphone as MonitorphoneModel;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
class Mpuser extends Admin {


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
			$where['username|user_id'] = $this->request->post('username', '', 'serach_in');
		
            $phoneid = $this->request->post('phone', '', 'serach_in');
            if($phoneid){
                $phone=MonitorphoneModel::where('id',$phoneid)->value('phone');
                $where['phone'] =$phone;
            }
            $where['is_pushed'] = $this->request->post('is_pushed', '', 'serach_in');
			//$create_time = $this->request->post('create_time', '', 'serach_in');
			//$where['create_time'] = ['between',[strtotime($create_time[0]),strtotime($create_time[1])]];


			$res = MpuserModel::where(formatWhere($where))->order('id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

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
		
		 // 缓存 key 根据 ID 生成
    $cacheKey = 'telegram_group_' . $data['id'];

    // 从缓存获取数据
    $cachedGroup = Cache::get($cacheKey);

    // 数据库查询
    $rs = MpuserModel::find($data['id']);
    if (!$rs) {
        throw new ValidateException('未找到对应的群组');
    }

    // 如果缓存不存在或者数据有变化，继续处理
    if (!$cachedGroup || $cachedGroup['status'] != $data['status']) {
        // 根据 status 进行 Webhook 处理
        $botToken = TelegrambotModel::where('bot_id',$rs['bot_id'])->value('bot_token');
        if ($data['status'] == 1 && $botToken) {
            // 禁用群组
           $content = array(
                'chat_id' => $rs['group_id'],
                'permissions' => json_encode(array(
                    'can_send_messages' => false,  // 禁止机器人发送消息
                )),
            );
            
            send($botToken, 'restrictChatMember', $content);
            $msg='已禁用该群机器人消息权限';
        } else {
            // 启用群组
            $content = array(
                'chat_id' => $rs['group_id'],
                'permissions' => json_encode(array(
                    'can_send_messages' => true,  // 恢复机器人发送消息权限
                )),
            );
            
            send($botToken, 'restrictChatMember', $content);
            $msg='已恢复机器人消息权限';
        }

        // 更新数据库记录
        try {
            MpuserModel::update($data);

            // 更新缓存
            $rs['status'] = $data['status']; // 更新缓存中的 status 字段
            Cache::set($cacheKey, $rs, 3600); // 缓存有效期设置为 1 小时

            // 返回成功响应
            return json(['status' => 200, 'msg' => $msg]);
        } catch (\Exception $e) {
            throw new ValidateException($e->getMessage());
        }
    } else {
        // 如果缓存存在且状态未变，则不更新数据库，直接返回成功
        return json(['status' => 200, 'msg' => '无需更新，数据未变动']);
    }
	
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,title,group_id,description,content,group_image,status,create_time,bot_id';
		$data = $this->request->only(explode(',',$postField),'post',null);
		
		$data['create_time'] = strtotime($data['create_time']);
		
        $originalData = MpuserModel::find($data['id']);
        $bot=TelegrambotModel::where('bot_id',$data['bot_id'])->find();
        $groupNameUpdated = ($originalData['title'] !== $data['title']);
        $groupImageUpdated =true;// ($originalData['group_image'] !== $data['group_image']);
        $groupDesUpdated = ($originalData['description'] !== $data['description']);
		try{
			MpuserModel::update($data);
			if($bot['bot_token']){
			    log::write($bot['bot_token']);
    			if($groupNameUpdated){
    			    $content = array(
    			     'chat_id'=>$data['group_id'],
                     'title' => $data['title'],
                     );
                    send($bot['bot_token'],'setChatTitle', $content);
                    
    			}
    			
    			if($groupDesUpdated){
    			    $content = array(
    			     'chat_id'=>$data['group_id'],
                     'description' => $data['description'],
                     );
                    send($bot['bot_token'],'setChatDescription', $content);
    			}
    			
    			if($groupImageUpdated){
    			    $tx = $_SERVER['DOCUMENT_ROOT'] .$data['group_image'];
    			    $realImagePath = realpath($tx);
    			    $content = array(
    			        'chat_id'=>$data['group_id'],
                        'photo' => new \CURLFile($realImagePath),
                     );
                    $rs=sendPhoto($bot['bot_token'],'setChatPhoto', $content);
                    log::write($rs);
    			}
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
		
		$res = MpuserModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('id', '', 'serach_in');
		//log::info($idx);
		if(!$idx) throw new ValidateException ('参数错误');
		MpuserModel::destroy(['id'=>explode(',',$idx)],true);
		return json(['status'=>200,'msg'=>'操作成功']);
	}
    
    
    function groupmessagedel(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		
		$res = MpuserModel::find($idx);
		
		$data['group_id']=$res['group_id'];
		$data['token']=TelegrambotModel::where('bot_id',$res['bot_id'])->value('bot_token');
    		        //处理关键词
    	 Queue::push('app\job\GroupMessageDelJob', $data);
		
		return json(['status'=>200,'msg'=>'指令已提交，系统处理中']);
	}
    
	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MpuserModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = MpuserModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

	/*
 	* @Description  导出
 	*/
	function dumpdata(){
		$page = $this->request->post('page', 1, 'intval');
		$limit = config('my.dumpsize') ? config('my.dumpsize') : 1000;

		$where = [];
		$where['id'] = ['in',$this->request->post('id', '', 'serach_in')];
		$where['username'] = $this->request->post('username', '', 'serach_in');
         $phoneid = $this->request->post('phone', '', 'serach_in');
            if($phoneid){
                $phone=MonitorphoneModel::where('id',$phoneid)->value('phone');
                $where['phone'] =$phone;
            }

		$field = 'id,user_id,username,create_time';

		$res = MpuserModel::where(formatWhere($where))->field($field)->order('id desc')->limit(($page-1)*$limit,$limit)->select()->toArray();

		foreach($res as $key=>$val){
			$res[$key]['create_time'] = date('Y-m-d H:i:s',$val['create_time']);
	
		}

		$data['status'] = 200;
		$data['header'] = explode(',','编号,userID,账号,创建时间');
		$data['percentage'] = ceil($page * 100/ceil(MpuserModel::where(formatWhere($where))->count()/$limit));
		$data['filename'] = 'userID导出.'.config('my.dump_extension');
		$data['data'] = $res;
		return json($data);
	}

    
    // 一键删除好友
    public function deleteFriends()
    {
        $accountId = input('post.account_id');
        // 获取账号信息
        // $account = AccountModel::find($accountId);
        
        try {
            // 初始化OpenTele客户端
            $client = $this->initOpenTeleClient($accountId);
            
            // 获取好友列表
            $friends = $client->getContacts();
            
            // 删除好友
            foreach ($friends as $friend) {
                $client->deleteContact($friend['id']);
            }
            
            return json(['code' => 1, 'msg' => '删除好友成功']);
        } catch (ApiException $e) {
            Log::error('删除好友失败: ' . $e->getMessage());
            return json(['code' => 0, 'msg' => '删除好友失败: ' . $e->getMessage()]);
        }
    }
    
    // 一键退出群聊
    public function leaveGroups()
    {
        $accountId = input('post.account_id');
        
        try {
            $client = $this->initOpenTeleClient($accountId);
            
            // 获取群聊列表
            $groups = $client->getDialogs(['type' => 'group']);
            
            // 退出群聊
            foreach ($groups as $group) {
                $client->leaveChat($group['id']);
            }
            
            return json(['code' => 1, 'msg' => '退出群聊成功']);
        } catch (ApiException $e) {
            Log::error('退出群聊失败: ' . $e->getMessage());
            return json(['code' => 0, 'msg' => '退出群聊失败: ' . $e->getMessage()]);
        }
    }
    
    // 检测账号
    public function checkAccount()
    {
        $accountId = input('post.account_id');
        
        try {
            $client = $this->initOpenTeleClient($accountId);
            
            // 检查账号是否在线
            $isOnline = $client->isUserOnline();
            
            // 检查账号是否被限制
            $isRestricted = $client->isUserRestricted();
            
            // 检查账号是否被封禁
            $isBanned = $client->isUserBanned();
            
            $result = [
                'is_online' => $isOnline,
                'is_restricted' => $isRestricted,
                'is_banned' => $isBanned
            ];
            
            return json(['code' => 1, 'msg' => '账号检测完成', 'data' => $result]);
        } catch (ApiException $e) {
            Log::error('账号检测失败: ' . $e->getMessage());
            return json(['code' => 0, 'msg' => '账号检测失败: ' . $e->getMessage()]);
        }
    }
    
    // 退出其他设备
    public function logoutOtherDevices()
    {
        $accountId = input('post.account_id');
        
        try {
            $client = $this->initOpenTeleClient($accountId);
            
            // 获取当前登录设备列表
            $devices = $client->getAuthorizations();
            
            // 退出其他设备
            foreach ($devices as $device) {
                if (!$device['current']) {
                    $client->revokeAuthorization($device['hash']);
                }
            }
            
            return json(['code' => 1, 'msg' => '退出其他设备成功']);
        } catch (ApiException $e) {
            Log::error('退出其他设备失败: ' . $e->getMessage());
            return json(['code' => 0, 'msg' => '退出其他设备失败: ' . $e->getMessage()]);
        }
    }
    
    // 检查账号异常
    public function checkAbnormalities()
    {
        $accountId = input('post.account_id');
        
        try {
            $client = $this->initOpenTeleClient($accountId);
            
            // 检查账号是否有异常登录
            $abnormalLogins = $this->checkAbnormalLogins($client);
            
            // 检查账号是否有异常活动
            $abnormalActivities = $this->checkAbnormalActivities($client);
            
            $result = [
                'abnormal_logins' => $abnormalLogins,
                'abnormal_activities' => $abnormalActivities
            ];
            
            return json(['code' => 1, 'msg' => '账号异常检查完成', 'data' => $result]);
        } catch (ApiException $e) {
            Log::error('账号异常检查失败: ' . $e->getMessage());
            return json(['code' => 0, 'msg' => '账号异常检查失败: ' . $e->getMessage()]);
        }
    }
    
    // 检查异常登录
    private function checkAbnormalLogins($client)
    {
        // 获取登录历史
        $loginHistory = $client->getLoginHistory();
        
        // 简单的异常检测逻辑
        $abnormal = [];
        foreach ($loginHistory as $login) {
            // 假设IP地址频繁变化或来自不同国家为异常
            if (time() - strtotime($login['date']) < 86400) {
                // 一天内的登录
                // 这里需要更复杂的逻辑来判断异常
                $abnormal[] = $login;
            }
        }
        
        return $abnormal;
    }
    
    // 检查异常活动
    private function checkAbnormalActivities($client)
    {
        // 获取最近的消息历史
        $messages = $client->getHistory('me', 100);
        
        // 简单的异常检测逻辑
        $abnormal = [];
        $messageCount = count($messages);
        if ($messageCount > 50) {
            // 短时间内发送大量消息可能是异常
            $abnormal[] = '短时间内发送大量消息';
        }
        
        return $abnormal;
    }
    
    // 发送任务
    public function sendTask()
    {
        $accountId = input('post.account_id');
        $taskType = input('post.task_type');
        $taskData = input('post.task_data', []);
        
        try {
            $client = $this->initOpenTeleClient($accountId);
            
            switch ($taskType) {
                case 'send_message':
                    $this->sendMessageTask($client, $taskData);
                    break;
                case 'add_contact':
                    $this->addContactTask($client, $taskData);
                    break;
                case 'join_chat':
                    $this->joinChatTask($client, $taskData);
                    break;
                default:
                    throw new \Exception('未知任务类型');
            }
            
            return json(['code' => 1, 'msg' => '任务发送成功']);
        } catch (ApiException $e) {
            Log::error('任务发送失败: ' . $e->getMessage());
            return json(['code' => 0, 'msg' => '任务发送失败: ' . $e->getMessage()]);
        }
    }
    
    // 发送消息任务
    private function sendMessageTask($client, $taskData)
    {
        $chatId = $taskData['chat_id'];
        $message = $taskData['message'];
        
        $client->sendMessage($chatId, $message);
    }
    
    // 添加联系人任务
    private function addContactTask($client, $taskData)
    {
        $phone = $taskData['phone'];
        $firstName = $taskData['first_name'];
        $lastName = $taskData['last_name'] ?? '';
        
        $client->addContact($phone, $firstName, $lastName);
    }
    
    // 加入群聊任务
    private function joinChatTask($client, $taskData)
    {
        $inviteLink = $taskData['invite_link'];
        
        $client->joinChatByLink($inviteLink);
    }
    
    // 初始化OpenTele客户端
    private function initOpenTeleClient($accountId)
    {
        // 获取账号信息
        // $account = AccountModel::find($accountId);
        
        // 这里需要根据实际情况初始化OpenTele客户端
        $client = new Client();
        // $client->setTDataPath($account['tdata_path']);
        // $client->setApiId(config('telegram.api_id'));
        // $client->setApiHash(config('telegram.api_hash'));
        
        return $client;
    }

}

