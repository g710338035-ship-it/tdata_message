<?php 
namespace app\admin\controller\Sys;

use think\exception\ValidateException;
use app\admin\controller\Sys\model\Field;
use app\admin\controller\Sys\model\Menu;
use app\admin\controller\Admin;
use think\facade\Db;

class Base extends Admin{
	
	
	public function initialize(){
		parent::initialize();
		config(['view_path'=>app_path()],'view');
	}
	
	//菜单列表
	function menu(){
		if (!$this->request->isPost()){
			return view('controller/Sys/view/menu/cms');
		}else{
			$app_id = $this->request->post('app_id',1,'intval');
			foreach(config('database.connections') as $k =>$v){
				$connects[] = $k;
			}
			$data['status'] = 200;
			$data['list'] = $this->getMenu($app_id,0);
			$data['defaultConnect'] = config('database.default');
			return json($data);
		}
	}
	
	//创建菜单
	public function createMenu(){
		$data = $this->request->post();
		$res = Menu::create($data);
		Menu::update(['menu_id'=>$res->menu_id,'sortid'=>$res->menu_id]);
		return json(['status'=>200]);
	}
	
	//更新菜单
	public function updateMenu(){
		$data = $this->request->post();
		try{
			$res = Menu::update($data);
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200]);
	}
	
	//方法列表直接修改操作
	public function updateMenuExt(){
		$data = $this->request->post();
		try{
			$res = Menu::update($data);
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200]);
	}
	
	
	//获取菜单信息
	public function getMenuInfo(){
		$data = $this->request->post('menu_id');
		try{
			$res = menu::find($data);
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200,'data'=>$res]);
	}
	
	//删除菜单
	public function deleteMenu(){
		$data = $this->request->post();
		try{
			$res = Menu::destroy($data);
			if($res){
				Field::where($data)->delete();
			}
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200]);
	}
	
	
	//菜单字段列表
	public function fieldList(){
		if (!$this->request->isPost()){
			$menu_id = $this->request->get('menu_id','','intval');
			$this->view->assign('menu_id',$menu_id);
			return view('controller/Sys/view/field/cms');
		}else{
			$limit  = $this->request->post('limit', 20, 'intval');
			$page   = $this->request->post('page', 1, 'intval');
			$menu_id = $this->request->post('menu_id','','intval');
			
			$res = Field::where(['menu_id'=>$menu_id])->order('sortid asc')->paginate(['list_rows'=>$limit,'page'=>$page]);
			
			$data['status'] = 200;
			$data['data'] = $res;
			$data['typeField']  = Config::fieldList();
			$data['itemList']  = Config::itemList();
			$data['menu_title'] = Menu::where('menu_id',$menu_id)->value('title');
			return json($data);
		}
		
	}
	
	//创建字段
	public function createField(){
		$data = $this->request->post();
		
		$this->validate($data,\app\admin\controller\Sys\validate\Field::class);
		
		$data['item_config'] = getItemData($data['item_config']);
		$data['other_config'] = json_encode($data['other_config']);
		$data['validate'] = implode(',',$data['validate']);
		
		foreach(Config::fieldList() as $v){
			if($v['type'] == $data['type'] && empty($data['belong_table'])){
				$search_status = $v['search'];
			}
		}

		$data['search_type'] = $search_status;
		try{
			$res = Field::create($data);
			if($res->id){
				Field::update(['id'=>$res->id,'sortid'=>$res->id]);
			}
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200]);
	}
	
	//更新字段
	public function updateField(){
		$data = $this->request->post();
		
		if($data['field_type']){
			$param['id'] = $data['id'];
			$param['field'] = $data['field'];
		}else{
			$this->validate($data,\app\admin\controller\Sys\validate\Field::class);
			
			$data['item_config'] = getItemData($data['item_config']);
			$data['other_config'] = json_encode($data['other_config']);
			$data['validate'] = implode(',',$data['validate']);
			
			foreach(Config::fieldList() as $v){
				if($v['type'] == $data['type'] && empty($data['belong_table'])){
					$search_status = $v['search'];
				}
			}

			$data['search_type'] = $search_status;
			
			$param = $data;
		}
				
		try{
			Field::update($param);
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200]);
	}
	
	//方法列表直接修改操作
	public function updateFieldExt(){
		$data = $this->request->post();
		try{
			$res = Field::update($data);
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200]);
	}
	
	//获取字段信息
	public function getFieldInfo(){
		$data = $this->request->post();
		try{
			$res = Field::where($data)->find();
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		$res['validate'] = explode(',',$res['validate']);
		$res['item_config'] = json_decode($res['item_config'],true);
		return json(['status'=>200,'data'=>$res]);
	}
	
	//删除字段
	public function deleteField(){
		$data = $this->request->post();
		$menuInfo = Menu::find($data['menu_id']);
		$pk = Db::connect($menuInfo['connect'])->name($menuInfo['table_name'])->getPk();
		$fieldList = Field::field('id,field')->where($data)->select()->toArray();
		$ids = [];
		foreach($fieldList as $v){
			if($pk <> $v['field']){
				array_push($ids,$v['id']);
			}else{
				$pk_status = true;
			}
		}
		try{
			Field::where('id','in',$ids)->delete();
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200,'pk_status'=>$pk_status]);
	}
	
	//拖动排序
	public function updateFieldSort(){
		$postField = 'currentId,preId,nextId,menu_id';
		$data = $this->request->only(explode(',',$postField),'post',null);
		
		if(!empty($data['preId'])){
			$pre = Field::where('id',$data['preId'])->where('menu_id',$data['menu_id'])->value('sortid');
		}
		if(!empty($data['nextId'])){
			$next = Field::where('id',$data['nextId'])->where('menu_id',$data['menu_id'])->value('sortid');
		}
		
		$current = Field::where('id',$data['currentId'])->where('menu_id',$data['menu_id'])->value('sortid');
		
		if($current > $pre){
			$sortid = $next;
		}else{
			$sortid = $pre;
		}
		
		if(empty($pre)){
			$pre = $next - 1;
			$sortid = $next;
		}
		if(empty($next)){
			$next = $pre + 1;
			$sortid = $pre;
		}
		try{
			if($current > $pre){
				Field::field('sortid')->where('sortid','between',[$pre+1,$current-1])->where('menu_id',$data['menu_id'])->inc('sortid',1)->update();
			}
			if($current < $pre){
				Field::field('sortid')->where('sortid','between',[$current+1,$next-1])->where('menu_id',$data['menu_id'])->dec('sortid',1)->update();
			}
			Field::field('sortid')->where('id',$data['currentId'])->where('menu_id',$data['menu_id'])->update(['sortid'=>$sortid]);
		}catch(\Exception $e){
			abort(501,$e->getMessage());
		}
		return json(['status'=>200,'pre'=>$pre]);
	}
	
	
	//字段选项配置，验证规则配置
	public function configList(){
		$ruleList = Config::ruleList();
		$propertyField = Config::propertyField();
		return json(['status'=>200,'ruleList'=>$ruleList,'propertyField'=>$propertyField]);
	}

	
	//获取菜单列表
	private function getMenu($app_id){
		$field = 'menu_id,pid,title,controller_name,create_code,create_table,table_name,status,sortid';
		$list = Menu::field($field)->where(['app_id'=>$app_id])->order('sortid asc')->select()->toArray();
		return _generateListTree($list,0,['menu_id','pid']);
	}
	
	
	
	//检测cms模型字段
	public function checkCmsField(){
		$field = $this->request->post('field');
		$list = Db::query('show full columns from '.config('database.connections.mysql.prefix').'content');
		foreach($list as $v){
			$arr[] = $v['Field'];
		}
		if(in_array($field,$arr)){
			throw new ValidateException('主表该字段已存在，请更换字段');
		}
		
		return json(['status'=>200]);
	}
	
	
	
}

