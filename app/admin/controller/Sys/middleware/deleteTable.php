<?php
namespace app\admin\controller\Sys\middleware;
use think\exception\ValidateException;
use app\admin\controller\Sys\model\Menu;
use app\admin\controller\Sys\model\Application;
use app\admin\controller\Admin;
use app\admin\controller\Sys\model\Field;
use app\admin\controller\Sys\model\Action;
use think\facade\Db;
use think\helper\Str;

class deleteTable extends Admin
{
	
    public function handle($request, \Closure $next)
    {	
		$data = $request->param();
		$menuInfo = Menu::find($data['menu_id']);
		$connect = $menuInfo['connect'] ? $menuInfo['connect'] : config('database.default');
		$prefix = config('database.connections.'.$connect.'.prefix');
		
		if($menuInfo['table_name'] && $menuInfo['create_table']){
			Db::connect($connect)->execute('DROP TABLE if exists '.$prefix.$menuInfo['table_name']);
		}

		return $next($request);	
    }
	
	
}