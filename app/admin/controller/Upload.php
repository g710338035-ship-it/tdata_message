<?php 

namespace app\admin\controller;
use think\facade\Log;
use think\facade\Db;
use think\facade\Validate;
use think\facade\Filesystem;
use think\exception\ValidateException;
use think\Image;
use app\admin\model\Files as FilesModel;

class Upload extends Admin{
	
	
	
	//上传前先检测上传模式 如果是oss客户端直传则直接返回 token 、key等信息
	public function upload(){
		$file = $this->request->file('file');
		$upload_config_id = $this->request->post('upload_config_id');
		$file_type = upload_replace(config('base_config.filetype')); //上传黑名单过滤
		if(!Validate::fileExt($file,$file_type)){
			throw new ValidateException('文件类型验证失败');
		}
		
		if(!Validate::fileSize($file,config('base_config.filesize') * 1024 * 1024)){
			throw new ValidateException('文件大小验证失败');
		}
		$filepath = $this->getFile($file);
		if($filepath){
			return json(['status'=>200,'data'=>$filepath,'filestatus'=>true]);
		}else{
			$edit = $this->request->post('edit');	//检测是否编辑器上传  如果是则不走oss客户端传
		
			if($url = $this->up($file,$upload_config_id)){
				return json(['status'=>200,'data'=>$url]);
			}
			
		}
	}
	
	//开始上传
	protected function up($file,$upload_config_id=''){
		try{
			
				$filename = Filesystem::disk('public')->putFile($this->getFileName(),$file,'uniqid');
				$url =config('filesystem.disks.public.url').'/'.$filename;
				log::info(config('base_config.domain'));
				log::info(config('filesystem.disks.public.url'));
				if($upload_config_id){
					$this->thumb(config('filesystem.disks.public.url').'/'.$filename,$upload_config_id);
				}
			
		}catch(\Exception $e){
			throw new ValidateException('上传失败');
		}
		
		
		if($url && explode('/',$file->getMime())[0] == 'image'){
			FilesModel::create(['filepath'=>$url,'hash'=>$file->hash('md5'),'create_time'=>time()]);
		}
		
		return $url;
	}
	
	
	
	public function markDownUpload() {
		$file = $this->request->file('editormd-image-file');
		$url = $this->up($file,$upload_config_id='');
		if($url){
			return json(['url'=>$url,'success'=>1,'message'=>'图片上传成功!']);
		}
	}
	
	//获取上传的文件完整路径
	private function getFileName(){
		return app('http')->getName().'/'.date(config('my.upload_subdir'));
	}
	
	//oss上传成功后写入图片路径
	public function createFile(){
		$filepath = $this->request->post('filepath');
		$hash = explode('.',basename($filepath))[0];
		if($filepath  && in_array(explode('.',basename($filepath))[1],['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])){
			FilesModel::create(['filepath'=>$filepath,'hash'=>$hash,'create_time'=>time(),'admin_id'=>session('admin.user_id')]);
		}
		return json(['status'=>200]);
	}
	
	//检测数据库的同图片的路径是否存在 存在则返回
	private function getFile($file){
		$filepath = FilesModel::where('hash',$file->hash('md5'))->where('admin_id',session('admin.user_id'))->value('filepath');
		if($filepath  && config('my.check_file_status')){
			return $filepath;
		}
	}
	
	//生成缩略图
	private function thumb($imagesUrl,$upload_config_id){
		$imagesUrl = '.'.$imagesUrl;
		$configInfo = Db::name("upload_config")->where('id',$upload_config_id)->find();
		if($configInfo){ 
			$image = Image::open($imagesUrl);
			$targetimages = $imagesUrl;
			
			//当设置不覆盖,生成新的文件
			if(!$configInfo['upload_replace']){
				$fileinfo = pathinfo($imagesUrl);
				$targetimages = $fileinfo['dirname'].'/s_'.$fileinfo['basename'];
				copy($imagesUrl,$targetimages);
			}
			
			//生成缩略图
			if($configInfo['thumb_status']){
				$image->thumb($configInfo['thumb_width'], $configInfo['thumb_height'],$configInfo['thumb_type'])->save($targetimages);
			}
			
			//生成水印
			if(config('base_config.water_status') && config('base_config.water_position')){
				$image->water(config('my.water_img'),config('base_config.water_position'),config('base_config.water_alpha'))->save($targetimages); 
			}
		}
	}
	

    
}