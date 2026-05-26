<?php 
/*
 module:		
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Sockts as SocktsModel;
use app\admin\model\Mtuser as MtuserModel;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
class Sockts extends Admin {


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
			
			$where['sockts.status'] = $this->request->post('status', '', 'serach_in');
		    $where['sockts.skcateid'] = $this->request->post('cateid', '', 'serach_in'); 
            $admin = session('admin');
            $userid = session('admin_sign') == data_auth_sign($admin) ? $admin['user_id'] : 0;
          
            if($userid!=1){
                $where['sockts.admin_id'] =$userid;
            }
			$create_time = $this->request->post('create_time', '', 'serach_in');
			$where['addtime'] = ['between',[strtotime($create_time[0]),strtotime($create_time[1])]];

			$withJoin = [
				'socktscate'=>explode(',','class_name'),
			];
	        

			$res = SocktsModel::where(formatWhere($where))->withJoin($withJoin,'left')->order('id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			foreach ($res['data'] as $key => $value) {
				$res['data'][$key]['user_count'] = MtuserModel::where('socktsid',$value['id'])->count();
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
		$postField = 'ip,port,username,password,skcateid,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
		$exists = SocktsModel::where('ip', $data['ip'])
			->where('port', $data['port'])
			->find();
			
		if ($exists) {
			return json(['status' => 400, 'msg' => '该IP和端口组合已存在']);
		}

        $data['addtime'] = time();
		try{
		    $data['admin_id']=session('admin.user_id');
			$res = SocktsModel::create($data);
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
		if($data['status']==1){
		    $sockt = SocktsModel::whereIn('id', $data['id'])->find();
            $proxyId = $sockt['id'];
            // 构建代理字符串
            $ipPort = $sockt['ip'] . ':' . $sockt['port'];
            if ($sockt['username']) {
                $proxy = "socks5://{$sockt['username']}:{$sockt['password']}@{$ipPort}";
            } else {
                $proxy = "socks5://{$ipPort}";
            }
            
            try {
                // 调用Python脚本检查代理
                $pythonResult = $this->callPythonAccountChecker($proxy);
                
                if ($pythonResult['status']) {
                    // 检测成功
                    SocktsModel::update($data);
                   $status=200;
                   $msg="操作成功";
                } else {
                    $status=201;
                    // 检测失败（Python返回失败状态）
                    $msg="代理信息无效，不能开启";
                }
            } catch (\Exception $e) {
                 $status=201;
                // 捕获当前代理的检测错误
                $msg="代理信息无效，不能开启";
            }
		}else{
		    SocktsModel::update($data);
		    MtuserModel::where('socktsid', $data['id'])->update([
                        'status' => 0,
                        'remask' => '代理已关闭'
                    ]);
                     $status=201;
                    $msg="操作成功";
		}
		
		return json(['status'=>$status,'msg'=>$msg]);
	}


	/*
 	* @Description  修改
 	*/
	public function update() {
		$postField = 'id,ip,port,username,password,skcateid,status';
		$data = $this->request->only(explode(',', $postField), 'post', null);

		try {
			// 开始事务
			Db::startTrans();
			
			// 更新代理信息
			SocktsModel::update($data);
			
			// 构建新的proxyip格式：ip:port##username##password
			$proxyip = "{$data['ip']}:{$data['port']}";
			if (!empty($data['username'])) {
				$proxyip .= "##{$data['username']}";
				if (!empty($data['password'])) {
					$proxyip .= "##{$data['password']}";
				}
			}
			// 获取受影响的用户ID列表
            $userIds = MtuserModel::where('socktsid', $data['id'])->column('id');
			// 更新mtuser表中的proxyip字段
			MtuserModel::where('socktsid', $data['id'])
				->update(['proxyip' => $proxyip]);
			
			// 提交事务
			Db::commit();
			/*if (!empty($userIds)) {
                $this->updateUserProxyCache($userIds);
            }*/
			return json(['status' => 200, 'msg' => '修改成功']);
		} catch (\Exception $e) {
			// 回滚事务
			Db::rollback();
			throw new ValidateException('更新失败：' . $e->getMessage());
		}
	} 
	private function getRedisCache()
    {
        return Cache::store('redis');
    }
    /**
     * 更新用户代理信息缓存
     * @param array $userIds 用户ID数组
     */
    private function updateUserProxyCache(array $userIds)
    {
        $redis = $this->getRedisCache();
        $redisPrefix = 'telegram_task:';
        $expireTime = 86400; // 24小时过期
        
        // 批量获取最新的用户信息
        $users = MtuserModel::field('id, tdata_path, proxyip, archive')
            ->whereIn('id', $userIds)
            ->select()
            ->toArray();
        
        $userMap = array_column($users, null, 'id');
        
        // 逐个更新缓存
        foreach ($userIds as $userId) {
            $cacheKey = $redisPrefix . 'user:' . $userId;
            
            if (isset($userMap[$userId])) {
                // 用数据库最新数据更新缓存
                $redis->set(
                    $cacheKey,
                    json_encode($userMap[$userId], JSON_UNESCAPED_UNICODE),
                    $expireTime
                );
                Log::info("用户 {$userId} 的代理信息缓存已更新");
            } else {
                // 数据库中已不存在的用户，标记为删除
                $redis->set($cacheKey, json_encode(null), 600);
                Log::info("用户 {$userId} 不存在，代理信息缓存标记为删除");
            }
        }
    }

	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getUpdateInfo(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = SocktsModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete() {
		$idx = $this->request->post('id', '', 'serach_in');
		if (!$idx) throw new ValidateException('参数错误');
		
		try {
			// 开始事务
			Db::startTrans();
			
			// 获取要删除的代理ID列表
			$ids = explode(',', $idx);
			
			// 清除mtuser表中的代理信息（将sockts_id字段置空）
			//MtuserModel::where('socktsid', 'in', $ids)->update(['socktsid' => 0,'proxyip' => NULL]);
			
			// 删除代理记录
			SocktsModel::destroy(['id' => $ids], true);
			
			// 提交事务
			Db::commit();
			
			return json(['status' => 200, 'msg' => '操作成功']);
		} catch (\Exception $e) {
			// 回滚事务
			Db::rollback();
			throw new ValidateException('删除失败：' . $e->getMessage());
		}
	}    

    function detection() {
        // 获取前端传递的ID列表
        $idx = $this->request->post('id', '', 'serach_in');
        if (!$idx) {
            throw new ValidateException('参数错误');
        }
        
        // 初始化计数器和结果数组
        $successCount = 0;
        $failCount = 0;
        $successIds = [];  // 成功的代理ID
        $failIds = [];     // 失败的代理ID
        $failReasons = []; // 失败原因
        
        try {
            // 拆分ID字符串为数组
            $ids = explode(',', $idx);
            $sockts = SocktsModel::whereIn('id', $ids)->select()->toArray();
            
            foreach ($sockts as $sockt) {
                log::info($sockt['id']);
                $proxyId = $sockt['id'];
                // 构建代理字符串
                $ipPort = $sockt['ip'] . ':' . $sockt['port'];
                if ($sockt['username']) {
                    $proxy = "socks5://{$sockt['username']}:{$sockt['password']}@{$ipPort}";
                } else {
                    $proxy = "socks5://{$ipPort}";
                }
                
                try {
                    // 调用Python脚本检查代理
                    $pythonResult = $this->callPythonAccountChecker($proxy);
                    
                    if ($pythonResult['status']) {
                        // 检测成功
                        $successCount++;
                       
                    } else {
                        // 检测失败（Python返回失败状态）
                        throw new \Exception($pythonResult['message'] ?? '代理检测失败');
                    }
                } catch (\Exception $e) {
                    // 捕获当前代理的检测错误
                    $failCount++;
                    $failIds[] = $proxyId;
                    $failReasons[$proxyId] = $e->getMessage();
                    
                    // 更新状态为无效
                    MtuserModel::where('socktsid', $proxyId)->update([
                        'status' => 0,
                        'remask' => '代理无效'
                    ]);
                    $res = SocktsModel::where(['id'=>$proxyId])->update(['status'=>0]);
                    // 继续处理下一个代理，不中断循环
                    continue;
                }
            }
            
            // 全部处理完成后返回汇总结果
            return json([
                'status' => 200,
                'msg' => '检测完成',
                'data' => [
                    'total' => count($sockts),
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'success_ids' => $successIds,
                    'fail_ids' => $failIds
                ]
            ]);
        } catch (\Exception $e) {
            // 捕获整体流程错误（非单个代理错误）
            return json([
                'status' => 500,
                'msg' => '检测过程发生系统错误: ' . $e->getMessage()
            ]);
        }
    }
	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = SocktsModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = SocktsModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
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
	function dumpdata(){
		$page = $this->request->post('page', 1, 'intval');
		$limit = config('my.dumpsize') ? config('my.dumpsize') : 1000;

		$where = [];
		$where['id'] = ['in',$this->request->post('id', '', 'serach_in')];
		$where['username'] = $this->request->post('username', '', 'serach_in');
        
		$field = 'ip,port,username,password';

		$res = SocktsModel::where(formatWhere($where))->field($field)->order('id desc')->limit(($page-1)*$limit,$limit)->select()->toArray();
		$dpdata=[];
		foreach($res as $key=>$val){
			$formatted = $val['ip'].':'.$val['port'];
			
			// 仅当username存在时添加##和username
			if (!empty($val['username'])) {
				$formatted .= '##'.$val['username'];
				
				// 仅当password存在且username也存在时添加##和password
				if (!empty($val['password'])) {
					$formatted .= '##'.$val['password'];
				}
			}
			
			$dpdata[$key] = $formatted;
		}

		$data['status'] = 200;
		$data['header'] = explode(',','ip,port,username,password');
		$data['percentage'] = ceil($page * 100/ceil(SocktsModel::where(formatWhere($where))->count()/$limit));
		$data['filename'] = 'sockts导出';
		$data['data'] = $dpdata;
		return json($data);
	}
	public function import()
	{
		$data = $this->request->post();
		
		// 验证代理分组ID
		if (empty($data['skcateid'])) {
			return json(['status' => 400, 'msg' => '请选择代理分组']);
		}
		
		// 验证导入数据
		if (empty($data['data']) || !is_array($data['data'])) {
			return json(['status' => 400, 'msg' => '导入数据为空或格式不正确']);
		}
		
		$successCount = 0;
		$failCount = 0;
		$failItems = [];
		
		foreach ($data['data'] as $item) {
			// 验证IP格式
			/*if (!filter_var($item['ip'], FILTER_VALIDATE_IP)) {
				$failCount++;
				$failItems[] = $item;
				continue;
			}*/
			
			// 验证端口格式
			if (!is_numeric($item['port']) || $item['port'] < 1 || $item['port'] > 65535) {
				$failCount++;
				$failItems[] = $item;
				continue;
			}
			
			// 检查IP和端口组合是否已存在
			$exists = SocktsModel::where('ip', $item['ip'])
				->where('port', $item['port'])
				->where('username', $item['username'])
				->find();
				
			if ($exists) {
				$failCount++;
				$failItems[] = [
					'ip' => $item['ip'],
					'port' => $item['port'],
					'error' => 'IP和端口组合已存在'
				];
				continue;
			}
			
			// 准备数据
			$insertData = [
				'ip' => $item['ip'],
				'port' => $item['port'],
				'username' => $item['username'] ?? '',
				'password' => $item['password'] ?? '',
				'skcateid' => $data['skcateid'],
				'status' => 1,
				'addtime' => time(),
				'admin_id' => session('admin.user_id')
			];
			
			try {
				SocktsModel::create($insertData);
				$successCount++;
			} catch (\Exception $e) {
				$failCount++;
				$failItems[] = [
					'ip' => $item['ip'],
					'port' => $item['port'],
					'error' => $e->getMessage()
				];
			}
		}
		
		return json([
			'status' => 200,
			'msg' => "导入完成：成功 {$successCount} 条，失败 {$failCount} 条",
			'success_count' => $successCount,
			'fail_count' => $failCount,
			'fail_items' => $failItems
		]);
	}
	
	
	     /**
     * 调用Python脚本检查账号状态并获取auth_key
     */
    private function callPythonAccountChecker(string $proxy): array
    {
    
        $this->httpClient =  new Client([
            'timeout'         => 60.0,      // 整体超时时间
            'connect_timeout' => 10.0,      // 连接超时时间
            'read_timeout'    => 30.0,      // 读取超时时间
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'TelegramApiClient/1.0'
            ]
        ]);
        // Flask 服务的基础 URL
        $baseUrl = 'http://127.0.0.1:5000'; // 替换为你的 Flask 服务地址
        $url = $baseUrl . '/telegram_action';
        $action='test_proxy';
        // 基础请求数据
        $requestData = [
            'action'    => 'test_proxy',
            'api_id'    => config('telegram.api_id'),
            'api_hash'  => config('telegram.api_hash'),
            'proxy'  => $proxy
        ];

      

        try {
     
            $pythonCallStartTime = microtime(true);
            Log::info("开始时间：" . date('Y-m-d H:i:s'));    
            // 发送POST请求
            $response = $this->httpClient->post($url, [
                'json' => $requestData
            ]);
            log::debug('接口'.json_encode($requestData));
            // 获取响应内容
            $responseBody = $response->getBody()->getContents();
           
            
            $pythonCallEndTime = microtime(true);
            $pythonCallCost = round($pythonCallEndTime - $pythonCallStartTime, 2);
            Log::info("接口调用成功：耗时：{$pythonCallCost}秒");
            // 解析JSON响应
            $result = json_decode($responseBody, true);

            // 检查JSON解析错误
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = "JSON解析失败: " . json_last_error_msg();
                log::error($error);
                throw new \Exception($error);
            }

            // 检查接口返回状态
            if (empty($result['status'])) {
                $message = $result['message'] ?? '接口返回未知错误';
                log::error('接口操作失败');
                throw new \Exception("操作失败: {$message}");
            }

        
             log::info('接口响应'.json_encode($result));
            return $result;

        } catch (RequestException $e) {
            // 处理HTTP请求异常
            $errorDetails = [];
            $errorMessage = "请求接口失败: ";

            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorMessage .= "状态码: {$statusCode}, 错误内容: {$errorBody}";
                $errorDetails['status_code'] = $statusCode;
                $errorDetails['response_body'] = $errorBody;
            } else {
                $errorMessage .= $e->getMessage();
            }

            $errorDetails['action'] = $action;
            $errorDetails['exception'] = $e->getMessage();
            $errorDetails['trace'] = $e->getTraceAsString();
            
            log::error($errorMessage, $errorDetails);
            throw new \Exception($errorMessage);

        } catch (GuzzleException $e) {
            // 处理其他Guzzle异常
            $errorMessage = "Guzzle请求异常: " . $e->getMessage();
            log::error($errorMessage, [
                'action' => $action,
                'trace'  => $e->getTraceAsString()
            ]);
            throw new \Exception($errorMessage);

        } catch (\Exception $e) {
            // 处理其他异常
            log::error('接口调用发生异常'.json_encode([
                'action'  => $action,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]));
            throw $e;
        }
    
        
        
    }
}

