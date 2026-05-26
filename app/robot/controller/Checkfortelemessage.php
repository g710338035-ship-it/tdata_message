<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Cache;
use app\BaseController;

class Checkfortelemessage extends BaseController
{
    protected $cacheBot;
    public function __construct($id = null)
    {
       $this->cacheBot = 'telegram_bot_' .$id;
    }
    
    public function checkForTelemessage($chat_id, $chatType, $text, $fromUserId, $token, $messageId)
    {
        $bot = Cache::store('redis')->get($this->cacheBot);

        $chType = $this->determineChatType($chatType);
        $telemessage = $this->getTelemessage($bot['bot_id'], $text, $chType);

        if (!$telemessage) {
            return false; // 未找到匹配的自定义消息
        }

        // 获取自定义消息内容
        if($text=='/start'){
            $str='';
            if($bot['qunzf']==1){
                $str.="♻️ 群转发  激活✅\n\n";
            }else{
                $str.="♻️ 群转发  关闭❌\n\n";
            }
            if($bot['isbi']==1){
                $str.="💹 币查询  激活✅\n\n";
            }else{
                $str.="💹 币查询  关闭❌\n\n";
            }
            if($bot['opentwo']==1){
                $str.="🔛开启双向  激活✅\n\n";
            }else{
                $str.="🔛开启双向  关闭❌\n\n";
            }
            if($bot['addressjc']==1){
                $str.="💠开启地址监测  激活✅\n\n";
            }else{
                $str.="💠开启地址监测  关闭❌\n\n";
            }
            $customMessage = $telemessage['content']."\n\n".$str;
        }else{
            $customMessage = $telemessage['content'];
        }
        
        
        $buttons = $this->getButtons($telemessage['id']);

        if (!empty($buttons)) {
            $this->sendMessageWithButtons($chat_id, $customMessage, $buttons, $messageId, $fromUserId, $token,$bot);
        } else {
            $this->sendSimpleMessage($chat_id, $customMessage, $token,$bot);
        }

        return true;
    }
    
    public function checkForTelemessageEdit($chat_id, $chatType, $text, $fromUserId, $token, $messageId)
    {
        $bot = Cache::store('redis')->get($this->cacheBot);
        
        $chType = $this->determineChatType($chatType);
        $telemessage = $this->getTelemessage($bot['bot_id'], $text, $chType);

        if (!$telemessage) {
            return false; // 未找到匹配的自定义消息
        }

        // 获取自定义消息内容
        if($text=='/start'){
            $str='';
            if($bot['qunzf']==1){
                $str.="♻️ 群转发  激活✅\n\n";
            }else{
                $str.="♻️ 群转发  关闭❌\n\n";
            }
            if($bot['isbi']==1){
                $str.="💹 币查询  激活✅\n\n";
            }else{
                $str.="💹 币查询  关闭❌\n\n";
            }
            if($bot['opentwo']==1){
                $str.="🔛开启双向  激活✅\n\n";
            }else{
                $str.="🔛开启双向  关闭❌\n\n";
            }
            if($bot['addressjc']==1){
                $str.="💠开启地址监测  激活✅\n\n";
            }else{
                $str.="💠开启地址监测  关闭❌\n\n";
            }
            $customMessage = $telemessage['content']."\n\n".$str;
        }else{
            $customMessage = $telemessage['content'];
        }
        
        $buttons = $this->getButtons($telemessage['id']);

        if (!empty($buttons)) {
            $this->editMessageWithButtons($chat_id, $customMessage, $buttons, $messageId, $token,$bot);
        } else {
            $this->sendSimpleMessage($chat_id, $customMessage, $token,$bot);
        }

        return true;
    }

    private function getBotInfo($token)
    {
        return Db::name('telegrambot')->where('bot_token', $token)->find();
    }

    private function determineChatType($chatType)
    {
        return ($chatType === 'private') ? 1 : 2; // 1 = 私聊, 2 = 群组
    }

    private function getTelemessage($bot_id, $text, $chType)
    {
        $map1 = [
            ['bot_id', '=', $bot_id],
            ['title', '=', $text],
            ['status', '=', 1],
            ['chattype', 'in', [0, $chType]],
        ];

        $map2 = [
            ['bot_id', '=', null],
            ['title', '=', $text],
            ['status', '=', 1],
            ['chattype', 'in', [0, $chType]],
        ];

        return Db::name('telemessage')->whereOr([$map1, $map2])->find();
    }

    private function getButtons($tmg_id)
    {   
        $buttons = getTelebuttonByTmgId($tmg_id);
        return $buttons;
    }

    private function sendMessageWithButtons($chat_id, $customMessage, $buttons, $messageId, $fromUserId, $token,$bot)
    {
        $keyboard = $this->createKeyboard($buttons,$bot);
        Cache::set("user:{$chat_id}:command_user", $fromUserId, 3600); 
        
        $buttonData = [
            'chat_id' => $chat_id,
            'text' => $customMessage,
            'reply_to_message_id' => $messageId,
            'reply_markup' => json_encode($keyboard),
        ];
        send($token, 'sendMessage', $buttonData);
    }

    private function editMessageWithButtons($chat_id, $customMessage, $buttons, $messageId, $token,$bot)
    {
        $keyboard = $this->createKeyboard($buttons,$bot);

        $buttonData = [
            'chat_id' => $chat_id,
            'text' => $customMessage,
            'message_id' => $messageId,
            'reply_markup' => json_encode($keyboard),
        ];
        send($token, 'editMessageText', $buttonData);
    }

    private function sendSimpleMessage($chat_id, $customMessage, $token)
    {
        $messageData = [
            'chat_id' => $chat_id,
            'text' => $customMessage,
        ];
        send($token, 'sendMessage', $messageData);
    }

    private function createKeyboard($buttons,$bot)
    {
        $keyboard = [];
        $currentRow = [];
        $currentRowNumber = null;
        $maxColumns = 3; // 默认列数

        foreach ($buttons as $button) {
            if ($button['row_number'] !== $currentRowNumber) {
                if (!empty($currentRow)) {
                    $keyboard[] = $currentRow;
                }
                $currentRow = [];
                $currentRowNumber = $button['row_number'];
                $maxColumns = $button['column_number'];
            }

            
            if($button['content'] === '/startgroup=start'){
                $button['content'] = $this->formatButtonContent($button,$bot);
                $currentRow[] = ['text' => $button['title'], 'url' => $button['content']];
            }else{
                $currentRow[] = ['text' => $button['title'], 'callback_data' => $button['content']];
            }
            

            if (count($currentRow) >= $maxColumns) {
                $keyboard[] = $currentRow;
                $currentRow = [];
            }
        }

        if (!empty($currentRow)) {
            $keyboard[] = $currentRow;
        }

        return ['inline_keyboard' => $keyboard];
    }

    private function formatButtonContent($button,$bot)
    {
        return ($button['content'] === '/startgroup=start') 
            ? 'https://t.me/' . $bot['bot_name'] . '?startgroup=gsetting' 
            : $button['content'];
    }
}
