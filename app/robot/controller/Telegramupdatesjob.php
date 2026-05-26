<?php
//已优化
namespace app\robot\controller;
use think\facade\Db;
use think\facade\Log;
use think\captcha\Captcha;
use think\facade\Cache;

class Telegramupdatesjob extends Apibot
{
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();       
        $this->cacheBot = $this->cacheBot;
    }
    public function handle($data)
    {
        // 判断事件类型
        if ($data['event_type'] === 'bot_added_to_group') {
            $this->handleBotAddedToGroup($data);
        } elseif ($data['event_type'] === 'bot_removed_from_group') {
            $this->handleBotRemovedFromGroup($data);
        } elseif ($data['event_type'] === 'user_added_to_group') {
            $this->handleUserAddedToGroup($data);
        } elseif ($data['event_type'] === 'user_removed_from_group') {
            $this->handleUserRemovedFromGroup($data);
        }

    }

    // 处理机器人加入群组
    private function handleBotAddedToGroup($data)
    {
        $groupId = $data['group_id'];
        $groupName = $data['group_name'];
        $type = $data['group_type'];
        $username = $data['username'];
        $first_name = $data['first_name'];
        $botId = $data['user_id'];
        $RsRobot=Cache::store('redis')->get($this->cacheBot);      
        if ($RsRobot) {
            $rsgroup = Db::name('telegraggroup')->where('group_id', $groupId)->where('bot_id', $botId)->find();
            if (!$rsgroup) {
                // 存储群组信息到数据库
                Db::name('telegraggroup')->insert([
                    'group_id' => $groupId,
                    'title' => $groupName,
                    'type' => $type, 
                    'username' => $username,
                    'first_name' => $first_name,
                    'bot_id' => $botId,
                    'create_time' => time(),
                ]);
                
                $botToken = $data['token'];
                $messageId = $data['message_id'];
                $content = [
                    'chat_id' => $groupId,
                    'message_id' => $messageId
                ];
                $res=send($botToken, 'deleteMessage', $content);
            
            }
        }
    }

    // 处理机器人被移除群组
    private function handleBotRemovedFromGroup($data)
    {
        $groupId = $data['group_id'];
        $botId = $data['user_id'];
       
        $botinfo=Cache::store('redis')->get($this->cacheBot);     
         $botToken = $data['token'];
        $messageId = $data['message_id'];
        $content = [
            'chat_id' => $groupId,
            'message_id' => $messageId
        ];
       // log::info($messageId); 
        $res=send($botToken, 'deleteMessage', $content);
       // log::info($res); 
        
        if ($botinfo) {
            Db::name('telegraggroup')->where('group_id', $groupId)->where('bot_id', $botId)->delete();
           // Db::name('xxsetting')->where('group_id', $groupId)->where('user_id', $botId)->delete();
          //  Log::info("机器人被移除群组 (ID: {$groupId})(Bot: {$botId})");
        }
    }

    // 处理用户加入群组
    private function handleUserAddedToGroup($data)
    {
        $groupId = $data['group_id'];
        $userId = $data['user_id'];
        $messageId = $data['message_id'];
        $botToken = $data['token'];
        
        $text = '';
        $name =$data['first_name'];
        
        $content = [
            'chat_id' => $groupId,
            'message_id' => $messageId
        ];
        send($botToken, 'deleteMessage', $content);
        log::write($name);
        if($name)   { 
            log::write($name);
            if ($this->checkforbannedwords->checkForBannedWords($groupId, $text, $name,$userId, $botToken, $messageId)) {
               // Log::info("检测到违禁词，执行惩罚: $text");
                return;
            }
        }
    }

    // 处理用户被移除群组
    private function handleUserRemovedFromGroup($data)
    {
        $groupId = $data['group_id'];
        $userId = $data['user_id'];
        $messageId = $data['message_id'];
        $botToken = $data['token'];
        
        $content = [
            'chat_id' => $groupId,
            'message_id' => $messageId
        ];
        send($botToken, 'deleteMessage', $content);

      
    }

    // 检查用户是否在群组中
    private function isUserInGroup($groupId, $userId)
    {
        try {
          
            $redisKey = "group_user_joins:$groupId.$userId";
            return $this->redis->exists($redisKey);
        } catch (\Exception $e) {
            Log::error("Redis 错误: " . $e->getMessage());
            return false;
        }
    }

    // 将用户存储到 Redis 验证中
    private function storeMessageToRedis($chatId, $userId)
    {
       
        if ($userId) {
            $redisKey = "group_user_joins:$chatId.$userId";
            $this->redis->sadd($redisKey, $userId);
            $this->redis->expire($redisKey, 60 * 60);
            return true;
        } else {
            return false;
        }
    }

    // 生成数学验证码
    private function createMathCaptcha($chatId,$userId, $botToken)
    {
        $num1 = rand(1, 10);
        $num2 = rand(1, 10);
        $operation = rand(0, 1) ? '+' : '-';
        $this->sendCaptchaToTelegram($chatId,$userId, $num1, $num2, $operation, $botToken);
    }

    // 发送图形及按钮的函数
    private function sendCaptchaToTelegram($chatId,$userId, $num1, $num2, $operation, $botToken)
    {
        $imageData = $this->createCaptchaImage($num1, $num2, $operation);
        $correctAnswer = ($operation === '+') ? $num1 + $num2 : $num1 - $num2;

        $content = [
            'chat_id' => $chatId,
            'photo' => config('app.domainurl').'/' . $imageData,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => $correctAnswer, 'callback_data' => '/Joingroupcorrect:'.$userId],
                        ['text' => $correctAnswer + 1, 'callback_data' => '/Joingroupwrong:'.$userId],
                        ['text' => $correctAnswer - 1, 'callback_data' => '/Joingroupwrong:'.$userId]
                    ]
                ]
            ])
        ];
        send($botToken, 'sendPhoto', $content);
        unlink(public_path() . $imageData);
    }

    // 创建验证码图片
    private function createCaptchaImage($num1, $num2, $operation)
    {
        $text = "$num1 $operation $num2 = ?";
        $im = imagecreatetruecolor(200, 50);
        $bgColor = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $bgColor);
        $textColor = imagecolorallocate($im, 0, 0, 0);
        $fontPath = '/www/wwwroot/kelongbot.globaldoge.site/public/static/simhei.ttf';
        imagettftext($im, 20, 0, 30, 30, $textColor, $fontPath, $text);
        
        $fileName = 'captcha/' . uniqid() . '.png';
        $filePath = public_path() . $fileName;
        imagepng($im, $filePath);
        imagedestroy($im);
        
        return $fileName;
    }
}
