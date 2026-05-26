<?php /*a:2:{s:61:"/www/wwwroot/tdata.tgbota.top/app/admin/view/index/index.html";i:1635492092;s:61:"/www/wwwroot/tdata.tgbota.top/app/admin/view/common/left.html";i:1635492092;}*/ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title><?php echo config('base_config.site_title'); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="/assets/css/icons.min.css" rel="stylesheet" type="text/css" />
<link href="/assets/css/app.min.css" rel="stylesheet" type="text/css" />
<link href="/assets/element/index.css" rel="stylesheet">
<script src="/assets/js/axios.min.js"></script>
<script src="/assets/libs/jquery/jquery.min.js"></script>
<script src="/assets/element/vue.js"></script>
<script src="/assets/js/js.cookie.min.js"></script>
<style>
html,
body {
    height: 100%;
}

body.full-height-layout layout-wrapper,{
    height: 100%;
}
#content-main {
    height: calc(100% - 100px);
    overflow: hidden;
}
</style>
</head>

<body class="full-height-layout" style="height:100%; overflow:hidden" data-sidebar="dark">
<div id="layout-wrapper" style="height:100%">
	<div class="vertical-menu">
		<div data-simplebar style="height: 100%!important">
			<div id="sidebar-menu">
	<div class="navbar-brand-box">
		<div class="logo logo-light">
			<span class="logo-sm">
				<img src="/assets/images/vue.png" alt="" height="22">
			</span>
			<span class="logo-lg" style="font-size:16px;">
				<?php echo config('base_config.site_title'); ?>
			</span>
		</div>
	</div>
	<ul class="metismenu list-unstyled" id="side-menu">
		
	</ul>
</div>
<script type="text/javascript">
const base_url = '<?php echo getBaseUrl()?>';

axios.post(base_url + '/Base/getMenu').then(res => {
	if(res.data.status == 200){
		Cookies.set(base_url+'breadcrumb','')
		var str = '';
		res.data.data.forEach(one=>{
			if(!one.children || one.children.length == 0){
				str += '<li><a target="iframe0" class="waves-effect j_menu" href="'+one.url+'"><i class="'+one.icon+'"></i><span key="'+one.url+'">'+one.title+'</span></a></li>'
			}else{
				str += '<li><a target="iframe0" class="has-arrow waves-effect" href="javascript: void(0);"><i class="'+one.icon+'"></i><span key="'+one.url+'">'+one.title+'</span></a>'
				str += '<ul class="sub-menu" aria-expanded="false">'
				one.children.forEach(two=>{
					if(!two.children || two.children.length == 0){
						str += '<li><a target="iframe0" class="waves-effect j_menu" href="'+two.url+'"><i class="'+two.icon+'"></i><span key="'+two.url+'">'+two.title+'</span></a></li>'
					}else{
						str += '<li><a target="iframe0" class="has-arrow waves-effect" href="javascript: void(0);"><i class="'+two.icon+'"></i><span key="'+two.url+'">'+two.title+'</span></a>'
						str += '<ul class="sub-menu" aria-expanded="false">'
						two.children.forEach(third=>{
							str += '<li><a target="iframe0" class="waves-effect j_menu" href="'+third.url+'"><i class="'+third.icon+'"></i><span key="'+third.url+'">'+third.title+'</span></a></li>'
						})
						str += '</ul>'
					}
				})
				str += '</ul>'
				str +='</li>'
			}
		})
		
		$('#side-menu').metisMenu('dispose');
		$("#side-menu").append(str);
		$('#side-menu').metisMenu();
		sessionStorage.setItem(base_url+'breadcrumb',JSON.stringify(res.data.data))
	}
})

$(function(){
	Cookies.set(base_url+'menu','')
	$(document).on('click','.j_menu',function(){
		menu = []
		var path = {
			title:$(this).find('span').text(),
			url:$(this).attr('href'),
			fullurl:$(this).attr('href'),
		}
				
		if(path.title !== '首页'){
			var noUrl = true
			if(Cookies.get(base_url+'menu')){
				var tags = JSON.parse(Cookies.get(base_url+'menu'))
				tags.forEach(item=>{
					if(item.url == path.url){
						noUrl = false 
					}
				})
				if(noUrl){
					tags.push(path)
					Cookies.set(base_url+'menu',JSON.stringify(tags))
				}
			}else{
				menu.push(path)
				Cookies.set(base_url+'menu',JSON.stringify(menu))
			}
		}
	});
})
</script>
		</div>
	</div>
	<div id="content-main" class="main-content" style="height:100%">
		<iframe class="J_iframe" name="iframe0" width="100%" height="100%" src="<?php echo url('admin/Index/main'); ?>" frameborder="0" data-id="<?php echo url('admin/Index/main'); ?>" seamless></iframe>
	</div>
</div>
<div class="menubar-overlay"></div>
<script src="/assets/libs/metismenu/metisMenu.min.js"></script>
<script src="/assets/libs/simplebar/simplebar.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>