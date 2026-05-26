<?php
namespace app\job;

use think\queue\Job;
use app\admin\model\Mtuser as MtuserModel;
use think\facade\Log;
use think\facade\Db;
use app\admin\controller\Mtarchive;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Cache;
class MtuserHandleJob
{
    // 增加批量处理支持
    private $batchSize = 5;
    private static $processedAccounts = [];
    // 数据库插入缓冲区
    private $insertBuffer = [];
    private $failedBuffer = [];
    private $httpClient;
    /**
     * 执行任务
     * @param Job $job 任务对象
     * @param array $data 任务数据
     */
    public function fire(Job $job, $data)
    {
        $success = false;
        
        try {
            // 验证任务数据
            /*if (empty($data['phone']) || empty($data['accountDir']) || empty($data['cateid'])) {
                throw new \Exception('任务数据不完整: ' . json_encode($data));
            }*/
            self::$processedAccounts[] = $data['phone'];
            
            //Log::info("开始处理账号任务: {$data['phone']}");
            
            // 检查账号是否已存在
            $existingUser = MtuserModel::where('account', $data['phone'])->find();
            if (!$existingUser) {
                 $tdataDir = $this->findTdataInAccountDir($data['accountDir']);
                if (!$tdataDir) {
                    $data['accountDir']=$data['accountDir'] . '/tdata';
                    $data['account_status']= '空号';
                    $data['account_status_desc']= '未找到tdata目录';
                    $this->recordFailedAccount($data, '未找到tdata目录');
                }else{
                    $pythonResult = $this->callPythonAccountChecker($tdataDir, $data['phone']);
                    Log::info("Python返回结果：" . json_encode($pythonResult));
                    $this->saveAccountInfo($data, $tdataDir, $pythonResult);
                    $success=true;
                }
            }

            
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
             Log::error("Telegram任务执行失败: " . $e->getMessage() . "，数据:" . json_encode($data));
        }
        
        // 更新进度
        $this->updateProgress($data['batch_id'], $success);
        $job->delete();
        
    }
    

    
private function updateProgress($batchId, $isSuccess)
{
    $cacheKey = "task_{$batchId}_progress";
    $redis = Cache::store('redis')->handler();
    
    if (!$redis->exists($cacheKey)) {
        Log::error("缓存键不存在: {$cacheKey}");
        return;
    }
    
    // 记录递增前的值
    $beforeCompleted = $redis->hGet($cacheKey, 'completed');
    
    
    // 执行递增并获取返回值
    $completed = $redis->hIncrBy($cacheKey, 'completed', 1);
    $result = $redis->hIncrBy($cacheKey, $isSuccess ? 'success' : 'failed', 1);
    
    
    $total = $redis->hGet($cacheKey, 'total');
    
    if ((int)$completed >= (int)$total) {
        $redis->hMSet($cacheKey, [
            'status'   => 'completed',
            'end_time' => time()
        ]);
   
    }
}

    /**
     * 查找tdata目录
     */
    private function findTdataInAccountDir(string $accountDir): ?string
    {
        $tdataPath = $accountDir . '/tdata';
        // 使用缓存结果减少IO操作
        static $tdataCache = [];
        
        if (isset($tdataCache[$tdataPath])) {
            return $tdataCache[$tdataPath];
        }
        
        // 检查目录是否存在
        if (is_dir($tdataPath)) {
            $tdataCache[$tdataPath] = $tdataPath;
            return $tdataPath;
        }
        
        // 尝试其他可能的路径
        $alternativePaths = [
            $accountDir . '/Tdata',
            $accountDir . '/TDATA',
            $accountDir
        ];
        
        foreach ($alternativePaths as $path) {
            if (is_dir($path)) {
                $tdataCache[$tdataPath] = $path;
                return $path;
            }
        }
        
        $tdataCache[$tdataPath] = null;
        return null;
    }
    
    
    /**
     * 调用Python HTTP接口检查账号（替代原本地脚本执行）
     * @param string $tdataPath tdata目录绝对路径
     * @param string $phone 关联手机号（可非标准格式，由Python接口处理）
     * @return array Python接口返回的账号信息数组
     * @throws \Exception 接口调用失败、参数错误、解析失败等异常
     */
    private function callPythonAccountChecker(string $tdataPath, string $phone): array
    {
        $this->httpClient = $httpClient ?? new Client([
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
        $url = $baseUrl . '/check_account';
        
        // 准备请求参数
        $postData = [
            'tdata_path' => $tdataPath,
            'tdata_phone' => $phone,
            'api_id' => config('telegram.api_id'),
            'api_hash' => config('telegram.api_hash'),
            'session_type' => 'file',
            'prefer_ipv6' => false
        ];
        try {
            // 记录请求信息
            /*log::info('调用Telegram接口', [
                'url'    => $url,
                'action' => $action,
                'params' => $requestData
            ]);*/

            // 发送POST请求
            $response = $this->httpClient->post($url, [
                'json' => $postData
            ]);

            // 获取响应内容
            $responseBody = $response->getBody()->getContents();
           // log::debug('接口响应原始数据'.$responseBody);

            // 解析JSON响应
            $result = json_decode($responseBody, true);
            
            // 检查JSON解析错误
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = "JSON解析失败: " . json_last_error_msg();
                log::error($error, [
                    'action' => $action,
                    'response' => $responseBody
                ]);
                throw new \Exception($error);
            }


            log::info('接口调用成功');

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
            log::error('接口调用发生异常', [
                'action'  => $action,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);
            throw $e;
        }
        
        
        
    }
    /**
     * 保存账号信息到数据库
     */
    private function saveAccountInfo(array $data, string $tdataDir, array $pythonResult)
    {
        $tdataRelPath = str_replace(public_path(), '', $tdataDir);
        
        $finalAccount = $pythonResult['phone'] ?? $data['phone'];
        $insertData = [
            'cateid' => $data['cateid'],
            'account' => $finalAccount,
            'tdata_path' => $tdataRelPath,
            'status' => $pythonResult['status'] ?? 0,
            'auth_key' => $pythonResult['auth_key'] ?? null,
            'uuid' => $pythonResult['user_id'] ?? null,
            'username' => $pythonResult['username'] ?? null,
            'nickName' => $pythonResult['nickname'] ?? null,
            'region' => $pythonResult['country'] ?? null,
            'is_authorized' => $pythonResult['is_authorized'] ?? false,
            'session_path' => isset($pythonResult['session_path']) ? $pythonResult['session_path'] : null,
            'remark' => $pythonResult['message'] ?? '',
            'addtime' => time(),
            'online' => $pythonResult['online'] ?? 0,
            'admin_id' => $data['userid'] ?? 0,
            'task_status' => 2, // 任务完成
            'task_error' => '',
            'account_status' => $pythonResult['account_status'] ?? null,
            'account_status_desc' => $pythonResult['account_status_desc'] ?? null,
        ];
        
        // 添加到缓冲区
        $this->insertBuffer[] = $insertData;
        
        // 达到批量大小则执行插入
        if (count($this->insertBuffer) >= $this->batchSize) {
            $this->flushInsertBuffer();
        }

    }
    
   
    /**
     * 记录处理失败的账号
     */
    private function recordFailedAccount(array $data, string $errorMsg)
    {
        $insertData = [
            'cateid' => $data['cateid'] ?? 0,
            'account' => $data['phone'],
            'tdata_path' => $data['accountDir'] ?? '',
            'status' => 0,
            'remark' => '任务处理失败',
            'addtime' => time(),
            'admin_id' => $data['userid'] ?? 0,
            'task_status' => 3, // 任务失败
            'task_error' => $errorMsg,
            'account_status' => $data['account_status'] ?? '异常',
            'account_status_desc' => $data['account_status_desc'] ?? $errorMsg,
        ];
        
        $this->failedBuffer[] = $insertData;
        $this->flushFailedBuffer();
    }
     /**
     * 刷新插入缓冲区，执行批量插入
     */
    private function flushInsertBuffer()
    {
        if (!empty($this->insertBuffer)) {
            try {
                Db::startTrans();
                MtuserModel::insertAll($this->insertBuffer);
                Db::commit();
                Log::info("批量插入成功，共 " . count($this->insertBuffer) . " 条记录");
            } catch (\Exception $e) {
                Db::rollback();
                Log::error("批量插入失败: " . $e->getMessage());
                // 尝试逐条插入，避免全部失败
                foreach ($this->insertBuffer as $item) {
                    try {
                        MtuserModel::create($item);
                    } catch (\Exception $e) {
                        Log::error("单条插入失败: " . $e->getMessage() . "，数据: " . json_encode($item));
                    }
                }
            }
            $this->insertBuffer = [];
        }
    }
    
    /**
     * 刷新失败缓冲区，执行批量插入
     */
    private function flushFailedBuffer()
    {
        if (!empty($this->failedBuffer)) {
            try {
                Db::startTrans();
                MtuserModel::insertAll($this->failedBuffer);
                Db::commit();
                Log::info("批量插入失败记录成功，共 " . count($this->failedBuffer) . " 条记录");
            } catch (\Exception $e) {
                Db::rollback();
                Log::error("批量插入失败记录失败: " . $e->getMessage());
                // 尝试逐条插入
                foreach ($this->failedBuffer as $item) {
                    try {
                        MtuserModel::create($item);
                    } catch (\Exception $e) {
                        Log::error("单条插入失败记录失败: " . $e->getMessage() . "，数据: " . json_encode($item));
                    }
                }
            }
            $this->failedBuffer = [];
        }
    }
    
    /**
     * 析构函数，确保缓冲区中剩余数据被插入
     */
    public function __destruct()
    {
        // 处理剩余的缓冲数据
        $this->flushInsertBuffer();
        $this->flushFailedBuffer();
    }
}
