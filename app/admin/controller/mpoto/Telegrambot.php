<?php 
/*
 module:		会员管理控制器
 create_time:	2021-10-13 23:05:54
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Telegrambot as TelegrambotModel;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

class Telegrambot extends Admin {


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

			

			$res = TelegrambotModel::where(formatWhere($where))->order('id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	/*
 	* @Description  修改排序开关
 	*/

function updateExt(){
    // 指定接收的字段
    $postField = 'id,status';
    $data = $this->request->only(explode(',', $postField), 'post', null);

    // 参数验证
    if (!$data['id']) {
        throw new ValidateException('参数错误');
    }

    // 缓存 key 根据 ID 生成
    $cacheKey = 'telegram_bot_' . $data['id'];
    
    // 从缓存获取数据
    $cachedBot = Cache::store('redis')->get($cacheKey);

    // 数据库查询
    $rs = TelegrambotModel::find($data['id']);
    if (!$rs) {
        throw new ValidateException('未找到对应的机器人');
    }

    // 如果缓存不存在或者数据有变化，继续更新
    if (!$cachedBot || $cachedBot['status'] != $data['status']) {
        // 根据 status 和 bot_token 进行 Webhook 处理
        if ($rs['status'] == 1 && $rs['bot_token']) {
           // log::info($rs['bot_token']);
            getWebHookDel($rs['bot_token']);
        } else {
            //log::info($rs['bot_token']);
            getWebHookreg($rs['bot_token'],$rs['id']); 

			
        }
        $resbot=getBotInfo($rs['bot_token']); 
        
        if ($resbot && isset($resbot['result'])) {
            $data['bot_id'] = $resbot['result']['id'];
	        $data['bot_name'] = $resbot['result']['username'];
	        $data['first_name'] = $resbot['result']['first_name'];
        }

		//log::write($resbot);  
        // 更新数据库记录
        try {
            TelegrambotModel::update($data);
            
            $rss = Db::name('telegrambot')->where('id', $rs['id'])->find();
            // 更新缓存
            Cache::store('redis')->set($cacheKey, $rss, 3600); // 缓存有效期设置为 1 小时

            // 返回成功响应
            return json(['status' => 200, 'msg' => '操作成功']);
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
		$postField = 'id,bot_name,bot_image,status,create_time,bot_token,starttime,endtime,clone_type';
		$data = $this->request->only(explode(',',$postField),'post',null);
			
		$data['create_time'] = strtotime($data['create_time']);
		
        $originalData = TelegrambotModel::find($data['id']);
        
        $botNameUpdated = ($originalData['bot_name'] !== $data['bot_name']);
        $botImageUpdated = ($originalData['bot_image'] !== $data['bot_image']);
        
		try{
		    
			TelegrambotModel::update($data);
			$cacheKey = 'telegram_bot_' . $data['id'];
			 //$rs = TelegrambotModel::find($data['id']);
			 $rss = Db::name('telegrambot')->where('id', $data['id'])->find();
			Cache::store('redis')->set($cacheKey, $rss, 3600); 
			if($botNameUpdated){
			    $content = array(
                'name' => $data['bot_name'],
                 );
                send($data['bot_token'],'setMyName', $content);
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
		
		$res = TelegrambotModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		TelegrambotModel::destroy(['id'=>explode(',',$idx)],true);
		
		$cacheKey = 'telegram_bot_' . $idx;
		$cachedBot = Cache::store('redis')->get($cacheKey);
		
		getWebHookDel($cachedBot['bot_token']);
		
		Cache::store('redis')->delete($cacheKey);
		
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		
		$res = TelegrambotModel::find($id);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  禁用
 	*/
	public function forbidden(){
		$idx = $this->request->post('id', '', 'serach_in');
		if(empty($idx)) throw new ValidateException ('参数错误');
		$data['status'] = '0';
		$res = TelegrambotModel::field('status')->where(['id'=>explode(',',$idx)])->update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}




}

