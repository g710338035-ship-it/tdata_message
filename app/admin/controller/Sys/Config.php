<?php
namespace app\admin\controller\Sys;

class Config
{
	
	//字段列表
	public static function fieldList(){
		$list=[
			[
				'name'=>'文本框',
				'type'=>1,
				'property'=>1,
				'search'=>true,
			],
			[
				'name'=>'下拉框',
				'type'=>2,
				'property'=>3,
				'item'=>true,
				'search'=>true,
			],
			[
				'name'=>'下拉框(多选)',
				'type'=>3,
				'property'=>1,
				'item'=>true,
				'search'=>true,
			],
			[
				'name'=>'单选框',
				'type'=>4,
				'property'=>3,
				'item'=>true,
				'search'=>true,
			],
			[
				'name'=>'多选框',
				'type'=>5,
				'property'=>1,
				'item'=>true,
				'search'=>true,
			],
			[
				'name'=>'开关按钮',
				'type'=>6,
				'property'=>6,
				'item'=>true,
				'search'=>true,
			],
			[
				'name'=>'密码框',
				'type'=>7,
				'property'=>1,
				'search'=>false,
			],
			[
				'name'=>'文本域',
				'type'=>8,
				'property'=>4,
				'search'=>true,
			],
			[
				'name'=>'日期框',
				'type'=>9,
				'property'=>2,
				'search'=>false,
			],
			[
				'name'=>'日期范围',
				'type'=>10,
				'property'=>1,
				'search'=>false,
			],
			[
				'name'=>'单图上传',
				'type'=>13,
				'property'=>1,
				'search'=>false,
			],
			[
				'name'=>'多图上传',
				'type'=>14,
				'property'=>4,
				'search'=>false,
			],
			[
				'name'=>'单文件上传',
				'type'=>15,
				'property'=>1,
				'search'=>false,
			],
			[
				'name'=>'多文件上传',
				'type'=>16,
				'property'=>4,
				'search'=>false,
			],
			[
				'name'=>'计数器',
				'type'=>17,
				'property'=>5,
				'search'=>false,
			],
			[
				'name'=>'标签',
				'type'=>18,
				'property'=>1,
				'search'=>true,
			],
			[
				'name'=>'滑块',
				'type'=>19,
				'property'=>3,
				'search'=>false,
			],
			[
				'name'=>'颜色选择器',
				'type'=>20,
				'property'=>1,
				'search'=>false,
			],
			[
				'name'=>'键值对',
				'type'=>21,
				'property'=>4,
				'search'=>false,
			],
			[
				'name'=>'省市区联动',
				'type'=>22,
				'property'=>1,
				'search'=>false,
			],
			[
				'name'=>'百度地图坐标选择器',
				'type'=>23,
				'property'=>4,
				'search'=>false,
			],
			[
				'name'=>'高德地图坐标选择器',
				'type'=>24,
				'property'=>4,
				'search'=>false,
			],
			[
				'name'=>'腾讯地图坐标选择器',
				'type'=>28,
				'property'=>4,
				'search'=>false,
			],
			[
				'name'=>'编辑器(wangeditor)',
				'type'=>25,
				'property'=>4,
				'search'=>false,
			],
			[
				'name'=>'编辑器(tinymce)',
				'type'=>26,
				'property'=>8,
				'search'=>false,
			],
		];
        return $list;
	}
	
	
	
	//字段的数据结构
    public static function propertyField(){
        $list= [
            ['type'=>1,'name'=>'varchar','maxlen'=>250,'decimal'=>0],
            ['type'=>2,'name'=>'int','maxlen'=>11,'decimal'=>0],
			['type'=>3,'name'=>'smallint','maxlen'=>6,'decimal'=>0],
            ['type'=>4,'name'=>'text','maxlen'=>0,'decimal'=>0],
			['type'=>8,'name'=>'longtext','maxlen'=>0,'decimal'=>0],
            ['type'=>5,'name'=>'decimal','maxlen'=>10,'decimal'=>2],
			['type'=>6,'name'=>'tinyint','maxlen'=>4,'decimal'=>0],
			['type'=>7,'name'=>'datetime','maxlen'=>0,'decimal'=>0],
        ];
        return $list;
    }
	
	public static function itemList(){
		$list = [
			[
				'name'=>'性别',
				'item'=>[
					['key'=>'男','val'=>'1','label_color'=>'primary'],
					['key'=>'女','val'=>'2','label_color'=>'warning'],
				]
			],
			[
				'name'=>'状态',
				'item'=>[
					['key'=>'正常','val'=>'1','label_color'=>'primary'],
					['key'=>'禁用','val'=>'0','label_color'=>'danger'],
				]
			],
			[
				'name'=>'开关',
				'item'=>[
					['key'=>'开启','val'=>'1'],
					['key'=>'关闭','val'=>'0'],
				]
			]
		];
		
		return $list;
	}
	
	//字段验证规则列表
	public static function ruleList(){
		$list = [
			'邮箱'	=> '/^([a-zA-Z0-9_-])+@([a-zA-Z0-9_-])+(.[a-zA-Z0-9_-])+/',
			'网址'	=> '/^((ht|f)tps?):\/\/([\w\-]+(\.[\w\-]+)*\/)*[\w\-]+(\.[\w\-]+)*\/?(\?([\w\-\.,@?^=%&:\/~\+#]*)+)?/',
			'货币'	=> '/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/',
			'数字'	=> '/^[0-9]*$/',
			'手机号'=> '/^1[3456789]\d{9}$/',
			'身份证'=> '/^[1-9]\d{5}(18|19|20|(3\d))\d{2}((0[1-9])|(1[0-2]))(([0-2][1-9])|10|20|30|31)\d{3}[0-9Xx]$/',
		];
		return $list;
	}
	
	
	
	//内置的短信平台
	public static function sms_list(){
		$list = [
			['name'=>'阿里','type'=>'Ali'],
			['name'=>'聚合','type'=>'Juhe'],
			['name'=>'极速','type'=>'Jisu'],
		];
		return $list;
	}
	
	
	

}
