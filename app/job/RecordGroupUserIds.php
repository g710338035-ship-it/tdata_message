<?php
namespace app\job;

use app\mproto\controller\Mpapi;
use think\queue\Job;
use think\facade\Log;

class RecordGroupUserIds
{
    public function fire(Job $job, $data)
    {
        if (!isset($data['accountName'])) {
            Log::error("RecordGroupUserIds job failed: 'accountName' is missing in the data.");
            $job->delete();
            return;
        }

        $accountName = $data['accountName'];
        Log::info("RecordGroupUserIds job started with account name: ". $accountName);

        $mpapi = new Mpapi();
        $instance = null;
        try {
            $instance = $mpapi->getMadelineInstance($accountName);
            $result = $mpapi->recordGroupUserIds($accountName, $instance);

            if ($result) {
                Log::info("RecordGroupUserIds job completed successfully for account: ". $accountName);
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    Log::error("RecordGroupUserIds job failed after 3 attempts for account: ". $accountName);
                    $job->delete();
                } else {
                    Log::warning("RecordGroupUserIds job failed, retrying in 60 seconds for account: ". $accountName);
                    $job->release(60); 
                }
            }
        } catch (\Exception $e) {
            Log::error("Error in RecordGroupUserIds job for account '$accountName': ". $e->getMessage());
            if ($job->attempts() > 3) {
                $job->delete();
            } else {
                $job->release(60); 
            }
        } finally {
            // 确保在任务结束时关闭实例
            if ($instance) {
                try {
                    $instance->destroy();
                } catch (\Exception $e) {
                    Log::error("Error destroying MadelineProto instance for account '$accountName': ". $e->getMessage());
                }
            }
        }
    }
}