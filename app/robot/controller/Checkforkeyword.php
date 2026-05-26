<?php
//已优化
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use app\BaseController;
use think\facade\Cache;
class Checkforkeyword extends BaseController
{
    protected $cacheBot;
    public function __construct($id = null)
    {
       $this->cacheBot = 'telegram_bot_' .$id;
    }
    // 检查消息中的关键词，并执行相应的惩罚
    public function checkForKeyword($chatId, $text, $userId, $token, $messageId)
    {
       
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        if($botinfo){
            
                $bot_id=$botinfo['bot_id'];  
                // 定义 Redis 缓存键
                $cacheBanwordsKey = "kl_tg_keyword";
                
                $cacheKey = "botgroup_cache";
                // 从缓存获取数据
                $botGroups = Cache::store('redis')->get($cacheKey);
        
                // 如果缓存不存在，从数据库查询并写入缓存
                if ($botGroups === null) {
                    $botGroups = Db::name('botgroup')->order("id desc")->select()->toArray(); // 查询所有 botgroup 数据
                    Cache::store('redis')->set($cacheKey, $botGroups, 3600); // 缓存查询结果
                }
                $result='';
                // 在缓存的结果中查找符合条件的记录
                foreach ($botGroups as $group) {
                    if (strpos($group['node'], "$chatId,") !== false) {
                        $result= $group['id']; // 返回符合条件的 ID
                        break;
                    }
                }
              
                // 获取缓存中的违禁词数据
                $cacheBanwordsData = Cache::store('redis')->get($cacheBanwordsKey);
        
                // 如果缓存中没有违禁词数据，从数据库查询并缓存
                if (!$cacheBanwordsData) {
                    $cacheBanwordsData = Db::name('keyword')->order("id desc")->select()->toArray();
                    Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600); // 缓存1小时
                }
                
             
            
                if($result){
                // 遍历缓存中的违禁词数据，检查是否存在匹配的词
                foreach ($cacheBanwordsData as $banword) {
                    // 检查是否符合 bot_id 或 group_id 与输入参数匹配
                    if ($banword['bot_id'] == $bot_id && $banword['keyword'] == $text&& $banword['bgid'] == $result ) {
                         $parse_mode=$this->detectTextType($banword['reply']);
                          $formattedReply = str_replace("||", "\n", $banword['reply']);
                          log::info($formattedReply);
                        $content = [
                            'chat_id' => $chatId,
                            'reply_to_message_id' => $messageId,
                            'text' => $formattedReply,
                            'parse_mode' => $parse_mode
                        ];
                        $rsss=send($token, 'sendMessage', $content);
                        log::info($rsss);
                        return true; // 检测到违禁词后直接返回
                    }
                }
                }
                return false; // 没有检测到违禁词
            }else{
                return false; 
            }
       
    }
    
    function detectTextType($text) {
        // 判断是否为 HTML
        if (preg_match('/<[^>]+>/', $text)) {
            return 'HTML';
        }
        // 判断是否为 MarkdownV2
        if (preg_match('/\\\[*_()[\]~`>#+\-=|{}.!]/', $text)) {
            return 'MarkdownV2';
        }
        // 默认认为是 Markdown
        if (preg_match('/[*_()[\]~`>#+\-=|{}.!]/', $text)) {
            return 'Markdown';
        }
        return '';
    }
}
