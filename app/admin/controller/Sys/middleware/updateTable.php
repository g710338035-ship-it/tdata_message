<?php
namespace app\admin\controller\Sys\middleware;
use think\exception\ValidateException;
use app\admin\controller\Admin;
use app\admin\controller\Sys\model\Field;
use app\admin\controller\Sys\model\Menu;
use think\facade\Db;


class updateTable extends Admin
{
	
    public function handle($request, \Closure $next)
    {	
		$data = $request->param();
		
		$this->validate($data,\app\admin\controller\Sys\validate\Menu::class);
		
		$table_name = strtolower(trim($data['table_name']));
		$pk = strtolower(trim($data['pk']));
		
		$menuInfo = Menu::find($data['menu_id']);
		
		$connect = $menuInfo['connect'] ? $menuInfo['connect'] : config('database.default');
		$prefix = config('database.connections.'.$connect.'.prefix');
		
		$sqlTable = "ALTER TABLE ".$prefix."".$menuInfo['table_name']." CHANGE ".$menuInfo['pk']." ".$pk." INT( 11 ) COMMENT '编号' NOT NULL AUTO_INCREMENT;";
		$sqlField = "ALTER TABLE ".$prefix."".$menuInfo['table_name']." RENAME TO ".$prefix."".$table_name;			

		Db::connect($connect)->execute($sqlTable);
		Db::connect($connect)->execute($sqlField);
			
		return $next($request);
    }
	
}