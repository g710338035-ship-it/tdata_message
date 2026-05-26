<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class PushMessage extends Command
{
    protected function configure()
    {
        $this->setName('telegram:pushMessage')
             ->setDescription('Push scheduled messages to telegram groups');
    }

    protected function execute(Input $input, Output $output)
    {
        $nowTime = date("H:i:s");

        // 获取符合条件的消息，推送时间到了并循环推送
        $messages = Db::name('xiaoxi')
            ->where('nexttime', '<=', $nowTime)
            ->where('status', 1)
            ->order('id desc')
            ->select();
        if($messages){
        foreach ($messages as $message) {
            $sendTime = date('H:i', strtotime($message['nexttime']));
            $nextSendTime = date('H:i', strtotime("+{$message['repeat_interval']} minutes", strtotime($sendTime)));

            Db::name('xiaoxi')->where('id', $message['id'])->update(['nexttime' => $nextSendTime]);
            
            $rs=Db::name('botgroup')->where('id',$message['bgid'])->find(); 
            $node=$rs['node'];
            $grouplist = explode(',', trim($node, ','));
            if($grouplist){
            // 推送消息
                foreach ($grouplist as $group) {
                    $this->pushMessage(
                        $group, 
                        $message['content'], 
                        $message['is_top'], 
                        $message['photo'], 
                        $message['token'],
                        $message['buttonset'],
                        $message['is_del']
                    );
                }
            }
            //Log::info("下次推送时间：{$nextSendTime}");
        }
        }
    }

    protected function pushMessage($groupId, $text, $isTop, $photo, $token,$buttonset, $isDel)
    {
        // 删除当前置顶消息（如果需要）
        if ($isDel == 1) {
            $this->deletePinnedMessage($groupId, $token);
        }
        
        // 如果消息需要置顶
        if ($isTop == 1) {
            $currentPinnedMessage =$this-> getPinnedMessage($token, $groupId);
            
           /* if ($currentPinnedMessage && $currentPinnedMessage['text'] == $text) {
                 echo "群组 ID: {$groupId} 当前置顶消息与发送的消息相同，跳过发送。\n";
            } else {*/
                    $buttons = explode("\n", trim($buttonset));
                        $bwButtons = []; // 按钮格式化数组
                        foreach ($buttons as $button) {
                            $parts = explode('#', $button);
                            if (count($parts) === 2) {
                                $bwButtons[] = [[
                                    'text' => $parts[0], // 按钮文本
                                    'url' => $parts[1]  // 按钮链接
                                ]];
                                
                            }
                        }
                     $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);   
                    $content = [
                    'chat_id' => $groupId,
                    'text' => $text,
                    'reply_markup' => $replyMarkup,
                    ];
                    
                    $response = send($token, 'sendMessage', $content);
                    $data = json_decode($response, true);
                    log::write($response);
                    if ($data['ok']) {
                        $messageId = $data['result']['message_id'];
                    }
              
                    $this->pinMessage($groupId, $token, $messageId);
            //}
        } else {
            //$this->unpinMessage($groupId, $token, $messageId);
            $this->sendContent($groupId, $text, $photo, $token, $buttonset);
        }
    }

    private function pinMessage($groupId, $token, $messageId)
    {
        $content = [
            'chat_id' => $groupId,
            'message_id' => $messageId,
            'disable_notification' => false
        ];
        send($token, 'pinChatMessage', $content);
        //Log::info("消息置顶成功，群组ID: {$groupId}");
    }

    private function unpinMessage($groupId, $token, $messageId)
    {
        $content = [
            'chat_id' => $groupId,
            'message_id' => $messageId,
            'disable_notification' => true
        ];
        send($token, 'unpinChatMessage', $content);
        //Log::info("取消置顶消息，群组ID: {$groupId}");
    }

    private function sendContent($groupId, $text, $photo, $token,$buttonset)
    {
        if (!empty($photo)) {
            $this->sendPhoto($groupId, $text, $photo, $token,$buttonset);
        } else {
            $this->sendTextMessage($groupId, $text, $token,$buttonset);
        }
    }

    private function sendPhoto($groupId, $text, $photo, $token,$buttonset)
    {
      
                $buttons = explode("\n", trim($buttonset));
                        $bwButtons = []; // 按钮格式化数组
                        foreach ($buttons as $button) {
                            $parts = explode('#', $button);
                            if (count($parts) === 2) {
                                $bwButtons[] = [[
                                    'text' => $parts[0], // 按钮文本
                                    'url' => $parts[1]  // 按钮链接
                                ]];
                                
                            }
                        }
                     $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);  
                $content = [
                    'chat_id' => $groupId,
                    'photo' => $photo,
                    'caption' => $text,
                    'reply_markup' => $replyMarkup,
                    'parse_mode' => 'Markdown'
                ];

                $response = send($token, 'sendPhoto', $content);

                if ($response['ok']) {
                    Log::info("图片发送成功: " . $photo);
                } else {
                    Log::info("图片发送失败: " . $response['description']);
                }
       
    }

    private function sendTextMessage($groupId, $text, $token,$buttonset)
    {
        /*$isHtml=preg_match('/<[^>]+>/', $text);
        if($isHtml){
            $parse_mode='HTML';
        }else{
           $parse_mode='Markdown'; 
        }*/
        if (preg_match('/<\s*a[^>]*>(.*?)<\s*\/\s*a>/', $text)) {
           $parse_mode='HTML';
        }

        if (preg_match('/\[(.*?)\]\((.*?)\)/', $text)) {
            $parse_mode='Markdown'; 
        }

        $buttons = explode("\n", trim($buttonset));
                        $bwButtons = []; // 按钮格式化数组
                        foreach ($buttons as $button) {
                            $parts = explode('#', $button);
                            if (count($parts) === 2) {
                                $bwButtons[] = [[
                                    'text' => $parts[0], // 按钮文本
                                    'url' => $parts[1]  // 按钮链接
                                ]];
                                
                            }
                        }
        $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);  
        $content = [
            'chat_id' => $groupId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
            'parse_mode' => $parse_mode,
            'disable_notification' => true
        ];
        send($token, 'sendMessage', $content);
        //Log::info("文本消息发送成功，群组ID: {$groupId}");
    }

    private function deletePinnedMessage($chatId, $token)
    {
        $content = ['chat_id' => $chatId];
        $chatInfo = json_decode(send($token, 'getChat', $content), true);

        if (isset($chatInfo['result']['pinned_message'])) {
            $messageId = $chatInfo['result']['pinned_message']['message_id'];
            $deleteContent = [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ];
            $deleteResult = json_decode(send($token, 'unpinChatMessage', $deleteContent), true);

            if ($deleteResult['ok']) {
               // Log::info("置顶消息已删除，群组ID: {$chatId}");
            } else {
               // Log::info("删除置顶消息失败: " . $deleteResult['description']);
            }
        } else {
           // Log::info("群组 {$chatId} 没有置顶消息");
        }
    }
    
        /**
     * 获取群组当前置顶的消息
     * 
     * @param string $token Telegram Bot Token
     * @param string $chatId 群组ID
     * @return array|false 当前置顶的消息信息
     */
    function getPinnedMessage($token, $chatId) {
        $content = ['chat_id' => $chatId];
        $chatInfo = json_decode(send($token, 'getChat', $content), true);
    
        if ($chatInfo['ok'] && isset($chatInfo['result']['pinned_message'])) {
            return $chatInfo['result']['pinned_message'];
        }
    
        return false;
    }
}
