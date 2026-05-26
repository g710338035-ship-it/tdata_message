<?php

namespace app\kefu\controller;
use think\exception\FuncNotFoundException;
use think\exception\ValidateException;
use app\BaseController;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Session;

class Baseinfo extends BaseController
{
	
	
	protected function initialize(){
		$controller = $this->request->controller();
		$action = $this->request->action();
		$app = app('http')->getName();
				
        $admin = session('kefu');
        $userid = $admin['id'];
        $sid = session('kefu_session_token');
        $cid = $userid ? Cache::store('redis')->get('kefu_session_token_'.$userid) : null;
		
        if( !$userid && ( $app <> 'kefu' || $controller <> 'Login' )){
            echo '<script type="text/javascript">window.location.href="'.url('/kefu/Login/index').'";</script>';exit();
        }
				
		//event('DoLog',session('admin.username'));	//写入操作日志
		// 如果已登录，验证session_id是否与数据库中存储的一致（实现唯一登录）
        if ($userid && $controller != 'Login') {
            if($sid !== $cid){
                session('kefu', null);
                session('kefu_sign', null);
                session('kefu_session_token', null);
                //echo '<script type="text/javascript">window.location.href="'.url('/kefu/Login/index').'";</script>';exit();
            }
            // 获取数据库中存储的session_id
            $db_session_id = Db::name('mtcustomer')
                ->where('id', $userid)
                ->value('session_id');
            
            // 获取当前session_id
            $current_session_id = Session::getId();
            
            // 如果数据库中存在session_id且与当前session_id不一致，强制退出登录
            if (!empty($db_session_id) && $db_session_id != $current_session_id) {
                session('kefu', null);
                session('kefu_sign', null);
                session('kefu_session_token', null);
               // echo '<script type="text/javascript">alert("您的账号已在其他设备登录，如非本人操作，请及时修改密码！");window.location.href="'.url('/kefu/Login/index').'";</script>';exit();
            }
        } 
        
		$list = Db::name('base_config')->cache(true,60)->select()->column('data','name');
		config($list,'base_config');
	}
	
	//返回当前应用的菜单列表
	protected function getBaseMenus(){
		$field = 'node_id,pid,title,status,icon,sortid,path';
		$list = db("node")->field($field)->where('status',1)->where('type',1)->order('sortid asc')->select()->toArray();
		if($list){
			foreach($list as $key=>$val){
				$menus[$key]['node_id'] = $val['node_id'];
				$menus[$key]['pid'] = $val['pid'];
				$menus[$key]['title'] = $val['title'];
				$menus[$key]['sortid'] = $val['sortid'];
				$menus[$key]['icon'] = $val['icon'] ? $val['icon'] : 'el-icon-menu';
				$menus[$key]['url'] = $val['path'];
			}
			return _generateListTree($menus,0,['node_id','pid']);
		}
	}
	
	
	
	//验证器 并且抛出异常
	protected function validate($data,$validate){
		try{
			validate($validate)->scene($this->request->action())->check($data);
		}catch(ValidateException $e){
			throw new ValidateException ($e->getError());
		}
		return true;
	}
	
	//格式化sql字段查询 转化为 key=>val 结构
	protected function query($sql){
		preg_match_all('/select(.*)from/iUs',$sql,$all);
		if(!empty($all[1][0])){
			$sqlvalue = explode(',',trim($all[1][0]));
		}
		$sql = str_replace('pre_',config('database.connections.mysql.prefix'),$sql);
		$list = Db::query($sql);
		$array = [];
		foreach($list as $k=>$v){
			$array[$k]['key'] = $v[$sqlvalue[1]];
			$array[$k]['val'] = $v[$sqlvalue[0]];
			if($sqlvalue[2]){
				$array[$k]['pid'] = $v[$sqlvalue[2]];
			}
		}
		return $array;
	}
	
	public function resetPwd(){
		$password = $this->request->post('password');
		
		if(empty($password)) $this->error('密码不能为空');
		
		$data['id'] = session('kefu.id');
		$data['password'] = md5($password.config('my.password_secrect'));
		
		$res = db('mtcustomer')->where('id',$data['id'])->update($data);
		
		return json(['status'=>200,'msg'=>'操作成功']);
	}
	
	//将带有下拉分页的格式化为前端匹配的数据格式
	protected function getSelectPageData($sql,$where,$limit){
	   
		preg_match_all('/select(.*)from/iUs',$sql,$all);
	
		if(!empty($all[1][0])){
			$sqlvalue = explode(',',trim($all[1][0]));
		}
		
		$res = loadList($sql,$limit,'',$where);
		
		$array = [];
		foreach($res['data'] as $k=>$v){
			$array[$k]['key'] = $v[$sqlvalue[1]];
			$array[$k]['val'] = $v[$sqlvalue[0]]; 
		}
		
		$data['data'] = $array;
		$data['total'] = $res['total'];
		
		return $data;
	}
	
	
	public function __call($method, $args){
        throw new FuncNotFoundException('方法不存在',$method);
    }
	
	
	
}
