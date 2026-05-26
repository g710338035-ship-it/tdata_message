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
use ZipArchive;
use think\Response;
class Mtuser extends Admin {

    private $redis;

  
    public function initialize(){
		parent::initialize();
		$this->redis = Cache::store('redis')->handler();
	}
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
			$where['Mtuser.nickName|Mtuser.uuid|Mtuser.id'] = $this->request->post('nickName', '', 'serach_in');
			$where['Mtuser.status'] = $this->request->post('status', '', 'serach_in');
			$where['Mtuser.online'] = $this->request->post('online', '', 'serach_in');
			$where['Mtuser.archive'] =1;
			$where['Mtuser.customid']=$this->request->post('customid', '', 'serach_in');
			$where['Mtuser.cateid']=$this->request->post('cateid', '', 'serach_in');
			
			$where['Mtuser.account_status']=$this->request->post('account_status', '', 'serach_in');
			$where['Mtuser.account_status_desc']=$this->request->post('account_status_desc', '', 'serach_in');
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
		$postField = 'id,tdata_path,session_path,proxyip,current_password,new_password,first_name,last_name,username,bio,avatar,operate_type,last_api_address';
		$data = $this->request->only(explode(',',$postField),'post',null);
		//log::info($data['proxyip']);
		//return json(['status'=>200,'msg'=>'操作成功']);
		if(empty($data['id'])){
			throw new ValidateException('参数错误：缺少会员ID');
		}
		
		// 获取会员信息
		$mtuser = MtuserModel::find($data['id']);
		if(empty($mtuser)){
			throw new ValidateException('会员不存在');
			return json(['status'=>401,'msg'=>$msg]);
		}
		
		// 初始化Telegram服务
		$telegramService = new TelegramTdata();
		$tdataPath = $mtuser['tdata_path'];
		$sessionPath=$mtuser['session_path'];
		$ipAdress=$mtuser['last_api_address']??'';
		if(empty($sessionPath)|| !file_exists($sessionPath)){
			//throw new ValidateException('缺少tdata路径信息，无法操作Telegram账号');
			return json(['status'=>400,'msg'=>'缺少tdata路径信息，无法操作Telegram账号']);
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
					$ipAdress,
					$sessionPath,
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
					$ipAdress,
					$sessionPath,
					$data['current_password'],
					$data['new_password'],
					$data['proxyip'] ?? null,
				);
			}elseif(!empty($data['first_name'])&&$data['operate_type']=='nickname'){
				$result =$telegramService->updateNickname(
					$tdataPath,
					$ipAdress,
					$sessionPath,
					$data['first_name'],
					$data['last_name'] ?? '',
					$data['proxyip'] ?? null,
				
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
					$ipAdress,
					$sessionPath,
					$data['username'],
					$data['proxyip'] ?? null,
					
				);
				
				if($result['status']){
					MtuserModel::where('id', $data['id'])->update([
						'username' => $data['username']
					]);
				}
			}elseif(isset($data['bio'])&&$data['operate_type']=='bio'){
				$result =$telegramService->updateBio(
					$tdataPath,
					$ipAdress,
					$sessionPath,
					$data['bio'],
					$data['proxyip'] ?? null,
				
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
    * @Description  批量修改昵称
    */
    public function upnick()
    {
        if (!$this->request->isPost()) {
            throw new ValidateException('请求方式错误');
        }

        $post = $this->request->post();
        $id =  $this->request->post('id', '', 'serach_in');
        $file = $post['file'] ?? '';
        
        if (empty($id)) {
            throw new ValidateException('请选择账户');
        }

        if (empty($file)) {
            throw new ValidateException('请选择昵称文件');
        }

        // 1. 读取昵称文件
        $nicknames = $this->readNicknameFile($file);
        if (empty($nicknames)) {
            throw new ValidateException('昵称文件内容为空或读取失败');
        }

        // 2. 将ID字符串转换为数组
        $idArr = is_array($id) ? $id : explode(',', $id);
        $idArr = array_filter(array_map('intval', $idArr)); // 过滤并转换为整数
        
        if (empty($idArr)) {
            throw new ValidateException('请选择有效的账户ID');
        }
    
        // 3. 获取指定ID的账户
        $accounts = MtuserModel::where([
            ['id', 'in', $idArr],
            ['archive', '=', 1],
            ['status', '=', 1],  // 只选择正常状态的账户
            ['account_status', '=', '正常']
        ])->select()->toArray();

        if (empty($accounts)) {
            throw new ValidateException('该分组下没有可用的账户');
        }
        // 过滤无效账户并构建任务数据
        $tasksData = [];
        $invalidAccounts = [];
        $nicknameIndex = 0;
        $totalNicknames = count($nicknames);
        
        // 如果昵称数量少于账户数量，则预先打乱昵称数组以便随机选取
        if ($totalNicknames < count($accounts)) {
            shuffle($nicknames);
        }
        
        $nicknameIndex = 0;
        
        
        foreach ($accounts as $account) {
            // 检查session文件是否存在
            if (empty($account['session_path']) || !file_exists($account['session_path'])) {
               
                continue;
            }

            // 检查代理是否存在
            if (empty($account['proxyip'])) {
                
                continue;
            }

            // 检查账户状态
            if ($account['last_api_address'] == '') {                
                continue;
            }
            // 分配昵称
            // 如果昵称数量足够，按顺序分配
            if ($nicknameIndex < $totalNicknames) {
                $nickname = $nicknames[$nicknameIndex];
                $nicknameIndex++;
            } else {
                // 如果昵称数量不足，随机从已有的昵称中选取
                $randomIndex = rand(0, $totalNicknames - 1);
                $nickname = $nicknames[$randomIndex];
            }
            // 准备任务数据
            $tasksData[] = [
                'user_id' => $account['id'],
                'account' => $account['account'],
                'tguser_id' => $account['uuid'],
                'session_path' => $account['session_path'],
                'tdata_path' => $account['tdata_path'],
                'proxyip' => $account['proxyip'],
                'port'=>$account['api_port'],
                'last_api_address' => $account['last_api_address'] ?? '',            
                'first_name' => $nickname
            ];
        }
        
        $total = count($tasksData);
        if ($total === 0) {
            throw new ValidateException('没有符合条件的账户需要处理');
        }
        
        // 创建任务批次
        $batchId = uniqid('nickname_batch_', true);       
       
        
        // 按批次大小拆分任务
        $batchSize = config('telegram.batch_size');
        $taskBatches = array_chunk($tasksData, $batchSize);
       
        // 批量投递任务到队列
        foreach ($taskBatches as $taskBatch) {
            Queue::push(TelegramTask::class, [
                'batch_id' => $batchId,
                'task_type' => 'update_nickname',
                'tasks' => $taskBatch
            ], 'telegram');
        }
   

        return json([
            'status' => 200,
            'msg' => "任务已提交到队列，共{$total}个账户，{$totalNicknames}个昵称",
            'batch_id' => $batchId,
            'total' => $total
        ]);
    
    }
   
   /*
    * @Description  读取昵称文件
    */
    private function readNicknameFile($filePath)
    {
        //if (strpos($filePath, 'uploads/') === 0) {
            // 转换为绝对路径
        $filePath = root_path() . 'public' . $filePath;
       
        log::info($filePath);
        if (!file_exists($filePath)) {
            throw new ValidateException('昵称文件不存在');
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ValidateException('读取昵称文件失败');
        }

        // 按行分割，过滤空行和空白字符
        $lines = explode("\n", $content);
        $nicknames = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $nicknames[] = $line;
            }
        }

        return $nicknames;
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
	
	function delweb() {
        $id = $this->request->post('id', '', 'serach_in');
     
		$data['web_key_dcid'] = '';
		$data['web_key_hex'] = '';
		$res = MtuserModel::where(['id'=> $id])->update($data);
	
		
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
            if($status === '正常'||$status === '退出'||$status === '异常'||$status === '代理异常'||$status === '登录成功') {
                $validUserIds[] = $userId;
            }
        }
        
        // 4. 如果没有符合条件的用户，直接返回成功（或者可以根据需求返回提示）
        if(empty($validUserIds)) {
            return json(['status' => 200, 'msg' => '没有启动任何账号']);
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
    
        // 构建批量任务数据
        $tasksData = [];
        $yesInUsers=[];
        $noProxyUsers = []; 
        $loggingInUsers = [];
        $errerUsers=[];
        foreach ($mtusers as $mtuser) {
            
            if ($mtuser['account_status_desc'] != '账户解析成功'&&$mtuser['account_status'] === '正常') {
                $yesInUsers[] = $mtuser['id'];
                continue;
            }
            
            if ($mtuser['account_status_desc'] === '登录中') {
                $loggingInUsers[] = $mtuser['id'];
                continue;
            }
            
            if ($mtuser['account_status'] === '未授权'||$mtuser['account_status'] === '空号'||$mtuser['account_status'] === '注销'||$mtuser['account_status'] === '封号') {
                $errerUsers[] = $mtuser['id'];
                continue;
            }
            
            /*if (empty($mtuser['proxyip'])) {
                $noProxyUsers[] = $mtuser['id'];
                continue;
            }*/
            $tasksData[] = [
                'user_id' => $mtuser['id'],
                'session_path' => $mtuser->session_path,
                'tdata_path' => $mtuser->tdata_path,
                'proxyip' => $mtuser->proxyip,
                'tguser_id'=> $mtuser->uuid,
                'main_dc_id'=> $mtuser->main_dc_id,
                'auth_key_hex'=> $mtuser->auth_key,
                'port'=>$mtuser->api_port,
                'last_api_address'=>$mtuser->last_api_address
            ];
        }
    
        // 处理无代理账号
        if (!empty($noProxyUsers)) {
            MtuserModel::whereIn('id', $noProxyUsers)->update([
                'account_status' => '异常',
                'account_status_desc' => '代理信息错误',
                'online' => 0,
                'updatetime' => time(),
            ]);
            Log::warning('[accountup] 以下用户代理信息缺失: ' . implode(',', $noProxyUsers));
        }
    
        $total=0;
        $batchId = null;
        $msgList = [];
        // 有代理的用户才入队
        if (!empty($tasksData)) {
            // 1. 防重复投递：生成唯一批次ID并通过Redis setnx锁定
            $batchId = uniqid('batch_', true);
            $batchExistsKey = "batch_exists:{$batchId}";
            if (!$this->redis->setnx($batchExistsKey, 1)) {
                throw new \Exception("批次已存在，避免重复处理：{$batchId}");
            }
            $this->redis->expire($batchExistsKey, 600); // 批次标记有效期10分钟
    
            // 2. 初始化任务进度缓存
            $total = count($tasksData);
            $this->redis->hMSet("task_{$batchId}_progress", [
                'total' => $total,
                'completed' => 0,
                'success' => 0,
                'failed' => 0,
                'status' => 'processing',
                'start_time' => time(),
            ]);
            $this->redis->expire("task_{$batchId}_progress", 3600); // 进度缓存有效期1小时
    
            // 3. 拆分任务为子批次（控制并发）
            $batchSize = Config('telegram.batch_size');
            $taskBatches = array_chunk($tasksData, $batchSize, true);
            $totalChunks = count($taskBatches);
            $startTime = microtime(true);
            // 4. Redis管道：减少IO操作，批量记录子批次信息
            $pipe = $this->redis->multi(\Redis::PIPELINE);
            foreach ($taskBatches as $chunkIndex =>  $taskBatch) {
                $chunkSize = count($taskBatch);
                $taskData = [
                    'batch_id' => $batchId,
                    'task_type' => 'set_online',
                    'tasks' => $taskBatch
                ];
    
                // 延迟投递：避免瞬间并发过高
                $delay = 0.1;
                $taskId = Queue::later($delay, TelegramTask::class, $taskData, 'telegram');
                if ($taskId === false) {
                    throw new \Exception("子批次投递失败（队列异常）：{$subBatchId}");
                }
    
    
            }
            $costTime = round(microtime(true) - $startTime, 2);
            Log::info("[telegram队列] 批次:  耗时={$costTime}秒");
            $pipe->exec();
    
            // 更新任务状态为“已入队”
            $this->redis->hSet("task_{$batchId}_progress", 'status', 'queued');
    
            // 更新账号状态为“登录中”
            $validUserIds = array_column($tasksData, 'user_id');
            MtuserModel::whereIn('id', $validUserIds)->update([
                'account_status_desc' => '登录中',
                'updatetime' => time(),
                'loginnum' => Db::raw('loginnum + 1'),
            ]);
    
            $msgList[] = '已提交 ' . $total . ' 个账户上线任务';
        }
        if(!empty($yesInUsers)){
            $msgList[] = '以下账户已在登录中，已自动跳过：' . count($yesInUsers);
        }
        // 组装返回消息
        if (!empty($loggingInUsers)) {
            $msgList[] = '以下账户已在登录中，已自动跳过：' . count($loggingInUsers);
        }
        if (!empty($errerUsers)) {
            $msgList[] = '以下账户未授权、空号、注销、封号，已自动跳过：' . count($errerUsers);
        }
        if (!empty($noProxyUsers)) {
            $msgList[] = '以下账户代理配置错误，已标记为异常：' . count($noProxyUsers);
        }
        if (empty($tasksData)) {
            $msgList[] = '没有可执行的账户';
        }
    
        return json([
            'status'   => 200,
            'msg'      => implode('；', $msgList),
            'batch_id' => $batchId,
            'total'    => $total,
        ]);
    }
    
    //上线
    /*public function accountup(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        
        // 构建批量任务数据
        $tasksData = [];
        $noProxyUsers = []; 
        $loggingInUsers = [];
        foreach ($mtusers as $mtuser) {
            
           * if ($mtuser['account_status_desc'] === '登录中') {
                $loggingInUsers[] = $mtuser['id'];
                continue;
            }
            if (empty($mtuser['proxyip'])) {
                $noProxyUsers[] = $mtuser['id'];
                continue;
            }
            $tasksData[] = [
                'user_id' => $mtuser['id'],
                'session_path' => $mtuser->session_path,
                'tdata_path' => $mtuser->tdata_path,
                'proxyip' => $mtuser->proxyip,
                'tguser_id'=> $mtuser->uuid,
                'main_dc_id'=> $mtuser->main_dc_id,
                'auth_key_hex'=> $mtuser->auth_key,
            ];
        }
        
        // 如果有未配置代理的账号，更新数据库状态
        if (!empty($noProxyUsers)) {
            MtuserModel::whereIn('id', $noProxyUsers)->update([
                'account_status' => '异常',
                'account_status_desc' => '代理信息错误',
                'online' => 0,
                'updatetime' => time(),
            ]);
            Log::warning('[accountup] 以下用户代理信息缺失: ' . implode(',', $noProxyUsers));
        }
        $total=0;
        $batchId = null;
        $msgList = [];
        // 有代理的用户才入队
        if (!empty($tasksData)) {
           // 创建任务批次ID
            $batchId = uniqid('batch_', true);
            $total = count($tasksData);
            
            $batchExistsKey = "batch_exists:{$batchId}";
        
            // 关键：用Redis的setnx确保批次唯一（避免重复投递）
            if (!$this->redis->setnx($batchExistsKey, 1)) {
                throw new \Exception("批次已存在，避免重复处理：{$batchId}");
            }
            $this->redis->expire($batchExistsKey, 600); // 批次标记有效期24小时
            
            // 初始化进度缓存
           
            $this->redis->hMSet("task_{$batchId}_progress", [
                'total' => $total,
                'completed' => 0,
                'success' => 0,
                'failed' => 0,
                'status' => 'processing',
                'start_time' => time(),
                'chunk_count' => 0 // 子批次数量（后续更新）
            ]);
            $this->redis->expire("task_{$batchId}_progress", 3600);
            // 按批次大小拆分任务
            $batchSize = 2;
            $taskBatches = array_chunk($tasksData, $batchSize, true);
            $totalChunks = count($taskBatches);
            
            //log::info(json_encode($totalChunks));
            // 优化点2：使用Redis管道减少IO操作
            $pipe = $this->redis->multi(\Redis::PIPELINE);
            // 批量投递任务到队列
            foreach ($taskBatches as $chunkIndex =>  $taskBatch) {
                
                $chunkSize = count($taskBatch);
                $subBatchId = "{$batchId}_sub_{$chunkIndex}";
                
                $taskData = [
                   'batch_id' => $batchId,
                    'task_type' => 'set_online',
                    'tasks' => $taskBatch,
                    'create_time' => time(),
                  
                    'sub_batch_id' => $subBatchId, // 子批次唯一ID
                    'chunk_index' => $chunkIndex,  // 子批次索引（便于日志追踪）
                ];
                $delay = $chunkIndex * 1;
                //Queue::push(TelegramTask::class,$taskData, 'telegram');
                $taskId = Queue::later($delay, TelegramTask::class, $taskData, 'telegram');
                if ($taskId === false) {
                    throw new \Exception("子批次投递失败（队列异常）");
                }
                
                // 管道记录子批次信息（用于追踪和重试）
                $pipe->hSet("task_{$batchId}_subs", $chunkIndex, json_encode([
                    'sub_batch_id' => $subBatchId,
                    'task_id' => $taskId,
                    'account_count' => $chunkSize,
                    'delay' => $delay,
                    'status' => 'queued',
                    'create_time' => time()
                ]));

               
                Log::info("批次{$batchId}：子批次{$chunkIndex}（{$chunkSize}个账号）投递成功，任务ID：{$taskId}");
            }
            // 执行Redis管道命令
            $pipe->hSet("task_{$batchId}_progress", 'chunk_count', $totalChunks);
            $pipe->exec();
            
            $this->redis->hSet("task_{$batchId}_progress", 'status', 'queued');
            // 更新状态为登录中
            $validUserIds = array_column($tasksData, 'user_id');
            MtuserModel::whereIn('id', $validUserIds)->update([
                'account_status_desc' => '登录中',
                'updatetime' => time(),
            ]);
            $msgList[] = '已提交 ' . $total . ' 个账户上线任务';
        }
        if (!empty($loggingInUsers)) {
            $msgList[] = '以下账户已在登录中，已自动跳过：' . count($loggingInUsers);
        }
    
        if (!empty($noProxyUsers)) {
            $msgList[] = '以下账户代理配置错误，已标记为异常：' . count($noProxyUsers);
        }
    
        if (empty($tasksData)) {
            $msgList[] = '没有可执行的账户';
        }
    
        return json([
            'status'   => 200,
            'msg'      => implode('；', $msgList),
            'batch_id' => $batchId,
            'total'    => $total,
        ]);
	}*/
	
	//检测
    public function checkAccount(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        
        

        // 构建批量任务数据
        $total=0;
        $tasksData = [];
        $invalidSessions = [];
        $errerUsers=[];
        foreach ($mtusers as $mtuser) {
            $sessionPath = $mtuser->session_path;
            
            // 检查 session 文件是否存在
            if (empty($sessionPath) || !file_exists($sessionPath)) {
                $invalidSessions[] = $mtuser['id'];
                continue;
            }else{
                if($mtuser['account_status'] === '正常'){
                    $tasksData[] = [
                        'user_id' => $mtuser['id'],
                        'tguser_id'=> $mtuser->uuid,
                        'session_path' => $mtuser->session_path,
                        'tdata_path' => $mtuser->tdata_path,
                        'proxyip' => $mtuser->proxyip,
                        'port'=>$mtuser->api_port,
                        'last_api_address'=>$mtuser->last_api_address,
                    ];
                }else{
                    $errerUsers[] = $mtuser['id'];
                    continue;
                }
            }
        }
        // === 更新无效 session 的账号状态 ===
        if (!empty($invalidSessions)) {
            MtuserModel::whereIn('id', $invalidSessions)->update([
                'account_status' => '异常',
                'account_status_desc' => '请先登录，或者代理不存在',
                'online' => 0,
                'updatetime' => time(),
            ]);
            Log::warning('[checkAccount] 以下账号 session 不存在: ' . implode(',', $invalidSessions));
        }
        if (!empty($tasksData)) {
            // 创建任务批次ID
            $batchId = uniqid('batch_', true);
            $total = count($tasksData);
            
            // 初始化进度缓存
            
            $this->redis->hMSet("task_{$batchId}_progress", [
                'total' => $total,
                'completed' => 0,
                'success' => 0,
                'failed' => 0,
                'status' => 'processing',
                'start_time' => time()
            ]);
            $this->redis->expire("task_{$batchId}_progress", 3600);
            // 按批次大小拆分任务
            $batchSize = config('telegram.batch_size');
            $taskBatches = array_chunk($tasksData, $batchSize);
            
            // 批量投递任务到队列
            foreach ($taskBatches as $taskBatch) {
                Queue::push(TelegramTask::class, [
                    'batch_id' => $batchId,
                    'task_type' => 'get_account_info',
                    'tasks' => $taskBatch
                ], 'telegram');
            }
            $msg='操作已提交，正在处理'.$total.'个账户';
        }else{
            $msg="没有账户需要处理";
        }
        return json([
            'status' => 200, 
            'msg' => $msg,
            'batch_id' => $batchId,
            'total' => $total
        ]);
	}
	
	//删除好友逻辑
	public function deleteFriends(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        

        // 构建批量任务数据
        $total=0;
        $tasksData = [];
        $invalidSessions = [];
        $errerUsers=[];
        foreach ($mtusers as $mtuser) {
            $sessionPath = $mtuser->session_path;    
            // 检查 session 文件是否存在
            if (empty($sessionPath) || !file_exists($sessionPath)) {
                $invalidSessions[] = $mtuser['id'];
                continue;
            }else{
                if($mtuser['account_status'] === '正常'){
                    $tasksData[] = [
                        'user_id' => $mtuser['id'],
                        'tguser_id'=> $mtuser->uuid,
                        'session_path' => $mtuser->session_path,
                        'tdata_path' => $mtuser->tdata_path,
                        'proxyip' => $mtuser->proxyip,
                        'port'=>$mtuser->api_port,
                        'last_api_address'=>$mtuser->last_api_address
                    ];
                }else{
                    $errerUsers[] = $mtuser['id'];
                    continue;
                }
            }
        }
        // === 更新无效 session 的账号状态 ===
        if (!empty($invalidSessions)) {
            MtuserModel::whereIn('id', $invalidSessions)->update([
                'account_status' => '异常',
                'account_status_desc' => '请先登录',
                'online' => 0,
                'updatetime' => time(),
            ]);
            Log::warning('[checkAccount] 以下账号 session 不存在: ' . implode(',', $invalidSessions));
        }
        if (!empty($tasksData)) {
        
            $batchId = uniqid('batch_', true);
            $total = count($tasksData);
            
            // 初始化进度缓存
           
            $this->redis->hMSet("task_{$batchId}_progress", [
                'total' => $total,
                'completed' => 0,
                'success' => 0,
                'failed' => 0,
                'status' => 'processing',
                'start_time' => time()
            ]);
            $this->redis->expire("task_{$batchId}_progress", 3600);
            // 按批次大小拆分任务
            $batchSize = config('telegram.batch_size');
            $taskBatches = array_chunk($tasksData, $batchSize);
            
            // 批量投递任务到队列
            foreach ($taskBatches as $taskBatch) {
                Queue::push(TelegramTask::class, [
                    'batch_id' => $batchId,
                    'task_type' => 'delete_all_contacts',
                    'tasks' => $taskBatch
                ], 'telegram');
            }
            $msg='操作已提交，正在处理'.$total.'个账户';
        }else{
            $msg="没有账户需要处理";
        }
        return json([
            'status' => 200, 
            'msg' => $msg,
            'batch_id' => $batchId,
            'total' => $total
        ]);
	}
	
	//退出群聊逻辑
	public function exitGroups()
    {
        $idx = $this->request->post('id', [], 'serach_in');
        if (empty($idx)) {
            throw new ValidateException('参数错误');
        }
    
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
        if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
    
        $tasksData        = [];
        $invalidSessions  = [];
        $invalidStatus    = [];
        $accountIds       = [];
    
        foreach ($mtusers as $mtuser) {
    
            // session / proxy 校验
            if (
                empty($mtuser->proxyip) ||
                empty($mtuser->session_path) ||
                !file_exists($mtuser->session_path)
            ) {
                $invalidSessions[] = $mtuser->id;
                continue;
            }
    
            // 状态校验
            if ($mtuser->account_status !== '正常') {
                $invalidStatus[] = $mtuser->id;
                continue;
            }
    
            $tasksData[] = [
                'user_id'           => $mtuser->id,
                'tguser_id'         => $mtuser->uuid,
                'session_path'      => $mtuser->session_path,
                'tdata_path'        => $mtuser->tdata_path,
                'proxyip'           => $mtuser->proxyip,
                'port'              => $mtuser->api_port,
                'last_api_address'  => $mtuser->last_api_address,
            ];
    
            $accountIds[] = "temp_{$mtuser->uuid}.session";
        }
    
        /** ===== 无效 session 账号 ===== */
        if ($invalidSessions) {
            MtuserModel::whereIn('id', $invalidSessions)->update([
                'account_status'       => '异常',
                'account_status_desc'  => '请先登录',
                'online'               => 0,
                'updatetime'           => time(),
            ]);
        }
    
        /** ===== 批量清理历史群聊数据（一次 SQL）===== */
        if ($accountIds) {
            Db::name('tdmessages')
                ->where('chat_id', '<', 0)
                ->whereIn('account_id', $accountIds)
                ->delete();
    
            Db::name('tdchats')
                ->where('chat_type', '<>', 'private')
                ->whereIn('account_id', $accountIds)
                ->delete();
        }
    
        if (empty($tasksData)) {
            return json([
                'status'   => 200,
                'msg'      => '没有账户需要处理',
                'total'    => 0,
                'batch_id' => null
            ]);
        }
    
        /** ===== 初始化批量任务 ===== */
        $batchId = uniqid('batch_', true);
        $total   = count($tasksData);
    
        $this->redis->hSet("task_{$batchId}_progress", 'total', $total);
        $this->redis->hSet("task_{$batchId}_progress", 'completed', 0);
        $this->redis->hSet("task_{$batchId}_progress", 'success', 0);
        $this->redis->hSet("task_{$batchId}_progress", 'failed', 0);
        $this->redis->hSet("task_{$batchId}_progress", 'status', 'processing');
        $this->redis->hSet("task_{$batchId}_progress", 'start_time', time());
        $this->redis->expire("task_{$batchId}_progress", 3600);
    
        /** ===== 投递队列 ===== */
        $batchSize  = config('telegram.batch_size', 10);
        $taskChunks = array_chunk($tasksData, $batchSize);
    
        foreach ($taskChunks as $chunk) {
            Queue::push(TelegramTask::class, [
                'batch_id' => $batchId,
                'task_type'=> 'leave_all_groups',
                'tasks'    => $chunk
            ], 'telegram');
        }
    
        return json([
            'status'   => 200,
            'msg'      => "操作已提交，正在处理 {$total} 个账户",
            'total'    => $total,
            'batch_id' => $batchId
        ]);
    }

	
	//退出其他设备逻辑
	public function exitOtherDevices(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
  
        // 构建批量任务数据
        $total=0;
        $tasksData = [];
        $invalidSessions = [];
        $errerUsers=[];
        foreach ($mtusers as $mtuser) {
            $sessionPath = $mtuser->session_path;
    
            // 检查 session 文件是否存在
            if (empty($sessionPath) || !file_exists($sessionPath)) {
                $invalidSessions[] = $mtuser['id'];
                continue;
            }else{
                if($mtuser['account_status'] === '正常'){
                    $tasksData[] = [
                        'user_id' => $mtuser['id'],
                        'tguser_id'=> $mtuser->uuid,
                        'session_path' => $mtuser->session_path,
                        'tdata_path' => $mtuser->tdata_path,
                        'proxyip' => $mtuser->proxyip,
                        'port'=>$mtuser->api_port,
                        'last_api_address'=>$mtuser->last_api_address
                    ];
                }else{
                    $errerUsers[] = $mtuser['id'];
                    continue;
                }
            }
        }
        // === 更新无效 session 的账号状态 ===
        if (!empty($invalidSessions)) {
            MtuserModel::whereIn('id', $invalidSessions)->update([
                'account_status' => '异常',
                'account_status_desc' => '请先登录',
                'online' => 0,
                'updatetime' => time(),
            ]);
            Log::warning('[checkAccount] 以下账号 session 不存在: ' . implode(',', $invalidSessions));
        }
        if (!empty($tasksData)) {
            
            $batchId = uniqid('batch_', true);
            $total = count($tasksData);
            
            // 初始化进度缓存
            
            $this->redis->hMSet("task_{$batchId}_progress", [
                'total' => $total,
                'completed' => 0,
                'success' => 0,
                'failed' => 0,
                'status' => 'processing',
                'start_time' => time()
            ]);
            $this->redis->expire("task_{$batchId}_progress", 3600);
            // 按批次大小拆分任务
            $batchSize = config('telegram.batch_size');
            $taskBatches = array_chunk($tasksData, $batchSize);
            
            // 批量投递任务到队列
            foreach ($taskBatches as $taskBatch) {
                Queue::push(TelegramTask::class, [
                    'batch_id' => $batchId,
                    'task_type' => 'logout_other_sessions',
                    'tasks' => $taskBatch
                ], 'telegram');
            }
            $msg='操作已提交，正在处理'.$total.'个账户';
        }else{
            $msg="没有账户需要处理";
        }
        return json([
            'status' => 200, 
            'msg' => $msg,
            'batch_id' => $batchId,
            'total' => $total
        ]);
	}
	
	//下线
    public function accountdown(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		
		
        $mtusers = MtuserModel::whereIn('id', $idx)->select();
		
		if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }

        // 构建批量任务数据
        $total=0;
        $tasksData = [];
        $invalidSessions = [];
        foreach ($mtusers as $mtuser) {
            $sessionPath = $mtuser->session_path;
            $cacheKeyUser="tdata8:telegram_task:user:". $mtuser->id;
            $this->redis->delete($cacheKeyUser);
            // 检查 session 文件是否存在
            if (empty($sessionPath) || !file_exists($sessionPath)) {
                $invalidSessions[] = $mtuser['id'];
                continue;
            }
            $tasksData[] = [
                'user_id' => $mtuser['id'],
                'tguser_id'=> $mtuser->uuid,
                'session_path' => $mtuser->session_path,
                'tdata_path' => $mtuser->tdata_path,
                'proxyip' => $mtuser->proxyip,
                'port'=>$mtuser->api_port,
                'last_api_address'=>$mtuser->last_api_address
            ];
        }
        // === 更新无效 session 的账号状态 ===
        if (!empty($invalidSessions)) {
            MtuserModel::whereIn('id', $invalidSessions)->update([
                'account_status' => '退出',
                'account_status_desc' => '未登录',
                'online' => 0,
                'updatetime' => time(),
            ]);
            Log::warning('[checkAccount] 以下账号 session 不存在: ' . implode(',', $invalidSessions));
        }
        if (!empty($tasksData)) {
            
            $batchId = uniqid('batch_', true);
            $total = count($tasksData);
            
            // 初始化进度缓存
            
            $this->redis->hMSet("task_{$batchId}_progress", [
                'total' => $total,
                'completed' => 0,
                'success' => 0,
                'failed' => 0,
                'status' => 'processing',
                'start_time' => time()
            ]);
            $this->redis->expire("task_{$batchId}_progress", 3600);
            // 按批次大小拆分任务
            $batchSize = config('telegram.batch_size');
            $taskBatches = array_chunk($tasksData, $batchSize);
            
            // 批量投递任务到队列
            foreach ($taskBatches as $taskBatch) {
                Queue::push(TelegramTask::class, [
                    'batch_id' => $batchId,
                    'task_type' => 'set_offline',
                    'tasks' => $taskBatch
                ], 'telegram');
            }
         
            // 更新状态为登录中
            $validUserIds = array_column($tasksData, 'user_id');
            MtuserModel::whereIn('id', $validUserIds)->update([
                'online' => 0,
                'account_status_desc' => '退出中',
                'updatetime' => time(),
            ]);
            $msg='操作已提交，正在处理'.$total.'个账户';
        }else{
            $msg="没有账户需要处理";
        }
        return json([
            'status' => 200, 
            'msg' => $msg,
            'batch_id' => $batchId,
            'total' => $total
        ]);
	}
    /**
     * 获取任务进度
     */
    public function getTaskProgress() {
        $batchId = $this->request->post('batch_id', '', 'trim');
        if (empty($batchId)) {
            throw new ValidateException('参数错误：缺少批次ID');
        }
    
        $cacheKey = "task_{$batchId}_progress";
        
        if (!$this->redis->exists($cacheKey)) {
            return json([
                'status' => 404,
                'msg' => '任务不存在或已过期'
            ]);
        }
    
        $progress = $this->redis->hGetAll($cacheKey);
        
        // 新增：从缓存获取更新后的账号状态
        $updatedAccounts = $this->getUpdatedAccountsFromCache($batchId);
        
        // 将更新后的账号信息添加到返回数据中
        $progress['updated_accounts'] = $updatedAccounts;
    
        // 任务完成时清理缓存
        if (isset($progress['status']) && $progress['status'] === 'completed') {
            $this->cleanupTaskCache($batchId);
            $this->redis->del($cacheKey);
        }
    
        return json([
            'status' => 200,
            'data' => $progress
        ]);
    }
    
    /**
     * 从缓存获取更新后的账号信息
     */
    private function getUpdatedAccountsFromCache(string $batchId): array
    {
        try {
            $accountCacheKey = "task_{$batchId}_updated_accounts";
            $updatedAccounts = [];
            
            // 获取所有缓存的账号ID
            $accountIds = $this->redis->sMembers($accountCacheKey);
            
            if (!empty($accountIds)) {
                foreach ($accountIds as $userId) {
                    $userCacheKey = "task_{$batchId}_account_{$userId}";
                    $accountData = $this->redis->get($userCacheKey);
                    
                    if ($accountData) {
                        $accountInfo = json_decode($accountData, true);
                        if (is_array($accountInfo)) {
                            $updatedAccounts[] = $accountInfo;
                        }
                    }
                }
            }
            
            return $updatedAccounts;
        } catch (\Exception $e) {
            Log::error("从缓存获取更新账号信息失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 清理任务缓存
     */
    private function cleanupTaskCache(string $batchId)
    {
        try {
            $accountCacheKey = "task_{$batchId}_updated_accounts";
            $accountIds = $this->redis->sMembers($accountCacheKey);
            
            // 删除所有账号缓存
            if (!empty($accountIds)) {
                foreach ($accountIds as $userId) {
                    $userCacheKey = "task_{$batchId}_account_{$userId}";
                    $this->redis->del($userCacheKey);
                }
            }
            
            // 删除账号集合
            $this->redis->del($accountCacheKey);
            
        } catch (\Exception $e) {
            Log::error("清理任务缓存失败: " . $e->getMessage());
        }
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
                
                // 获取当前分组的所有可用代理
                $allSockets = Db::name('sockts')
                    ->where('skcateid', $data['socktsid'])
                    ->where('status', 1)
                    ->select()
                    ->toArray();
                
                if (empty($allSockets)) {
                    throw new \Exception('所选代理分组中没有可用的代理');
                }
                
                // 获取每个代理已分配的账户数量
                $socketUsage = [];
                foreach ($allSockets as $socket) {
                    $assignedCount = MtuserModel::where('socktsid', $socket['id'])->count();
                    $socketUsage[$socket['id']] = $assignedCount;
                }
                
                foreach ($lines as $key => $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    if (isset($accountIds[$key])) {
                        // 为每个账号分配代理
                        $accountId = $accountIds[$key];
                        
                        // 解析代理信息
                        $socketInfo = $this->parseSocketLine($line,$data['socktsid']);
                        
                        // 保存代理信息
                        $socketId = $this->saveSocketInfo($socketInfo);
                        
                        // 检查该代理是否已分配超过20个账户
                        $assignedCount = $socketUsage[$socketId] ?? 0;
                        
                        if ($assignedCount >= 20) {
                            // 如果当前代理已满，自动切换到其他可用代理
                            $availableSockets = array_filter($allSockets, function($socket) use ($socketUsage) {
                                return ($socketUsage[$socket['id']] ?? 0) < 20;
                            });
                            
                            if (empty($availableSockets)) {
                                throw new \Exception('所有代理都已达到20个账户的分配上限');
                            }
                            
                            // 选择分配数量最少的代理
                            usort($availableSockets, function($a, $b) use ($socketUsage) {
                                return ($socketUsage[$a['id']] ?? 0) - ($socketUsage[$b['id']] ?? 0);
                            });
                            
                            $selectedSocket = $availableSockets[0];
                            $socketId = $selectedSocket['id'];
                            $line = $selectedSocket['ip'] . ':' . $selectedSocket['port'] . '##' . $selectedSocket['username'] . '##' . $selectedSocket['password'];
                        }else{
                            $line = $socketInfo['ip'] . ':' . $socketInfo['port'] . '##' . $socketInfo['username'] . '##' . $socketInfo['password'];
                        }
                        
                        // 保存账号与代理的关联
                        $this->saveAccountSocket($accountId, ['id' => $socketId,'line'=>$line]);
                        
                        // 更新代理使用计数
                        $socketUsage[$socketId] = ($socketUsage[$socketId] ?? 0) + 1;
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
                
                // 获取每个代理已分配的账户数量
                $socketUsage = [];
                foreach ($sockets as $socket) {
                    $assignedCount = MtuserModel::where('socktsid', $socket['id'])->count();
                    $socketUsage[$socket['id']] = $assignedCount;
                }
                
                // 为每个账号分配代理，优先选择分配数量较少的代理
                foreach ($accountIds as $accountId) {
                    // 过滤出分配数量少于20的代理
                    $availableSockets = array_filter($sockets, function($socket) use ($socketUsage) {
                        return $socketUsage[$socket['id']] < 20;
                    });
                    
                    if (empty($availableSockets)) {
                        throw new \Exception('所有代理都已达到20个账户的分配上限');
                    }
                    
                    // 选择分配数量最少的代理
                    usort($availableSockets, function($a, $b) use ($socketUsage) {
                        return $socketUsage[$a['id']] - $socketUsage[$b['id']];
                    });
                    
                    $socket = $availableSockets[0];
                    $line=$socket['ip'] . ':' . $socket['port'] . '##' . $socket['username'] . '##' . $socket['password'];
                    
                    // 保存账号与代理的关联
                    $this->saveAccountSocket($accountId, ['id' => $socket['id'],'line'=>$line]);
                    
                    // 更新代理使用计数
                    $socketUsage[$socket['id']]++;
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
    private function parseSocketLine($line, $skcateid)
    {
        $line = trim($line);
        if (empty($line)) {
            return null;
        }
        
        $socketInfo = [
            'ip' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
            'zone' => '',
            'other_info' => '',
            //'protocol' => 'socks5', // 新增协议字段
            'addtime' => time(),
            'updatetime' => time(),
            'status' => 1,
            'skcateid' => $skcateid,
        ];
        
        // 尝试多种格式解析
        $parsed = false;
        
        // 1. 先尝试URL格式
        if (strpos($line, '://') !== false) {
            $parsed = $this->parseProxyUrlFormat($line, $socketInfo);
        }
        
        // 2. 如果URL格式失败，尝试原有的##格式
        if (!$parsed && strpos($line, '##') !== false) {
            $parsed = $this->parseDoubleHashFormat($line, $socketInfo);
        }
        
        // 3. 尝试冒号分隔格式: ip:port:username:password
        if (!$parsed && substr_count($line, ':') >= 3) {
            $parsed = $this->parseColonFormat($line, $socketInfo);
        }
        
        // 4. 尝试简单格式: ip:port
        if (!$parsed && strpos($line, ':') !== false) {
            $parsed = $this->parseSimpleFormat($line, $socketInfo);
        }
        
        if (!$parsed) {
            return null;
        }
        
        // 验证必要字段
        if (empty($socketInfo['ip']) || empty($socketInfo['port'])) {
            return null;
        }
        
        return $socketInfo;
    }
    
    private function parseProxyUrlFormat($line, &$socketInfo)
    {
        $parts = parse_url($line);
        
        if (!$parts || !isset($parts['host']) || !isset($parts['port'])) {
            return false;
        }
        
        // 解析协议
        /*if (isset($parts['scheme'])) {
            $socketInfo['protocol'] = $parts['scheme'];
        }*/
        
        // 设置IP和端口
        $socketInfo['ip'] = $parts['host'];
        $socketInfo['port'] = $parts['port'];
        
        // 解析用户名和密码
        if (isset($parts['user'])) {
            $socketInfo['username'] = $parts['user'];
        }
        
        if (isset($parts['pass'])) {
            $socketInfo['password'] = $parts['pass'];
        }
        
     
        
        return true;
    }
    
    private function parseDoubleHashFormat($line, &$socketInfo)
    {
        $parts = explode('##', $line);
        
        if (isset($parts[0])) {
            list($socketInfo['ip'], $socketInfo['port']) = explode(':', $parts[0], 2);
        }
        
        if (isset($parts[1])) {
            $userInfo = explode('-', $parts[1], 5);
            if (isset($userInfo[0])) $socketInfo['username'] = $userInfo[0];
            if (isset($userInfo[1])) $socketInfo['zone'] = $userInfo[1];
            if (isset($userInfo[2])) $socketInfo['other_info'] = implode('-', array_slice($userInfo, 2));
        }
        
        if (isset($parts[2])) {
            $socketInfo['password'] = $parts[2];
        }
        
        return true;
    }
    
    private function parseColonFormat($line, &$socketInfo)
    {
        $parts = explode(':', $line, 4);
        
        if (count($parts) >= 2) {
            $socketInfo['ip'] = $parts[0];
            $socketInfo['port'] = $parts[1];
        }
        
        if (isset($parts[2])) {
            $socketInfo['username'] = $parts[2];
        }
        
        if (isset($parts[3])) {
            $socketInfo['password'] = $parts[3];
        }
        
        return true;
    }
    
    private function parseSimpleFormat($line, &$socketInfo)
    {
        list($socketInfo['ip'], $socketInfo['port']) = explode(':', $line, 2);
        return true;
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
        MtuserModel::where('id', $accountId)
            ->update([
				'socktsid'=>$socket['id'],
                'proxyip' => $socket['line'],
                'updatetime' => time()
            ]);
    }

    public function getRobot_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = ['status'=>1,'admin_id'=>session('admin.user_id')];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select id,phone from cd_Mtuser',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}

    
    
    public function accountStatusDesc() {
        // 获取 GET/POST 参数
        $archive = input('param.archive', 0); // 默认 0，如果没传就算普通数据
    
        // 构建查询条件
        $where = [];
        if ($archive) {
            $where['archive'] = $archive;
        }
    
        // 查询账号状态描述及数量
        $res = MtuserModel::where($where)
            ->field('account_status_desc, COUNT(*) as count')
            ->group('account_status_desc')
            ->order('id desc')
            ->select()
            ->toArray();
    
        return json(['status' => 200, 'data' => $res]);
    }



    /**
     * 更新用户归档状态缓存
     * @param array $userIds 用户ID数组
     */
    public  function updateUserArchiveCache(array $userIds)
    {
        if (empty($userIds) || !is_array($userIds)) {
            Log::info("没有需要更新归档缓存的用户ID或参数类型错误");
            return;
        }
    
        // 统一处理用户ID，确保为整数类型
        $userIds = array_map(function($id) {
            return (int)$id;
        }, $userIds);
        $userIds = array_unique($userIds); // 去重避免重复操作
    
       
        $redisPrefix = 'tdata8:telegram_task:';
        $expireTime = 86400; // 24小时过期
    
        // 强制从数据库批量获取最新数据（忽略现有缓存）
        $this->batchFetchAndUpdateFromDb( $redisPrefix, $userIds, $expireTime);
        
        Log::info("用户归档缓存已强制从数据库更新，共处理 " . count($userIds) . " 个用户");
    }
    
 
    
    /**
     * 批量从数据库获取并更新缓存
     */
    private function batchFetchAndUpdateFromDb( $prefix, array $userIds, $expireTime)
    {
        // 批量查询数据库，获取最新用户数据
        $users = MtuserModel::field('id,account,account_status,account_status_desc, tdata_path,status,session_path, proxyip, archive,last_api_address')
            ->where(['id'=>$userIds])
            ->select()
            ->toArray();
    
        $userMap = array_column($users, null, 'id');
    
        foreach ($userIds as $userId) {
            $cacheKey = $prefix . 'user:' . $userId;
            $this->redis->delete($cacheKey);
           /* if (isset($userMap[$userId])) {
                // 数据库中存在的用户：强制更新归档状态并覆盖缓存
                $userData = $userMap[$userId];
                $this->redis->set($cacheKey, json_encode($userData, JSON_UNESCAPED_UNICODE), $expireTime);
                //Log::info("用户 {$userId} 已从数据库强制更新归档缓存");
            } else {
                // 数据库中不存在的用户：标记为删除（覆盖可能存在的旧缓存）
                $this->redis->delete($cacheKey);
               // Log::info("用户 {$userId} 数据库中不存在，强制标记为删除缓存");
            }*/
        }
    }
    
    public function exportTdataZip()
    {
        $ids = $this->request->get('id', '');
        if (empty($ids)) {
            abort(400, '参数错误');
        }
    
        $ids = explode(',', $ids);
    
        // 查询账号对应的 tdata 路径
        $users = MtuserModel::whereIn('id', $ids)
            ->field('id,account,tdata_path')
            ->select();
    
        if ($users->isEmpty()) {
            abort(404, '未找到账号');
        }
    
        $zipName = 'tdata_export_' . date('Ymd_His') . '.zip';
        $zipPath = runtime_path() . $zipName;
    
        $zip =  new ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    
        foreach ($users as $user) {
            $tdataPath = public_path() . '/' . str_replace('/tdata', '', $user['tdata_path']);
           // $tdataPath = public_path() .'/'.$user['tdata_path'];
    
            if (!is_dir($tdataPath)) {
                continue;
            }
            log::info($tdataPath);
            // 以手机号为目录名
            $this->addDirToZip($tdataPath, $zip, $user['account']);
        }
    
        $zip->close();
    
        return download($zipPath, $zipName, false); 
    }

    private function addDirToZip($dir, \ZipArchive $zip, $zipBase)
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
    
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = $zipBase . '/' . substr($filePath, strlen($dir) );
            // log::info('relativePath'.$relativePath);
            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }
    }
}
