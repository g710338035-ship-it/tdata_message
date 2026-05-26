<?php

namespace app\kefu\controller;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Session;
class Login extends Baseinfo{
	
	
	
	//用户登录 
    public function index(){
		if(!$this->request->isPost()) {
			return view('index');
		}else{
			$postField = 'username,password';
			$data = $this->request->only(explode(',',$postField),'post',null);
			
			//$this->validate($data,\app\admin\validate\Login::class);
			
			if($this->checkLogin($data)){
				return json(['status'=>200]);
			}
		}
    }
	
	//用户登录 
    public function mato(){
		if(!$this->request->isPost()) {
			return view('mato');
		}else{
			$postField = 'username,password';
			$data = $this->request->only(explode(',',$postField),'post',null);
			
			$this->validate($data,\app\kefu\validate\Login::class);
			
			if($this->checkLogin($data)){
				return json(['status'=>200]);
			}
		}
    }
    //验证登录
    private function checkLogin($data){ 
		$where['username'] = trim($data['username']);
		$where['password']  = md5(trim($data['password']).config('my.password_secrect'));
		
		$info = Db::name('mtcustomer')->where($where)->find();
		
		if(!$info){
			throw new ValidateException("请检查用户名或者密码");
		}
		if(!($info['status'])){
			throw new ValidateException("该账户被禁用");
		}
		
		
		$token = md5($data['username'] . time() . uniqid());
		
		session('kefu', $info);
		session('kefu_sign', data_auth_sign($info));
		session('kefu_session_token', $token);
		Cache::store('redis')->set('kefu_session_token_'.$info['id'], $token, 604800);
		
		$session_id = Session::getId();
        Db::name('mtcustomer')
            ->where('id', $info['id'])
            ->update(['session_id' => $session_id]);
    
        
	//	event('LoginLog',$data['username']);	//写入登录日志
		
        return $info;
    }
	
	
	//验证码
	public function verify(){
		$data['data'] = captcha();
		$data['verify_status'] = config('my.verify_status',true);	//验证码开关
		$data['status'] = 200;
	    return json($data);
	}
	

	//退出
    public function logout(){
        $kefu = session('kefu');
        if($kefu && isset($kefu['id'])){
            Cache::store('redis')->delete('kefu_session_token_'.$kefu['id']);
            Db::name('mtcustomer')->where('id',$kefu['id'])->update(['session_id'=>null]);
        }
        session('kefu', null);
		session('kefu_sign', null);
		session('kefu_session_token', null);
	    return json(['status'=>200]);
    }
	

}
