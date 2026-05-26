<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Telegraggroup as TelegraggroupModel;
use app\admin\model\Telegrambot as TelegrambotModel;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
class Telegraggroup extends Admin {


	/*
 	* @Description  数据列表
 	*/
	function index(){
		if (!$this->request->isPost()){
			return view('index');
		}else{
			$limit  = $this->request->post('limit', 20, 'intval');
			$page = $this->request->post('page', 1, 'intval');

			$where = [];
			
			//$where['username'] = $this->request->post('username', '', 'serach_in');
		

			$create_time = $this->request->post('create_time', '', 'serach_in');
			$where['create_time'] = ['between',[strtotime($create_time[0]),strtotime($create_time[1])]];

			$withJoin = [
				'telegrambot'=>explode(',','bot_name'),
			];

			$res = TelegraggroupModel::where(formatWhere($where))->order('id desc')->withJoin($withJoin,'left')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	/*
 	* @Description  修改排序开关
 	*/
	function updateExt(){
		$postField = 'id,status';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(!$data['id']) throw new ValidateException ('参数错误');
		
		 // 缓存 key 根据 ID 生成
    $cacheKey = 'telegram_group_' . $data['id'];

    // 从缓存获取数据
    $cachedGroup = Cache::get($cacheKey);

    // 数据库查询
    $rs = TelegraggroupModel::find($data['id']);
    if (!$rs) {
        throw new ValidateException('未找到对应的群组');
    }

    // 如果缓存不存在或者数据有变化，继续处理
    if (!$cachedGroup || $cachedGroup['status'] != $data['status']) {
        // 根据 status 进行 Webhook 处理
        $botToken = TelegrambotModel::where('bot_id',$rs['bot_id'])->value('bot_token');
        if ($data['status'] == 1 && $botToken) {
            // 禁用群组
           $content = array(
                'chat_id' => $rs['group_id'],
                'permissions' => json_encode(array(
                    'can_send_messages' => false,  // 禁止机器人发送消息
                )),
            );
            
            send($botToken, 'restrictChatMember', $content);
            $msg='已禁用该群机器人消息权限';
        } else {
            // 启用群组
            $content = array(
                'chat_id' => $rs['group_id'],
                'permissions' => json_encode(array(
                    'can_send_messages' => true,  // 恢复机器人发送消息权限
                )),
            );
            
            send($botToken, 'restrictChatMember', $content);
            $msg='已恢复机器人消息权限';
        }

        // 更新数据库记录
        try {
            TelegraggroupModel::update($data);

            // 更新缓存
            $rs['status'] = $data['status']; // 更新缓存中的 status 字段
            Cache::set($cacheKey, $rs, 3600); // 缓存有效期设置为 1 小时

            // 返回成功响应
            return json(['status' => 200, 'msg' => $msg]);
        } catch (\Exception $e) {
            throw new ValidateException($e->getMessage());
        }
    } else {
        // 如果缓存存在且状态未变，则不更新数据库，直接返回成功
        return json(['status' => 200, 'msg' => '无需更新，数据未变动']);
    }
	
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,title,group_id,description,content,group_image,status,create_time,bot_id';
		$data = $this->request->only(explode(',',$postField),'post',null);
		
		$data['create_time'] = strtotime($data['create_time']);
		
        $originalData = TelegraggroupModel::find($data['id']);
        $bot=TelegrambotModel::where('bot_id',$data['bot_id'])->find();
        $groupNameUpdated = ($originalData['title'] !== $data['title']);
        $groupImageUpdated =true;// ($originalData['group_image'] !== $data['group_image']);
        $groupDesUpdated = ($originalData['description'] !== $data['description']);
		try{
			TelegraggroupModel::update($data);
			if($bot['bot_token']){
			    log::write($bot['bot_token']);
    			if($groupNameUpdated){
    			    $content = array(
    			     'chat_id'=>$data['group_id'],
                     'title' => $data['title'],
                     );
                    send($bot['bot_token'],'setChatTitle', $content);
                    
    			}
    			
    			if($groupDesUpdated){
    			    $content = array(
    			     'chat_id'=>$data['group_id'],
                     'description' => $data['description'],
                     );
                    send($bot['bot_token'],'setChatDescription', $content);
    			}
    			
    			if($groupImageUpdated){
    			    $tx = $_SERVER['DOCUMENT_ROOT'] .$data['group_image'];
    			    $realImagePath = realpath($tx);
    			    $content = array(
    			        'chat_id'=>$data['group_id'],
                        'photo' => new \CURLFile($realImagePath),
                     );
                    $rs=sendPhoto($bot['bot_token'],'setChatPhoto', $content);
                    log::write($rs);
    			}
			}
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'msg'=>'修改成功']);
	}


	/*
 	* @Description  修改信息之前查询信息的 勿要删除
 	*/
	function getUpdateInfo(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = TelegraggroupModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		TelegraggroupModel::destroy(['id'=>explode(',',$idx)],true);
		return json(['status'=>200,'msg'=>'操作成功']);
	}
    
    
    function groupmessagedel(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		
		$res = TelegraggroupModel::find($idx);
		
		$data['group_id']=$res['group_id'];
		$data['token']=TelegrambotModel::where('bot_id',$res['bot_id'])->value('bot_token');
    		        //处理关键词
    	 Queue::push('app\job\GroupMessageDelJob', $data);
		
		return json(['status'=>200,'msg'=>'指令已提交，系统处理中']);
	}
    
	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = TelegraggroupModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = TelegraggroupModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}




}

