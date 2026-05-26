<?php 
/*
 module:		
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Mttask as MttaskModel;
use app\admin\model\Mtuser as MtuserModel;
use app\admin\model\Mtcate as MtcateModel;
use think\facade\Db;
use think\facade\Log;
use think\facade\Validate;
use think\facade\Filesystem;
use think\Image;
use think\facade\Cache;
use think\facade\Queue;
use app\job\TelegramTaskExecutor; 

class Mttask extends Admin {

    private $redisPrefix = 'telegram_task:';
	/*
 	* @Description  数据列表
 	*/
	function index(){
		if (!$this->request->isPost()){
			return view('index');
		}else{
			$limit  = $this->request->post('limit', 30, 'intval');
			$page = $this->request->post('page', 1, 'intval');

			$where = [];
			
			//$where['username'] = $this->request->post('username', '', 'serach_in');
		    

			$create_time = $this->request->post('create_time', '', 'serach_in');
			$where['create_time'] = ['between',[strtotime($create_time[0]),strtotime($create_time[1])]];
			
            $admin = session('admin');
            $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
          
            if($userid!=1){
                $where['mttask.admin_id'] =$userid;
            }
            
            
			$withJoin = [
				'mtcate'=>explode(',','class_name'),
			];
	        

			$res = MttaskModel::field('id,title,status,success_count,fail_count,create_time')->where(formatWhere($where))->withJoin($withJoin,'left')->order('id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	
	public	function addtask(){ 
		return view('addtask');
	}
	public	function updatetask(){ 
	    $id = input('id');
		return view('updatetask');
	}
			/*
 	* @Description  添加
 	*/
	public function add(){
		if (!$this->request->isPost()){
			return view('add');
		}else{
			$postField = 'title,bot_id,content,sendtype,pic,status';
			$data = $this->request->only(explode(',',$postField),'post',null);


			$data['create_time'] = time();
			try{
			    $data['admin_id']=session('admin.user_id');
				$res = MttaskModel::create($data);
			}catch(\Exception $e){
				throw new ValidateException($e->getMessage());
			}
			return json(['status'=>200,'data'=>$res->id,'msg'=>'添加成功']);
		}
	}

	public function getInfo()
    {
        $id = input('id');
        if (empty($id)) {
            return json(['status' => 400, 'message' => '缺少参数']);
        }
        
        $task = MttaskModel::find($id);
        if (empty($task)) {
            return json(['status' => 404, 'message' => '任务不存在']);
        }
        
        // 解析messages字段
        $task->messages = json_decode($task->messages, true);
        
        return json([
            'status' => 200,
            'message' => '获取成功',
            'data' => $task
        ]);
    }
	/**
     * 保存任务
     */
    public function save()
	{
		$data = $this->request->post();
		$redisPrefix = 'telegram_task:'; // 与任务处理类保持一致的前缀
        $redis = Cache::store('redis');
		
		// 检查是否为新增操作，设置创建时间
		if (!isset($data['id']) || empty($data['id'])) {
			$data['create_time'] = time();
		}
		
		// 无论新增还是更新，都设置更新时间
		$data['update_time'] = time();		

		
		// 验证数据
		try {
			validate('Mttask')->scene('save')->check($data);
		} catch (ValidateException $e) {
			return json(['status' => 400, 'message' => $e->getMessage()]);
		}
		
		// 处理messages字段
		if (isset($data['messages']) && is_array($data['messages'])) {
			$data['messages'] = json_encode($data['messages'], JSON_UNESCAPED_UNICODE);
		}
		
		Db::startTrans();
		try {
		    $taskId = $data['id'] ?? 0;
            
			if (isset($data['id']) && !empty($data['id'])) {
	
				// 更新任务
				$task = MttaskModel::find($data['id']);
				if (empty($task)) {
					throw new \Exception('任务不存在');
				}
				
				$result = $task->save($data);
				if (!$result) {
					throw new \Exception('更新任务失败');
				}
			} else {
		
				// 新增任务
				$task = MttaskModel::create($data);
				if (!$task) {
					throw new \Exception('创建任务失败');
				}
				$taskId = $task->id;
			}
			$cacheKey = $redisPrefix . 'config:' . $taskId;
			 // 准备缓存数据（与processTask中缓存的结构一致）
            /*$cacheData = [
                'messages' => isset($data['messages']) ? json_decode($data['messages'], true) : [],
                'groupList' => !empty($data['group_list']) ? explode(',', $data['group_list']) : [],
                'concurrent' => isset($data['concurrent']) && $data['concurrent'] > 0 ? $data['concurrent'] : 5,
                'xhnum' => isset($data['xhnum']) && $data['xhnum'] > 0 ? $data['xhnum'] : 1,
            ];
            */
            // 保存缓存（1小时过期，与processTask保持一致）
            $redis->set($cacheKey, json_encode($cacheData, JSON_UNESCAPED_UNICODE), 3600);
            
            // 如果是更新操作，清除旧的循环计数缓存（确保新配置立即生效）
            if (isset($data['id']) && !empty($data['id'])) {
                $redis->del($redisPrefix . 'cycle:' . $taskId);
                $redis->del($redisPrefix . 'config:' . $taskId);
            }
			Db::commit();
			return json(['status' => 200, 'message' => '保存成功']);
		} catch (\Exception $e) {
			Db::rollback();
			return json(['status' => 500, 'message' => $e->getMessage()]);
		}
	}
	/*
 	* @Description  修改排序开关
 	*/
	function updateExt(){
		$postField = 'id,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(!$data['id']) throw new ValidateException ('参数错误');
		MttaskModel::update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,title,bot_id,content,sendtype,pic,status';
		$data = $this->request->only(explode(',',$postField),'post',null);

		try{
		    
			MttaskModel::update($data);
			
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
		
		$res = MttaskModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}

    /*
     * @Description  检查任务状态
     */
    public function checkStatus()
    {
        if (!$this->request->isPost()) {
            return json(['status' => 400, 'message' => '请求方式错误']);
        }
        
        $taskIds = $this->request->post('ids', '', 'serach_in');
        
        // 如果没有传递ID，返回空数据
        if (empty($taskIds)) {
            return json(['status' => 200, 'data' => [], 'message' => '无任务ID']);
        }
        
        // 将ID转换为数组
        if (!is_array($taskIds)) {
            $taskIds = explode(',', $taskIds);
        }
        
        // 过滤非数字ID
        $taskIds = array_filter($taskIds, function($id) {
            return is_numeric($id) && $id > 0;
        });
        
        if (empty($taskIds)) {
            return json(['status' => 200, 'data' => [], 'message' => '无有效任务ID']);
        }
        
        try {
            // 查询任务状态
            $tasks = MttaskModel::where('id', 'in', $taskIds)
                ->field('id, title, status, success_count, fail_count, update_time')
                ->select()
                ->toArray();
            
            
            return json([
                'status' => 200,
                'data' => $tasks,
                'message' => '获取成功'
            ]);
            
        } catch (\Exception $e) {
            // 记录错误日志
            Log::error('检查任务状态失败: ' . $e->getMessage());
            
            return json([
                'status' => 500,
                'data' => [],
                'message' => '检查任务状态失败: ' . $e->getMessage()
            ]);
        }
    }
	/*
 	* @Description  删除
 	*/
	/*function delete(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		//MttaskModel::destroy(['id'=>explode(',',$idx)],true);
		return json(['status'=>200,'msg'=>'操作成功']);
	}*/
    public function delete()
    {
        $idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		//MttaskModel::destroy(['id'=>explode(',',$idx)],true);
        
        $ids = explode(',', $idx);
        $redisPrefix = 'telegram_task:';
        $redis = Cache::store('redis');
    
        Db::startTrans();
        try {
            
            MttaskModel::destroy(['id'=>explode(',',$idx)],true);
    
            // 批量清理相关缓存
            foreach ($ids as $taskId) {
                // 清理任务配置缓存
                $redis->del($redisPrefix . 'config:' . $taskId);
                // 清理循环计数缓存
                $redis->del($redisPrefix . 'cycle:' . $taskId);
                // 清理状态缓存
                $redis->del($redisPrefix . 'status:' . $taskId);
                // 清理第一条消息记录缓存
                $redis->del($redisPrefix . '' . $taskId . ':first_msg_ids');
                // 清理消息发送状态缓存（按群组）
                $pattern = $redisPrefix . "{$taskId}:group:*:messages";
                $messageKeys = $redis->keys($pattern);
                if (!empty($messageKeys)) {
                    $redis->del($messageKeys);
                }
            }
    
            Db::commit();
            return json(['status' => 200, 'msg' => "成功删除任务"]);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['status' => 500, 'msg' => $e->getMessage()]);
        }
    }


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = MttaskModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = MttaskModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

    public function getMessage_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = ['status'=>1];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select id,title from cd_telemessage',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}
	
	public function getByGroup(){ 
		$group_id = $this->request->post('group_id', '', 'serach_in');
		if(!$group_id) throw new ValidateException ('参数错误');
		$admin = session('admin');
		$userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 1;
		$task_num=MtcateModel::where('class_id',$group_id)->value('task_num');
		$where['cateid'] = intval($group_id);
		$where['archive'] = 1;
		$where['status'] = 1;
		$where['account_status']='正常';

		$where['admin_id'] =$userid;
		
		$data = Mtusermodel::where($where)->where('loginnum','>',0)->field('id,account_status,loginnum,nickName')->limit($task_num)->order('id asc')->select()->toArray();	
	    $total=Mtusermodel::where($where)->limit($task_num)->count();
		if($task_num>0&&$total>=$task_num){
		    
		    return json(['status'=>200,'data'=>$data]);
		}else{
		    $nonum=$task_num-$total;
		    return json(['status'=>201,'message'=>'账户不足-'.$nonum]);
		}
		
	}
	/**
     * 导入任务配置
     */
    public function import()
    {
        $file = $this->request->file('file');
        $upload_config_id = $this->request->post('upload_config_id');
        $file_type = upload_replace(config('base_config.filetype')); // 上传黑名单过滤
    
        // 验证文件类型
        if (!Validate::fileExt($file, $file_type)) {
            throw new ValidateException('文件类型验证失败');
        }
    
        // 验证文件大小
        if (!Validate::fileSize($file, config('base_config.filesize') * 1024 * 1024)) {
            throw new ValidateException('文件大小验证失败');
        }
    
        // 上传文件并获取URL
        if ($url = $this->up($file)) {
            try {
                // 读取文件内容（根据实际存储方式调整读取逻辑）
                // 1. 如果是本地文件路径，直接读取
                $url=public_path() .$url;
                if (file_exists($url)) {
                    $content = file_get_contents($url);
                } 
                // 2. 如果是远程URL，通过file_get_contents读取（需开启allow_url_fopen）
                elseif (filter_var($url, FILTER_VALIDATE_URL)) {
                    $content = file_get_contents($url);
                    if ($content === false) {
                        throw new \Exception('远程文件读取失败');
                    }
                } 
                // 3. 其他存储方式（如OSS等）需对应调整
                else {
                    throw new \Exception('文件路径无效，无法读取内容');
                }
    
                // 解析JSON内容（针对你的空投150.json场景）
                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON格式错误：' . json_last_error_msg());
                }
    
                // 返回上传URL、文件内容解析结果等信息
                return json([
                    'status' => 200,
                    'message' => '文件上传并读取成功',
                    'data' => $data
                        
                ]);
            } catch (\Exception $e) {
                return json([
                    'status' => 500,
                    'message' => '文件读取失败：' . $e->getMessage()
                ]);
            }
        }
    
        // 上传失败处理
        return json([
            'status' => 400,
            'message' => '文件上传失败'
        ]);
    }
    //开始上传
	protected function up($file,$upload_config_id=''){
		try{
		
				$filename = Filesystem::disk('public')->putFile($this->getFileName(),$file,'uniqid');
				$url =config('filesystem.disks.public.url').'/'.$filename;
				//log::info(config('base_config.domain'));
				//log::info(config('filesystem.disks.public.url'));
			
		}catch(\Exception $e){
			throw new ValidateException('上传失败');
		}
	
		
		return $url;
	}
	//获取上传的文件完整路径
	private function getFileName(){
		return app('http')->getName().'/'.date(config('my.upload_subdir'));
	}
	
	/**
     * 开始任务
     */
    public function start()
    {
        $ids = $this->request->post('id');
        if(empty($ids)) {
            return $this->error('请选择要开始的任务');
        }
        
        $idArr = explode(',', $ids);
        $successCount = 0;
        
        try {
            foreach($idArr as $id) {
                $task = MttaskModel::where('id', $id)->find();
                if(empty($task)) {
                    continue;
                }
                
                // 检查任务当前状态
                if(in_array($task['status'], [2])) {
                    continue; // 已经在运行中，跳过
                }
                
                // 2. 初始化Redis状态（0=未运行 → 2=运行中）
                $statusKey = $this->redisPrefix . 'status:' . $id;
          
                $status = Cache::store('redis')->get($statusKey);
                
                if ($task['status'] ===3) {
                    $dataStatus['success_count']=0;
                    $dataStatus['fail_count']=0;
                }
                $currentStatus = $status;
            
                Cache::store('redis')->set($statusKey, 2);
                $dataStatus['status']=1;
                // 更新任务状态为运行中
                MttaskModel::where('id', $id)->update($dataStatus);
                
                 Queue::push(TelegramTaskExecutor::class, [
                    'task' => $task
                ], 'telegramtaskjob');
                // 记录日志
                Log::info("任务开始: ID={$id}, 名称={$task['title']}");
                
                $successCount++;
            }
            return json([
                    'status' => 200,
                    'msg' => "成功启动 {$successCount} 个任务",
                    
                ]);
           
        } catch (\Exception $e) {
            Log::error("启动任务失败: " . $e->getMessage());
            return $this->error("启动任务失败: " . $e->getMessage());
        }
    }
    
    /**
     * 停止任务
     */
    public function stop()
    {
        $ids = $this->request->post('id');
        if(empty($ids)) {
            return $this->error('请选择要停止的任务');
        }
        
        $idArr = explode(',', $ids);
        $successCount = 0;
        
        try {
            
            foreach($idArr as $id) {
                $task = MttaskModel::where('id', $id)->find();
                if(empty($task)) {
                    continue;
                }
                // 检查任务当前状态
                if(!in_array($task['status'], [2])) {
                    continue; // 不在运行中，跳过
                }
                $statusKey = $this->redisPrefix . 'status:' . $id;
                Cache::store('redis')->set($statusKey, 5);
                // 更新任务状态为已停止
               // MttaskModel::where('id', $id)->update(['status' => 5]);
                
                // 记录日志
                Log::info("任务停止: ID={$id}, 名称={$task['title']}");
                // 终止子进程
               
    
                // 更新任务表
                MttaskModel::where('id', $id)->update([
                    'status'   => 5
                ]);
                $successCount++;
            }
            return json([
                    'status' => 200,
                    'msg' => "成功停止 {$successCount} 个任务",
                    
                ]);
        } catch (\Exception $e) {
            Log::error("停止任务失败: " . $e->getMessage());
            return $this->error("停止任务失败: " . $e->getMessage());
        }
    }
    

}

