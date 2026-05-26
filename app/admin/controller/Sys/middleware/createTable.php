<?php
namespace app\admin\controller\Sys\middleware;
use app\admin\controller\Sys\model\Application;
use think\exception\ValidateException;
use app\admin\controller\Admin;
use think\facade\Db;

class createTable extends Admin
{
	
    public function handle($request, \Closure $next)
    {	
		$data = $request->param();
		$this->validate($data,\app\admin\controller\Sys\validate\Menu::class);
		
		if($data['create_table'] && $data['table_name'] && $data['pk']){
			$table_name = strtolower(trim($data['table_name']));
			$pk = strtolower(trim($data['pk']));
			
			$connect = $data['connect'] ? $data['connect'] : config('database.default');
			$prefix = config('database.connections.'.$connect.'.prefix');

			$sql=" CREATE TABLE IF NOT EXISTS `".config('database.connections.mysql.prefix')."".$table_name."` ( ";
			$sql .= '
				`'.$pk.'` int(11) NOT NULL AUTO_INCREMENT ,
				PRIMARY KEY (`'.$data['pk'].'`)
				) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8;
			';
			Db::connect($connect)->execute($sql);
			Db::connect($connect)->execute("ALTER TABLE `".$prefix.$data['table_name']."` ADD `content_id` INT( 11 ) NOT NULL");
		}
        
		return $next($request);
    }
}