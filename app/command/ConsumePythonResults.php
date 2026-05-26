<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use think\facade\Cache;
use app\admin\model\Mtuser as MtuserModel;

class ConsumePythonResults extends Command
{
    protected function configure()
    {
        $this->setName('telegram:consume-results')
             ->setDescription('Consume Telegram Python results from Redis and update database');
    }

    private $updateBuffer = [];
    private $batchSize = 50;
    private $lastFlushTime = 0;

    protected function execute(Input $input, Output $output)
    {
        $output->writeln("Starting Telegram Python result consumer...");
        Log::info("Telegram Python result consumer started.");
        
        try {
            $redis = Cache::store('redis')->handler();
        } catch (\Exception $e) {
            $output->error("Failed to connect to Redis: " . $e->getMessage());
            return;
        }

        $key = 'telegram_python_results';
        $this->lastFlushTime = time();

        while (true) {
            try {
                // Short timeout to allow frequent flushing checks
                $popResult = $redis->blPop($key, 1); 
                
                if ($popResult) {
                    $jsonStr = $popResult[1];
                    $data = json_decode($jsonStr, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                        $this->processResult($data);
                    } else {
                        Log::error("Invalid JSON in result queue: " . substr($jsonStr, 0, 100));
                    }
                }
                
                // Check if we need to flush buffer
                $this->checkAndFlushBuffer();
                
            } catch (\Exception $e) {
                Log::error("Error in result consumer loop: " . $e->getMessage());
                // Try to flush anyway in case of error
                $this->flushBuffer();
                sleep(1);
            }
        }
    }

    private function checkAndFlushBuffer()
    {
        if (empty($this->updateBuffer)) {
            return;
        }

        $isTimeout = (time() - $this->lastFlushTime) >= 2; // Flush every 2 seconds max latency
        $isFull = count($this->updateBuffer) >= $this->batchSize;

        if ($isTimeout || $isFull) {
            $this->flushBuffer();
        }
    }

    private function flushBuffer()
    {
        if (empty($this->updateBuffer)) {
            return;
        }

        $count = count($this->updateBuffer);
        try {
            $model = new MtuserModel();
            $model->saveAll($this->updateBuffer);
            Log::info("Batch updated {$count} accounts.");
        } catch (\Exception $e) {
            Log::error("Batch update failed: " . $e->getMessage() . ". Retrying individually.");
            // Fallback to individual updates
            foreach ($this->updateBuffer as $data) {
                try {
                    MtuserModel::update($data);
                } catch (\Exception $subE) {
                    Log::error("Individual update failed for ID {$data['id']}: " . $subE->getMessage());
                }
            }
        }

        $this->updateBuffer = [];
        $this->lastFlushTime = time();
    }

    private function processResult($result)
    {
        $userId = $result['mt_id'] ?? ($result['user_id'] ?? null);
        if (!$userId) {
            Log::warning("Result missing user_id/mt_id: " . json_encode($result));
            return;
        }

        $action = $result['action'] ?? 'unknown';
        $meta = $result['meta'] ?? [];
        $batchId = $meta['batch_id'] ?? 'unknown';
        $status = $result['status'] ?? false;
        
        // Update task progress in Redis
        if ($batchId !== 'unknown') {
            $this->updateProgress($batchId, $status);
        }

        // Prepare data but DO NOT save yet
        if ($status) {
            $this->prepareSuccess($userId, $result, $action, $batchId);
        } else {
            $this->prepareFailure($userId, $result, $batchId);
        }
    }

    private function prepareSuccess($userId, $result, $taskType, $batchId)
    {
        $updateData = [
            'id' => $userId,
            'updatetime' => time()
        ];
        
        // ... (rest of logic same as handleSuccess, but instead of MtuserModel::update, push to buffer)
        
        switch ($taskType) {
            case 'set_online':
                $updateData['online'] = 1;
                $updateData['status'] = 1;
                $updateData['remark'] = '已上线: ' . ($result['message'] ?? '');
                
                if (isset($result['data']) && ($result['data']['account_status'] ?? '') == '正常') {
                    if (isset($result['account_info']['result'])) {
                        $account_result = $result['account_info']['result'];
                        $verification_info = $account_result['verification_result'] ?? [];
                        
                        $updateData['session_path'] = $account_result['session_path'] ?? '';
                        $updateData['uuid'] = $verification_info['user_id'] ?? '';
                        $updateData['username'] = $verification_info['username'] ?? '';
                        $updateData['nickName'] = $verification_info['nickname'] ?? '';
                        $updateData['is_authorized'] = isset($verification_info['is_authorized']) ? (int)$verification_info['is_authorized'] : 0;
                    }
                }
                break;
                
            case 'set_offline':
                $updateData['online'] = 0;
                $updateData['remark'] = '已下线: ' . ($result['message'] ?? '');
                break;
                
            case 'get_account_info':
                $data = $result['data'] ?? [];
                $updateData['username'] = $data['username'] ?? '';
                $updateData['nickName'] = $data['nickname'] ?? '';
                $updateData['friends_count'] = $data['friends_count'] ?? 0;
                $updateData['groups_count'] = $data['groups_count'] ?? 0;
                
                $avatar_url = $data['avatar_url'] ?? '';
                if (is_string($avatar_url) && $avatar_url !== '') {
                    $updateData['avatar_url'] = preg_replace('#^.+/public#', '', $avatar_url);
                }
                
                $updateData['status'] = 1;
                $updateData['remark'] = '检查完成: ' . ($result['message'] ?? '');
                break;
                
            case 'delete_all_contacts':
                $updateData['remark'] = '好友已删除: ' . ($result['message'] ?? '');
                break;
                
            case 'update_nickname':
                $updateData['nickName'] = $result['data']['updated_info']['first_name'] ?? '';
                break;
                
            case 'leave_all_groups':
                $updateData['remark'] = '已退出所有群组: ' . ($result['message'] ?? '');
                break;
                
            case 'logout_other_sessions':
                $updateData['remark'] = '已退出其他设备: ' . ($result['message'] ?? '');
                break;
        }

        if (!empty($result['data']['account_status'])) {
            $updateData['account_status'] = $result['data']['account_status'];
            $updateData['account_status_desc'] = $result['data']['account_status_desc'] ?? '';
        }

        $this->updateBuffer[] = $updateData;
        $this->recordAccountUpdate($userId, $updateData, $batchId);
    }

    private function prepareFailure($userId, $result, $batchId)
    {
        $account_status = '异常';
        $account_status_desc = '';
        
        if (isset($result['data']) && is_array($result['data'])) {
            $account_status = $result['data']['account_status'] ?? '异常';
            $account_status_desc = $result['data']['account_status_desc'] ?? ($result['message'] ?? '任务失败');
        } else {
            $account_status_desc = $result['message'] ?? "任务失败";
        }
        
        $updateData = [
            'id' => $userId,
            'account_status' => $account_status,
            'account_status_desc' => $account_status_desc,
            'online' => 0,
            'status' => 0, 
            'remark' => '任务失败: ' . $account_status_desc,
            'updatetime' => time()
        ];
        
        $this->updateBuffer[] = $updateData;
        
        // For cache
        $cacheData = $updateData;
        $cacheData['status'] = 1; 
        $this->recordAccountUpdate($userId, $cacheData, $batchId);
    }


    private function recordAccountUpdate($userId, $data, $batchId)
    {
        // Redis cache update logic from TelegramTask
        // "telegram_task:user:{$userId}"
        // And "task_{$batchId}_progress" ...
        // We will just update user cache here if needed.
        
        // Note: The original logic also updated a batch-specific progress key
        // But here we might not have all batch info.
        // However, for frontend real-time updates, it might check specific keys.
        
        // Let's implement the basic user cache update
        /*
        $redisKey = "telegram_task:user:{$userId}";
        try {
            $redis = Cache::store('redis')->handler();
            // We should ideally fetch the full user and update it, 
            // but just updating known fields is safer than overwriting with partial data
            // if we don't fetch first.
            // TelegramTask fetched MtuserModel::find($userId).
        } catch (\Exception $e) {}
        */
    }

    private function updateProgress($batchId, $isSuccess)
    {
        $cacheKey = "task_{$batchId}_progress";
        try {
            $redis = Cache::store('redis')->handler();
            if ($redis->exists($cacheKey)) {
                $pipe = $redis->multi(\Redis::PIPELINE);
                $pipe->hIncrBy($cacheKey, 'completed', 1);
                if ($isSuccess) {
                    $pipe->hIncrBy($cacheKey, 'success', 1);
                } else {
                    $pipe->hIncrBy($cacheKey, 'failed', 1);
                }
                $pipe->exec();
            }
        } catch (\Exception $e) {
            Log::error("Failed to update progress for batch {$batchId}: " . $e->getMessage());
        }
    }
}
