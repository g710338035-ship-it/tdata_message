<?php 
/*
 module:		上传组件控制器
 create_time:	2021-10-13 23:06:40
 author:		
 contact:		
*/

namespace app\admin\controller;
use think\exception\ValidateException;
use app\admin\model\Files as FileModel;
use think\facade\Db;

class File extends Admin {


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
			$where['id'] = $this->request->post('id', '', 'serach_in');

			$field = 'id,filepath,create_time';

			$res = FileModel::where(formatWhere($where))->field($field)->order('id desc')->paginate(['list_rows'=>$limit,'page'=>$page])->toArray();

			$data['status'] = 200;
			$data['data'] = $res;
			return json($data);
		}
	}
	/*
 	* @Description  修改排序开关
 	*/
	function updateExt(){
		$postField = 'id,';
		$data = $this->request->only(explode(',',$postField),'post',null);
		if(!$data['id']) throw new ValidateException ('参数错误');
		FileModel::update($data);
		return json(['status'=>200,'msg'=>'操作成功']);
	}

	/*
 	* @Description  添加
 	*/
	public function add(){
		$postField = 'title,pic,pic_2,pics,file,files';
		$data = $this->request->only(explode(',',$postField),'post',null);

		$this->validate($data,\app\admin\validate\File::class);
		$data['pics'] = getItemData($data['pics']);
		$data['files'] = getItemData($data['files']);

		try{
			$res = FileModel::create($data);
		}catch(\Exception $e){
			throw new ValidateException($e->getMessage());
		}
		return json(['status'=>200,'data'=>$res->id,'msg'=>'添加成功']);
	}


	/*
 	* @Description  修改
 	*/
	public function update(){
		$postField = 'id,title,pic,pic_2,pics,file,files';
		$data = $this->request->only(explode(',',$postField),'post',null);

		$this->validate($data,\app\admin\validate\File::class);
		$data['pics'] = getItemData($data['pics']);
		$data['files'] = getItemData($data['files']);

		try{
			FileModel::update($data);
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
		$field = 'id,title,pic,pic_2,pics,file,files';
		$res = FileModel::field($field)->find($id);
		$res['pics'] = json_decode($res['pics'],true);
		$res['files'] = json_decode($res['files'],true);
		return json(['status'=>200,'data'=>$res]);
	}


	/*
 	* @Description  删除
 	*/
	function delete(){
		$idx =  $this->request->post('id', '', 'serach_in');
		if(!$idx) throw new ValidateException ('参数错误');
		FileModel::destroy(['id'=>explode(',',$idx)],true);
		return json(['status'=>200,'msg'=>'操作成功']);
	}


	/*
 	* @Description  查看详情
 	*/
	function detail(){
		$id =  $this->request->post('id', '', 'serach_in');
		if(!$id) throw new ValidateException ('参数错误');
		$field = 'id,title,pic,pic_2,pics,file,files';
		$res = FileModel::field($field)->find($id);
		return json(['status'=>200,'data'=>$res]);
	}




}

