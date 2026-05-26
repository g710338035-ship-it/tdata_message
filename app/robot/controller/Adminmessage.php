<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

class Adminmessage extends Apibot
{
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();       
        $this->cacheBot = $this->cacheBot;
    }

   public function handleMessage($data)
    {
       
        $token=$data['token'];
      
        if (isset($data)&&$data['messagetype']==1) {
            
                $chat_id = $data['chat']['id'];
                $chatType = $data['chat']['type'];
                $message = $data['text']??'未知';
                $messageId = $data['message_id'] ?? null;
                $userId = $data['from']['id'];
        }
        if (isset($data)&&$data['messagetype']==2) {
            $last_name=isset($data['from']['last_name']);
            $name = $data['from']['first_name'].$last_name;
            
            $chat_id = $data['message']['chat']['id'];
            $chatType = $data['message']['chat']['type'];
            $message=$data['data'];
            $messageId = $data['message']['message_id']; 
    		$userId =$data['from']['id'];
    		$username ='';
            
        }
    
//

        if ($this->checkfortelemessage->checkForTelemessage($chat_id, $chatType,$message, $userId, $token,$messageId)) {
            return; // 如果检测到自定义回复，直接返回
        }
        
        if($this->cacheInfoToData($userId,$chat_id,$message,$token,$messageId)){
            return;
        }
      
    }
    private function cacheInfoToData($userId,$chat_id,$message,$token,$messageId){
        //$cacheGroupkey="telegram_group_".$chat_id;
       // $groupInfo = Cache::store('redis')->get($cacheGroupkey);
        ///////banwodadd add_status
                $waiting='waiting_for_message';
             
               /* $redisKeybanwodadd = "banwordadd:notsay.$userId.$chat_id:add_status";
                $redisHashKeybanwodadd = "banwordadd:notsay.$userId.$chat_id:addmessage";
                if ($this->redis->sismember($redisKeybanwodadd, $waiting)) {
                      
                        $duration=$groupInfo['duration'];
                        $this->addBanwords($message,$chat_id,$duration,1);
                        $hashMessageId = $this->redis->hget($redisHashKeybanwodadd, $waiting);
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $hashMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/banWords'
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "违禁词添加成功！",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeybanwodadd,  $waiting);
                     $this->redis->del($redisHashKeybanwodadd);
                     return true;
                }
                 ///////banwodadd del_status
                $redisKeybanwoddel = "banwordadd:notsay.$userId.$chat_id:del_status";
                $redisHashKeybanwoddel = "banwordadd:notsay.$userId.$chat_id:delmessage";
                if ($this->redis->sismember($redisKeybanwoddel, $waiting)) {
                       
                        $this->delBanwords($message,$chat_id);
                        $hashMessageId = $this->redis->hget($redisHashKeybanwoddel, $waiting);
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $hashMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/banWords'
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "违禁词删除成功！",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeybanwoddel,  $waiting);
                     $this->redis->del($redisHashKeybanwoddel);
                      return true;
                }
                
                ///////banwrodgo add_status
                
                $redisKeybanwrodgoadd = "banwordadd:goout.$userId.$chat_id:add_status";
                $redisHashKeybanwrodgoadd = "banwordadd:goout.$userId.$chat_id:addmessage";
                if ($this->redis->sismember($redisKeybanwrodgoadd, $waiting)) {
                       
                        $duration='';
                        $this->addBanwords($message,$chat_id,$duration,2);
                        $hashMessageId = $this->redis->hget($redisHashKeybanwodadd, $waiting);
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $hashMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/banWords'
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "违禁词\n$message\n添加成功！",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeybanwrodgoadd,  $waiting);
                     $this->redis->del($redisHashKeybanwrodgoadd);
                      return true;
                }
                 ///////banwrodgo del_status
                $redisKeybanwrodgodel = "banwordadd:goout.$userId.$chat_id:del_status";
                $redisHashKeybanwrodgodel = "banwordadd:goout.$userId.$chat_id:delmessage";
                if ($this->redis->sismember($redisKeybanwrodgodel, $waiting)) {  
                        $this->delBanwords($message,$chat_id);
                        $hashMessageId = $this->redis->hget($redisHashKeybanwoddel, $waiting);
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $hashMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/banWords'
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "违禁词删除成功！",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeybanwrodgodel,  $waiting);
                     $this->redis->del($redisHashKeybanwrodgodel);
                      return true;
                }
                
                
                
                
                $redisKeywelcomeadd = "welcomeadd:$userId:add_status";
                $redisHashKeywelcomeadd = "welcomeadd:$userId:addmessage";
                if ($this->redis->sismember($redisKeywelcomeadd, $waiting)) {
                        $isaddWelcome=$this->addWelcome($message,$chat_id);
                     
                        $hashMessageId = $this->redis->hget($redisHashKeywelcomeadd, $waiting);
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $hashMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🗑删除欢迎语',
                                'callback_data' => '/welcome_del'
                            ], [
                                'text' => '🔙 返回',
                                'callback_data' => '/welcome_set'
                            ]];
                            
                            $bwButtons[] = [[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "👥群组设置。\n编辑👏欢迎语，发送信息完成修改。\n\n现在欢迎语为：\n $message",
                                'reply_markup' => $replyMarkup,
                                'reply_to_message_id' => $messageId,
                               // 'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeywelcomeadd,  $waiting);
                     $this->redis->del($redisHashKeywelcomeadd);
                     
                     $groupInfo['content']=$message;
                     Cache::store('redis')->set($cacheGroupkey, $groupInfo);
                    
                     return true;
                }*/
                
                $redisKeycustomadd = "customadd:$chat_id.$userId.txt:add_status";
                $redisHashKeycustomadd = "customadd:$chat_id.$userId.txt:addmessage";
                if ($this->redis->sismember($redisKeycustomadd, $waiting)) {
                        $isaddWelcome=$this->addCustom($message,$chat_id,$userId,$token,$messageId);
                     
                        $hashMessageId = $this->redis->hget($redisHashKeycustomadd, $waiting);
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $hashMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/custom_message_start'
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "🕑 重发消息\n\n\👉🏻 设置成功。\n\n $message",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeycustomadd,  $waiting);
                     $this->redis->del($redisHashKeycustomadd);
                     
                     return;
                }        

                $redisKeycustomedit = "customedit:$chat_id.$userId.txt:add_status";
                $redisHashKeycustomedit = "customedit:$chat_id.$userId.txt:addmessage";
                if ($this->redis->sismember($redisKeycustomedit, $waiting)) {
                    $hashMessageId = $this->redis->hget($redisHashKeycustomedit, $waiting);
                       
                    $parts = explode('_', $hashMessageId);
                    if (count($parts) === 2) {
                         $newMessageId= $parts[0]; // 固定为 00:00
                         $id =$parts[1];
                    
                        Db::name('xiaoxi')->where('id', $id)->update(['message_id'=>$messageId,'content'=>$message]);
                      
                    
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $newMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/custom_message_start'
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "🕑 重发消息\n\n\👉🏻 修改成功。\n\n $message",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $newMessageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeycustomedit,  $waiting);
                     $this->redis->del($redisHashKeycustomedit);
                     
                     return;
                    }   
                }  
 
    }
    
    private function delBanwords($text,$chat_id){
        
        // 将文本按换行符分割成数组
        $words = explode("\n", $text);
        
        // 去除前后空格并过滤空行
        $words = array_filter(array_map('trim', $words));
        
        // 如果数组为空，直接返回
        if (empty($words)) {
            echo "没有需要处理的词条。";
            return;
        }
        
        // 批量查询数据库中已有的词条
        $existingWords = Db::name('banwords')
            ->whereIn('word', $words)
            ->where('group_id', $chat_id) 
            ->column('word');
        
        // 如果没有匹配的词条，直接返回
        if (empty($existingWords)) {
            echo "没有匹配的词条需要删除。";
            return;
        }
        
        // 批量删除存在的词条，同时匹配指定的 bot_id
        Db::name('banwords')
            ->whereIn('word', $existingWords)
            ->where('group_id', $chat_id)  // 增加 bot_id 条件
            ->delete();

        $cacheBanwordsKey = "kl_tg_banwords";
        $cacheBanwordsData = Db::name('banwords')->where('status', 1)->select()->toArray();
        Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600);     


        
    }
    private function addBanwords($text,$chat_id,$duration,$psid){
        // 将文本按换行符分割成数组
        $words = explode("\n", $text);
        
        // 去除前后空格并过滤空行
        $words = array_filter(array_map('trim', $words));
        
        // 如果数组为空，直接返回
        if (empty($words)) {
            echo "没有需要处理的词条。";
            return;
        }
        
        // 批量查询数据库中已有的词条
        $existingWords = Db::name('banwords')
            ->whereIn('word', $words)
            ->where('group_id', $chat_id) 
            ->column('word');
        
        // 过滤掉已存在的词条
        $newWords = array_diff($words, $existingWords);
        
        // 如果没有新的词条，直接返回
        if (empty($newWords)) {
            echo "没有新的词条需要插入。";
            return;
        }
        
        // 批量插入新的词条到数据库
        $insertData = [];
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        foreach ($newWords as $word) {
            if($psid==1){
                $insertData[] = ['bot_id' => $botinfo['bot_id'],'word' => $word,'group_id' => $chat_id,'duration' => $duration,'create_time' => time(),'psid'=>$psid];
            }else{
                $insertData[] = ['bot_id' => $botinfo['bot_id'],'word' => $word,'group_id' => $chat_id,'create_time' => time(),'psid'=>$psid];
            }
        }
        Db::name('banwords')->insertAll($insertData); 
        
        $cacheBanwordsKey = "kl_tg_banwords";
        $cacheBanwordsData = Db::name('banwords')->where('status', 1)->select()->toArray();
        Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600);   
     }
   
    
    private function addWelcome($text,$groupID){
       
        $rs=Db::name('telegraggroup')->where('group_id',$groupID)->update(['content'=>$text]); 
        if($rs) {return true;}
        else {return false;}
     }
     

     
    private function addCustom($text,$groupID,$username,$token,$messageId){
        $xxsetting=Db::name('xxsetting')->where('group_id',$groupID)->find();
      
           $data['send_time']=$xxsetting['send_time'];
           $data['nexttime']=$xxsetting['send_time'];
           $data['content']=$text;
           $data['is_top']=$xxsetting['is_top'];
           if($xxsetting['repeat_interval']>0){
           $data['repeat_interval']=$xxsetting['repeat_interval'];
           }else{
              $data['repeat_interval']=1440; 
           }
           $data['status']=0;
           $data['create_time']=time();
           $data['username']=$username;
           $data['group_id']=$groupID;
           $data['token']=$token;
           $data['message_id']=$messageId;
           $rs=Db::name('xiaoxi')->insert($data); 
            if($rs) {
                Db::name('xxsetting')->where('group_id',$groupID)->update(['send_time'=>null,'repeat_interval'=>null,'is_top'=>0,'is_del'=>0]);
                return true;}
            else {return false;}
        
     }
    
}