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
use app\service\TelegramService;
use think\facade\Cache;
use think\facade\Log;
use app\admin\model\Uploadfile as UploadfileModel;
use ZipArchive;
use think\facade\Queue;
use app\job\MtuserHandleJob; 
use app\common\NodeManager;
class Mtarchive extends Admin {

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
			$where['Mtuser.nickName'] = $this->request->post('nickName', '', 'serach_in');
			$where['Mtuser.status'] = $this->request->post('status', '', 'serach_in');
			$where['Mtuser.archive'] =0;
			$where['Mtuser.cateid']=$this->request->post('cateid', '', 'serach_in');
			$where['Mtuser.customid']=$this->request->post('customid', '', 'serach_in');
			$where['Mtuser.account_status']=$this->request->post('account_status', '', 'serach_in');
			$where['Mtuser.account_status_desc']=$this->request->post('account_status_desc', '', 'serach_in');
			
			
			
            $withJoin = [
				'Adminuser'=>explode(',','name'),
                'Mtcate'=>explode(',','class_name'),
			];
			$res = MtuserModel::where(formatWhere($where))->order('id desc')->withJoin($withJoin,'left')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	




    /**
     * @Description 删除（同时删除tdata的父级文件夹14504251432）
     */
    function delete() {
        $id = $this->request->post('id', '', 'serach_in');
        if (!$id) {
            throw new ValidateException('参数错误');
        }
        
        // 获取要删除的记录，获取包含tdata的完整路径
        $ids = explode(',', $id);
        $mtusers = MtuserModel::whereIn('id', $ids)->field('id, tdata_path')->select();
        
        if ($mtusers->isEmpty()) {
            throw new ValidateException('记录不存在');
        }
        
        try {
            // 先删除数据库记录（批量删除，true表示强制删除）
            MtuserModel::destroy($ids, true);
            
            // 再删除对应的“账号专属目录”（仅删除当前账号的目录，不碰公共目录）
            foreach ($mtusers as $mtuser) {
                $tdataPath = $mtuser->tdata_path;
                
                // 1. 基础校验：路径非空且是目录
                if (empty($tdataPath) || !is_dir($tdataPath)) {
                    // 可选：记录日志（避免静默失败）
                   
                    continue; // 跳过无效路径，不影响其他账号删除
                }
                
              
                // 2. 明确目标：仅删除“tdata的父目录”（即账号专属目录，如8618020175281）
                $accountDir = dirname($tdataPath); // 正确：单个账号的专属目录
                
                // 3. 安全校验：确保目标目录是tdata的直接父级（避免误删上层目录）
                // （例如：账号目录下必须包含tdata子目录，防止路径被篡改）
                if (!is_dir($accountDir) || !is_dir($tdataPath)) {
                  
                    continue;
                }
                
                // 4. 递归删除“账号专属目录”（仅当前账号的文件，不影响其他子账号）
                $deleteResult = $this->deleteDirectory($accountDir);
                if (!$deleteResult) {
                   
                }
                
                $publicDir = dirname($accountDir); // 公共目录 = 账号目录的父级
            
            
                
                // 3. 检查公共目录是否为空（无其他子账号目录）
                if ($this->isDirEmpty($publicDir)) {
                    // 若为空，删除公共目录
                    $this->deleteDirectory($publicDir);
                   // Log::info("公共目录已空，已删除", ['public_dir' => $publicDir]);
                } else {
                    //Log::info("公共目录仍有子目录，保留", ['public_dir' => $publicDir]);
                }
                
            }
            
            return json(['status' => 200, 'msg' => '操作成功']);
        } catch (\Exception $e) {
            // 记录异常日志，便于排查
            
            return json(['status' => 500, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }
    // 新增：判断目录是否为空（仅包含.和..）
    private function isDirEmpty($dir) {
        if (!is_dir($dir)) {
            return false; // 不是目录，视为“非空”
        }
        
        $files = scandir($dir);
        // 过滤.和..后，若没有其他文件/目录，则视为空
        $validFiles = array_filter($files, function($file) {
            return $file !== '.' && $file !== '..';
        });
        
        return empty($validFiles);
    }
    
    /**
     * 递归删除文件夹及其所有内容
     * @param string $dir 目标文件夹路径
     * @return bool
     */
    private function deleteDirectory($dir) {
        // 安全校验：确保路径中包含uploads/admin，防止误删其他目录
        if (strpos($dir, 'uploads/admin') === false) {
            throw new \Exception('禁止删除非uploads/admin目录下的文件');
        }
        
        // 检查是否为有效的目录
        if (!is_dir($dir)) {
            return false;
        }
        
        // 遍历并删除目录内容
        $files = new \FilesystemIterator($dir);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                $this->deleteDirectory($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        
        // 删除空目录
        return rmdir($dir);
    }

 	/*
 	* @Description  归档
 	*/  
  


  
    /*
    转移分组*/
    public function transfer(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['cateid'] =  $this->request->post('cateid', '', 'serach_in');
		$res = MtuserModel::field('cateid')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

    public function getRobot_id(){
		$limit  = $this->request->post('limit', 20, 'intval');
		$page = $this->request->post('page', 1, 'intval');

		$where = ['status'=>1,'admin_id'=>session('admin.user_id')];
		$skip = ($page-1) * $limit.','.$limit;
		$data = $this->getSelectPageData('select id,phone from cd_Mtuser',$where,$skip); 
		return json(['status'=>200,'data'=>$data]);
	}
	
	public function Uploadfile(){
		$postField = 'cateid,file';
		$data = $this->request->only(explode(',',$postField),'post',null);

		$this->validate($data,\app\admin\validate\Uploadfile::class);
		$datas['title'] = $data['cateid'];
		$datas['file'] = $data['file'];
        $datas['addtime'] = time();
		try{
		    $fileExists = UploadfileModel::where('file', $datas['file'])->find();
            if ($fileExists) {
                throw new \Exception('该文件已上传，禁止重复上传');
            }
            
		    $relativeFilePath = $data['file']; // 例如: uploads/admin/202508/68934ebeabbdc.zip
            $absoluteFilePath = public_path() . ltrim($relativeFilePath, '/');
        	
            // 验证文件是否存在
            if (!file_exists($absoluteFilePath)) {
                throw new \Exception('文件不存在: ' . $relativeFilePath);
            }
            $ext = strtolower(pathinfo($absoluteFilePath, PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                throw new \Exception('仅支持 ZIP 压缩文件上传');
            }
			$res = UploadfileModel::create($datas);
			// 处理本地压缩文件
            $reaccount=$this->handleMultiAccountZip($absoluteFilePath, $relativeFilePath, $data['cateid']);
            
            log::info("返回结果: " . json_encode($reaccount));
            return json([
                'status' => 200,
                'data' => $res->uploadfile_id,
                'msg' => '文件处理成功，解析'.$reaccount['total'].'账号信息后台处理中',
                'batch_id'=>$reaccount['batch_id'],
                'total'=>$reaccount['total']
            ]);
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		
	}
    /**
     * 处理本地压缩文件，解析tdata并提取账号
     * @param string $absoluteFilePath 本地文件绝对路径
     * @param string $relativeFilePath 相对路径（用于存储）
     * @param int $uploadId 上传记录ID
     * @param int $cateid 分类ID
     * @throws \Exception
     */
     
    private function handleMultiAccountZip(string $absoluteFilePath, string $relativeFilePath, int $cateid): array
    {
        $admin = session('admin');
        $userid = $admin['user_id'] ?? 0;
        
        // 初始化统计数据
        $stats = [
            'total' => 0,          // 总账号数
            'queued' => 0,         // 成功入队数
            'failed' => 0,         // 入队失败数
            'chunk_count' => 0,    // 子批次数量
            'errors' => [],        // 错误信息
            'batch_id' => ''       // 主批次ID
        ];
        
        // 获取压缩包信息
        $fileDir = pathinfo($absoluteFilePath, PATHINFO_DIRNAME);
        $fileName = pathinfo($absoluteFilePath, PATHINFO_FILENAME);
        $extractMainDir = $fileDir . '/' . $fileName;
        $zip = null;
        try {
            // 1. 检查压缩包是否存在
            if (!file_exists($absoluteFilePath)) {
                throw new \Exception("压缩包不存在：{$absoluteFilePath}");
            }
    
            // 2. 创建临时解压目录（增加权限校验）
            if (!is_dir($extractMainDir)) {
                if (!mkdir($extractMainDir, 0755, true) && !is_dir($extractMainDir)) {
                    throw new \Exception("创建解压目录失败（权限不足）：{$extractMainDir}");
                }
            }
            // 解压压缩包
            $zip = new ZipArchive();
            $openResult = $zip->open($absoluteFilePath);
            
            if ($openResult !== true) {
                throw new \Exception('无法打开压缩文件，错误代码: ' . $openResult);
            }
            
            // 检查解压是否成功
            if (!$zip->extractTo($extractMainDir)) {
                throw new \Exception('压缩包解压失败');
            }
            $zip->close();
            $zip = null;
            // 获取所有账号文件夹
            $accountDirs = $this->findAccountDirectories($extractMainDir);
            
            $totalAccounts = count($accountDirs);
            if ($totalAccounts === 0) {
                throw new \Exception('未找到有效账号目录');
            }
            
            $stats['total'] = $totalAccounts;
            $batchId = uniqid('batch_', true);
            $stats['batch_id'] = $batchId;
    
            $batchExistsKey = "batch_exists:{$batchId}";
        
            // 关键：用Redis的setnx确保批次唯一（避免重复投递）
            if (!$this->redis->setnx($batchExistsKey, 1)) {
                throw new \Exception("批次已存在，避免重复处理：{$batchId}");
            }
            $this->redis->expire($batchExistsKey, 600); // 批次标记有效期24小时
            
            $this->redis->hMSet("task_{$batchId}_progress", [
                'total' => $totalAccounts,
                'completed' => 0,
                'success' => 0,
                'failed' => 0,
                'status' => 'processing',
                'start_time' => time(),
                'file_path' => $relativeFilePath,
                'cateid' => $cateid,
                'chunk_count' => 0 // 子批次数量（后续更新）
            ]);
            $this->redis->expire("task_{$batchId}_progress", 3600);
    
            // 优化点1：分批次处理，每批处理1000个
            $batchSize = config('telegram.batch_size');
            $accountChunks = array_chunk($accountDirs, $batchSize, true);
            $totalChunks = count($accountChunks);
            
            $stats['chunk_count'] = $totalChunks;
            $stats['total'] = $totalAccounts;
            $stats['batch_id'] = $batchId;
            //log::info(json_encode($totalChunks));
            // 优化点2：使用Redis管道减少IO操作
            $pipe = $this->redis->multi(\Redis::PIPELINE);
            $queueErrors = [];
            $nodeManager = new NodeManager();
            
            foreach ($accountChunks as $chunkIndex => $chunk) {
                $chunkSize = count($chunk);
                $subBatchId = "{$batchId}_sub_{$chunkIndex}"; // 子批次唯一ID
                // 优化点3：使用延迟队列，避免瞬间压力过大
             
                 $taskData = [
                    'cateid' => $cateid,
                    'userid' => $userid,
                    'accounts' => $chunk, // 用于后续清理
                    'create_time' => time(),
                    'batch_id' => $batchId,
                    'sub_batch_id' => $subBatchId, // 子批次唯一ID
                    'chunk_index' => $chunkIndex  // 子批次索引（便于日志追踪）
                ];
                // 延迟投递：每批延迟1秒（错开处理高峰，避免进程争抢）
                $delay = $chunkIndex * 1;
                
                // 推送到队列
                //$taskId = Queue::push(MtuserHandleJob::class, $taskData, 'mtuser_handle');
                //$taskId = Queue::later($delay, MtuserHandleJob::class, $taskData, 'mtuser_handle'); 
                try {
                    // 投递到mtuser_handle队列（返回任务ID，校验投递结果）
                    $taskId = Queue::later($delay, MtuserHandleJob::class, $taskData, 'mtuser_handle');
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
                    $pipe->expire("task_{$batchId}_subs", 3600);
                    
                    $stats['queued'] += $chunkSize;
                    Log::info("批次{$batchId}：子批次{$chunkIndex}（{$chunkSize}个账号）投递成功，任务ID：{$taskId}");
    
                } catch (\Exception $e) {
                    $errorMsg = "子批次{$chunkIndex}处理失败：{$e->getMessage()}";
                    $queueErrors[] = $errorMsg;
                    $stats['failed'] += $chunkSize;
                    Log::error($errorMsg);
                } 
                 
            }
            
            // 执行Redis管道命令
            $pipe->hSet("task_{$batchId}_progress", 'chunk_count', $totalChunks);
            $pipe->exec();
            
            // 更新批次状态为已排队
            $this->redis->hSet("task_{$batchId}_progress", 'status', 'queued');
            if (!empty($queueErrors)) {
                $stats['errors'] = $queueErrors;
                $this->redis->hSet("task_{$batchId}_progress", 'errors', implode(';', $queueErrors));
            }
    
            Log::info("批次{$batchId}处理完成：总账号{$totalAccounts}，子批次{$totalChunks}，成功入队{$stats['queued']}个");
            return $stats;
            
        } catch (\Exception $e) {
            // 清理解压目录
            $this->deleteDirectory($extractMainDir);
            $errorMsg = '处理压缩文件失败: ' . $e->getMessage();
            $stats['errors'][] = $errorMsg;
            $stats['failed'] = $stats['total'];
            Log::error($errorMsg);
            return $stats;
        }finally {
            // 确保zip资源释放
            if ($zip !== null) {
                @$zip->close();
            }
            // 仅在完全失败时清理目录（部分成功时保留用于排查）
            if ($stats['failed'] == $stats['total'] && is_dir($extractMainDir)) {
                $this->deleteDirectory($extractMainDir);
                Log::info("因处理失败，已清理临时目录: {$extractMainDir}");
            }
        }
    }
    
    /**
     * 查找以电话号码命名的文件夹（7开头的11位数字）
     * @param string $dir 查找目录
     * @return array 格式: [电话号码 => 文件夹路径]
     */
    private function findAccountDirectories(string $dir): array
    {
        $accountDirs = [];
        
        if (!is_dir($dir)) {
            return $accountDirs;
        }
        
        $directory = new \DirectoryIterator($dir);
        
        foreach ($directory as $fileinfo) {
            if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                $dirname = $fileinfo->getFilename();
                
                // 匹配7开头的11位数字（如79390415998）
                //if (preg_match('/^7\d{10}$/', $dirname)) {
                    $accountDirs[$dirname] = $fileinfo->getPathname();
                //}
            }
        }
        
        return $accountDirs;
    }
    
    /**
     * 在账号文件夹中查找tdata目录
     * @param string $accountDir 账号文件夹路径
     * @return string|null tdata目录路径
     */
    private function findTdataInAccountDir(string $accountDir): ?string
    {
        // 情况1: tdata直接在账号文件夹下
        $tdataPath = $accountDir . '/tdata';
        if (is_dir($tdataPath)) {
            return $tdataPath;
        }
      
    }

}

