<?php
namespace app\admin\controller\Sys\middleware;
use app\admin\controller\Sys\model\Menu;
use think\exception\ValidateException;
use app\admin\controller\Admin;
use think\facade\Db;


class createField extends Admin
{
	
    public function handle($request, \Closure $next)
    {	
		$data = $request->param();
		
		$this->validate($data,\app\admin\controller\Sys\validate\Field::class);

		if($data['create_table_field']){
			$menuInfo = Menu::find($data['menu_id']);
			if($menuInfo['page_type'] == 1){
				if((!empty($data['default_value']))){
					if($data['type'] == 13){
						$data['default_value'] = '0';
					}
					$default = "DEFAULT '".$data['default_value']."'";
				}else{
					$default = 'DEFAULT NULL';
				}
				
				if(in_array($data['datatype'],['datetime','longtext'])){
					$data['length'] = ' null';
				}else{
					$data['length'] = "({$data['length']})";
				}
				
				$connect = $menuInfo['connect'] ? $menuInfo['connect'] : config('database.default');
				$prefix = config('database.connections.'.$connect.'.prefix');
				$sql="ALTER TABLE ".$prefix."{$menuInfo['table_name']} ADD {$data['field']} {$data['datatype']}{$data['length']} COMMENT '{$data['title']}' {$default}";
				
				Db::connect($connect)->execute($sql);
				
				if(!empty($data['indexdata'])){
					Db::connect($connect)->execute("ALTER TABLE ".$prefix."{$menuInfo['table_name']} ADD ".$data['indexdata']." (  `".$data['field']."` )");
				}
			}
		}
		
		return $next($request);
		
		
    }
}