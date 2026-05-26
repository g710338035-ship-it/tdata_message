<?php
namespace app\mproto\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Config;
use think\facade\Request;
use app\BaseController;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use danog\MadelineProto\Settings;
use think\facade\Cache;
use think\facade\Queue;

/**
 * MadelineProto 8.x 完整功能控制器
 */
class Mptest extends BaseController
{
    // 存储 session 文件路径的配置键
    private const SESSION_CONFIG_KEY = 'telegram.active_sessions';

    public function __construct()
    {
        // 设置时区，避免时间相关问题
        //date_default_timezone_set('Asia/Shanghai');
    }

    /**
     * 默认提示
     */
    public function index()
    {
        
    }

  
    /**
 * 登录（支持 tdata / session）
 *
 * @param string $type tdata/session
 * @param string $path tdata 目录或 session 文件路径
 * @param string $bot_token 可选，机器人 token
 */
    public function login($type = 'session',$path = '/www/wwwroot/tdata.tgbota.top/runtime/telegram_tdata/14504401114/tdata') 
    {
            
        }

/**
 * 确保路径在允许的基础目录内，防止 open_basedir 限制
 */
private function getSafePath($path, $baseDir)
{
    // 检查路径是否已经在允许的目录内
    if (str_starts_with(realpath($path), realpath($baseDir))) {
        return $path;
    }
    
    // 如果不在允许的目录内，尝试使用相对路径
    $relativePath = ltrim(str_replace(realpath('/www/wwwroot/tdata.tgbota.top'), '', realpath($path)), '/');
    $safePath = $baseDir . $relativePath;
    
    // 验证构建的路径是否存在
    if (file_exists($safePath)) {
        return $safePath;
    }
    
    // 如果路径构建失败，使用临时目录
    $tempDir = '/tmp/telegram_temp/';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    // 生成临时文件
    $tempFile = $tempDir . md5($path) . '_' . basename($path);
    
    // 尝试复制文件到临时目录（如果是文件）
    if (is_file($path)) {
        copy($path, $tempFile);
        return $tempFile;
    }
    
    // 如果是目录，创建临时目录
    if (is_dir($path)) {
        mkdir($tempFile, 0777, true);
        return $tempFile;
    }
    
    // 如果无法处理，抛出异常
    throw new \Exception('路径不在允许的范围内，且无法安全处理: ' . $path);
}
    /**
     * 退出账号
     *
     * @param string $session session 文件路径
     */
    public function logout($session = '')
    {
        if (empty($session) || !is_file($session)) {
            return json([
                'status' => 'error',
                'message' => 'session 文件不存在'
            ]);
        }

        try {
            $madeline = new API($session);
            $madeline->start();
            
            // 获取用户信息以删除对应的配置
            $me = $madeline->getSelf();
            $userId = $me['id'];
            
            // 退出登录
            $madeline->logout();
            
            // 删除 session 文件
            if (is_file($session)) {
                unlink($session);
            }
            
            // 从配置中移除 session 路径
            $this->removeSessionPath($userId);
            
            return json([
                'status' => 'success',
                'message' => '退出并删除 session 成功'
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram 退出失败: ' . $e->getMessage());
            return json([
                'status' => 'error',
                'message' => '退出失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取账号信息
     *
     * @param string $session
     */
    public function status($session = '')
    {
        if (empty($session) || !is_file($session)) {
            return json([
                'status' => 'error',
                'message' => 'session 文件不存在'
            ]);
        }

        try {
            $madeline = new API($session);
            $madeline->start();

            $me = $madeline->getSelf();

            return json([
                'status' => 'success',
                'data' => [
                    'id' => $me['id'],
                    'username' => $me['username'] ?? '',
                    'phone' => $me['phone'] ?? '',
                    'first_name' => $me['first_name'] ?? '',
                    'last_name' => $me['last_name'] ?? '',
                    'online_status' => $me['status']['_'] ?? 'unknown'
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('获取 Telegram 状态失败: ' . $e->getMessage());
            return json([
                'status' => 'error',
                'message' => '获取状态失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 发送消息
     *
     * @param string $session
     * @param string|int $peer_id
     * @param string $msg
     * @param array $options 额外选项，如 reply_to_msg_id, parse_mode 等
     */
    public function sendMessage($session = '', $peer_id = '', $msg = '', $options = [])
    {
        if (empty($session) || !is_file($session)) {
            return json([
                'status' => 'error',
                'message' => 'session 文件不存在'
            ]);
        }

        if (empty($peer_id) || empty($msg)) {
            return json([
                'status' => 'error',
                'message' => '请传入 peer_id 和 msg'
            ]);
        }

        try {
            $madeline = new API($session);
            $madeline->start();

            $params = [
                'peer' => $peer_id,
                'message' => $msg,
                'parse_mode' => $options['parse_mode'] ?? 'markdown',
            ];
            
            // 添加可选参数
            if (isset($options['reply_to_msg_id'])) {
                $params['reply_to_msg_id'] = $options['reply_to_msg_id'];
            }
            
            if (isset($options['disable_web_page_preview'])) {
                $params['disable_web_page_preview'] = (bool)$options['disable_web_page_preview'];
            }
            
            if (isset($options['silent'])) {
                $params['silent'] = (bool)$options['silent'];
            }

            $result = $madeline->messages->sendMessage($params);

            return json([
                'status' => 'success',
                'message' => '消息发送成功',
                'message_id' => $result['id'] ?? null
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram 发送消息失败: ' . $e->getMessage());
            return json([
                'status' => 'error',
                'message' => '发送失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 启动消息监听器
     *
     * @param string $session
     */
    public function startListener($session = '')
    {
        if (empty($session) || !is_file($session)) {
            return json([
                'status' => 'error',
                'message' => 'session 文件不存在'
            ]);
        }

        try {
            // 检查是否已有监听进程
            $processKey = 'telegram_listener_' . md5($session);
            if (Cache::get($processKey)) {
                return json([
                    'status' => 'error',
                    'message' => '已存在监听进程'
                ]);
            }

            // 设置事件处理器
            $settings = new Settings;
            $settings->getUpdate()->setEventHandler(MessageHandler::class);
            
            // 后台运行监听进程
            Queue::push(function() use ($session, $settings, $processKey) {
                try {
                    // 标记进程为运行中
                    Cache::set($processKey, true, 86400);
                    
                    $madeline = new API($session, $settings);
                    $madeline->start();
                    
                    // 记录启动信息
                    Log::info("Telegram 消息监听器已启动: {$session}");
                    
                    // 开始处理更新
                    $madeline->loop();
                } catch (\Throwable $e) {
                    Log::error("Telegram 监听进程异常: " . $e->getMessage());
                } finally {
                    // 进程结束，清除标记
                    Cache::delete($processKey);
                }
            }, [], 'telegram');

            return json([
                'status' => 'success',
                'message' => '消息监听器已启动（后台运行）'
            ]);
        } catch (\Throwable $e) {
            Log::error('启动 Telegram 监听器失败: ' . $e->getMessage());
            return json([
                'status' => 'error',
                'message' => '启动监听器失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 停止消息监听器
     *
     * @param string $session
     */
    public function stopListener($session = '')
    {
        if (empty($session) || !is_file($session)) {
            return json([
                'status' => 'error',
                'message' => 'session 文件不存在'
            ]);
        }

        try {
            $processKey = 'telegram_listener_' . md5($session);
            
            // 检查是否有监听进程
            if (!Cache::get($processKey)) {
                return json([
                    'status' => 'error',
                    'message' => '没有正在运行的监听进程'
                ]);
            }

            // 清除进程标记，让进程自然结束
            Cache::delete($processKey);
            
            // 向消息队列发送停止信号
            Queue::push(function() use ($session) {
                try {
                    $madeline = new API($session);
                    $madeline->stop();
                    Log::info("Telegram 消息监听器已停止: {$session}");
                } catch (\Throwable $e) {
                    Log::error("停止 Telegram 监听器失败: " . $e->getMessage());
                }
            }, [], 'telegram');

            return json([
                'status' => 'success',
                'message' => '已发送停止监听器的请求'
            ]);
        } catch (\Throwable $e) {
            Log::error('停止 Telegram 监听器失败: ' . $e->getMessage());
            return json([
                'status' => 'error',
                'message' => '停止监听器失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 保存 session 路径到配置
     *
     * @param int $userId 用户 ID
     * @param string $sessionPath session 文件路径
     */
    private function saveSessionPath($userId, $sessionPath)
    {
        $sessions = Config::get(self::SESSION_CONFIG_KEY, []);
        $sessions[$userId] = $sessionPath;
        Config::set(self::SESSION_CONFIG_KEY, $sessions);
    }

    /**
     * 从配置中移除 session 路径
     *
     * @param int $userId 用户 ID
     */
    private function removeSessionPath($userId)
    {
        $sessions = Config::get(self::SESSION_CONFIG_KEY, []);
        if (isset($sessions[$userId])) {
            unset($sessions[$userId]);
            Config::set(self::SESSION_CONFIG_KEY, $sessions);
        }
    }
}

/**
 * 消息事件处理器
 */
class MessageHandler extends EventHandler
{
    /**
     * 处理新消息
     *
     * @param array $update 消息更新
     */
    public function onUpdateNewMessage(array $update)
    {
        // 忽略自己发送的消息
        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }

        try {
            // 提取消息信息
            $message = $update['message'];
            $peer = $message['peer_id'];
            $text = $message['message'] ?? '';
            
            // 记录消息日志
            $senderId = $message['from_id']['user_id'] ?? 'unknown';
            Log::info("收到 Telegram 消息: 来自 {$senderId}, 内容: {$text}");
            
            // 简单的消息回复逻辑
            if (strpos($text, '/start') === 0) {
                $this->messages->sendMessage([
                    'peer' => $peer,
                    'message' => '你好！我是自动回复机器人。',
                ]);
            } elseif (strpos($text, '/help') === 0) {
                $this->messages->sendMessage([
                    'peer' => $peer,
                    'message' => '支持的命令: /start, /help, /info',
                ]);
            } elseif (strpos($text, '/info') === 0) {
                $info = "消息信息:\n";
                $info .= "ID: {$message['id']}\n";
                $info .= "发送者: {$senderId}\n";
                $info .= "时间: " . date('Y-m-d H:i:s', $message['date']) . "\n";
                
                $this->messages->sendMessage([
                    'peer' => $peer,
                    'message' => $info,
                ]);
            } else {
                // 默认回复
                $this->messages->sendMessage([
                    'peer' => $peer,
                    'message' => "我收到了你的消息: {$text}\n但我暂时无法回复更多内容。",
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("处理 Telegram 消息失败: " . $e->getMessage());
        }
    }
}