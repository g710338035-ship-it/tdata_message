<?php

namespace app\service;

use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use danog\MadelineProto\Settings;
use think\facade\Config;
use GuzzleHttp\Client;
use think\facade\Db;
use think\facade\Log;
use think\queue\Job;
use think\facade\Cache;

class TelegramService
{
    protected $task;
    protected $queue;

    public function __construct()
    {
        // 初始化队列实例
        //$this->queue = app('queue');
    }

    protected function getMadelineInstance($phoneNumber)
    {
        $config = Config::get('madelineproto');
        $settings = new Settings;
        $settings->getAppInfo()
            ->setApiId($config['api_id'])
            ->setApiHash($config['api_hash']);

        $sessionPath = $config['session_path'] . md5($phoneNumber) . '.madeline';

        // 添加调试日志，确认会话文件路径
        Log::info("会话文件路径: $sessionPath");
        // 检查会话目录是否存在，不存在则创建
        $sessionDir = dirname($sessionPath);
        if (!is_dir($sessionDir)) {
            if (!mkdir($sessionDir, 0777, true)) {
                Log::error("无法创建会话目录: $sessionDir");
                throw new \Exception("无法创建会话目录");
            }
        }
        // 检查会话目录权限
        if (!is_writable($sessionDir)) {
            if (!chmod($sessionDir, 0777)) {
                Log::error("无法修改会话目录权限: $sessionDir");
                throw new \Exception("无法修改会话目录权限");
            }
        }
 
        // 确保不会错误地创建目录
        /*if (is_dir($sessionPath)) {
            // 可以选择删除该目录或记录错误信息
            $this->recursiveDelete($sessionPath);
            Log::info("已删除错误创建的会话目录: $sessionPath");
        }*/
        // 检查会话文件是否存在
        /*if (!file_exists($sessionPath)) {
            Log::info("会话文件 $sessionPath 不存在，正在创建新文件。");
        } else {
            Log::info("会话文件 $sessionPath 存在。");
        }*/
      

        // 创建 MadelineProto 实例
        try {
            $madeline = new API($sessionPath, $settings);
            // 检查 $madeline 是否为有效的实例
            if (!$madeline instanceof API) {
                Log::info("未能创建有效的 MadelineProto 实例。");
                throw new \Exception('未能创建有效的 MadelineProto 实例。');
            } else {
                Log::info("有效的 MadelineProto 实例。");
            }
            return $madeline;
        } catch (\Exception $e) {
            Log::error('初始化 MadelineProto 时出错: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 发送登录验证码
     *
     * 向指定手机号发送 Telegram 登录验证码，并返回验证码哈希值
     *
     * @param string $phoneNumber 手机号
     * @return array 包含发送状态和验证码哈希值的数组
     */
    public function sendLoginCode($phoneNumber)
    {
        // 记录手机号信息
        Log::info("手机号: $phoneNumber");
        // 获取对应的 MadelineProto 实例
        $madeline = $this->getMadelineInstance($phoneNumber);
        if ($this->isLoggedIn($madeline)) {
            Log::info('用户已登录，无需发送登录验证码。');
            return [
                'status' => false,
                'message' => '用户已登录。'
            ];
        } else {
            // 获取 MadelineProto 配置
            $config = Config::get('madelineproto');
            // 生成唯一的会话文件名，使用手机号的 MD5 哈希值
            $sessionPath = $config['session_path'] . md5($phoneNumber) . '.madeline';
            // 记录会话文件路径信息
            Log::info("会话文件路径ffffffff: $sessionPath");
            try {
                // 发送登录验证码
                $sentCode = $madeline->phoneLogin($phoneNumber);
                // 记录验证码哈希值信息
                Log::info("验证码哈希值: " . $sentCode['phone_code_hash']);
                return [
                    'status' => true,
                    'phone_code_hash' => $sentCode['phone_code_hash']
                ];
            } catch (\Exception $e) {
                return ['status' => false, 'message' => $e->getMessage()];
            }
        }
    }

    /**
     * 验证登录验证码
     *
     * 使用指定的验证码和验证码哈希值验证手机号登录
     *
     * @param string $phoneNumber 手机号
     * @param string $code 验证码
     * @param string $phoneCodeHash 验证码哈希值
     * @return bool 验证成功返回 true，失败抛出异常
     * @throws \Exception 验证失败时抛出异常
     */
    public function verifyLoginCode($phoneNumber, $code, $phoneCodeHash, $pwd = NULL)
    {
        $madeline = $this->getMadelineInstance($phoneNumber);

        try {
            Log::info("开始验证手机号 {$phoneNumber} 的登录验证码");
            $authorization = $madeline->completePhoneLogin($code, $phoneCodeHash);
            Log::info("验证码验证结果：" . json_encode($authorization));
            if ($authorization['_'] === 'account.noPassword') {
                throw new \Exception('该账户启用了两步验证');
            } elseif ($authorization['_'] === 'account.password') {
                if (!$pwd) {
                    throw new \Exception('需要输入两步验证密码');
                }
                Log::info("开始验证手机号 {$phoneNumber} 的两步验证密码");
                $authorization = $madeline->complete2faLogin($pwd);
                Log::info("两步验证密码验证结果：" . json_encode($authorization));
            }

            if (!in_array($authorization['_'], ['account.authorization', 'auth.authorization'])) {
                throw new \Exception('登录失败，未知错误');
            }

            Log::info("手机号 {$phoneNumber} 登录成功");
            return true;
        } catch (\Exception $e) {
            Log::error("登录验证失败：" . $e->getMessage());
            return false;
        }
    }


   /**
 * 登出账号
 *
 * 为指定手机号的 Telegram 账号执行登出操作，并删除会话文件
 *
 * @param string $phoneNumber 手机号
 * @return bool 登出成功返回 true，已登出返回 false
 * @throws \Exception 登出过程中出现异常时抛出异常
 */
public function logout($phoneNumber)
{
    // 获取会话文件或目录路径
    $config = Config::get('madelineproto');
    $sessionPath = $config['session_path'] . md5($phoneNumber) . '.madeline';
    Log::info("手号 $sessionPath 。");
    // 检查会话文件或目录是否存在
    if (!file_exists($sessionPath)) {
        Log::info("手机号 $phoneNumber 对应的会话文件或目录不存在，可能已登出。");
        return ['status' => 400, 'msg' => '手机号 对应的会话文件或目录不存在，可能已登出。'];
    }else{

    $madeline = $this->getMadelineInstance($phoneNumber);
    try {
        $isLoggedIn = $this->isLoggedIn($madeline);
        if ($isLoggedIn) {
            // 执行登出操作
            $madeline->logout();
        }

        // 删除会话文件或目录
        if (is_file($sessionPath)) {
            unlink($sessionPath);
            Log::info("已删除手机号 $phoneNumber 对应的会话文件。");
        } elseif (is_dir($sessionPath)) {
            // 递归删除目录
            $this->recursiveDelete($sessionPath);
            Log::info("已删除手机号 $phoneNumber 对应的会话目录。");
        }

        if ($isLoggedIn) {
            return ['status' => 200, 'msg' => '手机号 '.$phoneNumber.' 登出成功。'];
        } else {
            return ['status' => 200, 'msg' => '手机号已退出。'];
        }
    } catch (\Exception $e) {
        Log::error("登出操作出错: ". $e->getMessage());
        return ['status' => 500, 'msg' => '登出操作出错: '. $e->getMessage()];
    }
    }
}


// 递归删除目录的方法
private function recursiveDelete($dir)
{
    $files = new \FilesystemIterator($dir);
    foreach ($files as $file) {
        if ($file->isDir()) {
            $this->recursiveDelete($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($dir);
}
    /**
     * 判断是否已登录
     *
     * 检查指定 MadelineProto 实例对应的账号是否已登录
     *
     * @param API $madeline MadelineProto 实例
     * @return bool 已登录返回 true，否则返回 false
     */
    public function isLoggedIn($madeline)
    {
        try {
            $authorization = $madeline->getSelf();
            Log::debug('授权数据: ' . json_encode($authorization));

            if (is_array($authorization) && isset($authorization['id'])) {
                Log::info('已使用 ID: ' . $authorization['id'] . ' 登录。');
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::error('检查登录状态时出错: ' . $e->getMessage());
            return false;
        }
    }
}