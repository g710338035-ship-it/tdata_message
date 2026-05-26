<?php /*a:2:{s:62:"/www/wwwroot/tdata.tgbota.top/app/admin/view/sockts/index.html";i:1770401388;s:66:"/www/wwwroot/tdata.tgbota.top/app/admin/view/common/container.html";i:1729069940;}*/ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="/assets/css/icons.min.css" rel="stylesheet" type="text/css" />
<link href="/assets/css/app.min.css" rel="stylesheet" type="text/css" />
<link href="/assets/element/index.css" rel="stylesheet">
<link href="/assets/css/base.css" rel="stylesheet">
<script src="/assets/element/vue.js"></script>
<script src="/assets/element/index.js"></script>
<script src="/assets/js/axios.min.js"></script>
<script src="/components/base.component.js"></script>
<script src="/assets/libs/jquery/jquery.min.js"></script>
<script src="/assets/libs/metismenu/metisMenu.min.js"></script>
<script src="/assets/js/js.cookie.min.js"></script>
<script src="/assets/js/common.js"></script>
<script type="text/javascript">
<?php
$action = request()->action();
$dialogstate = request()->get('dialogstate');
?>
const base_url = '<?php echo getBaseUrl()?>';
const base_dir = '';//勿要删除
</script>
</head>


<body>
<header id="page-topbar" style="position:fixed;left:0; top:0; z-index:999;<?php if($dialogstate == 1): ?>display:none;<?php endif; ?>">
	<div class="navbar-header">
		<div class="d-flex">
			<div class="navbar-header">
				<div class="d-flex" data-toggle="cospan">
					<i style="margin-left:15px;" id="vertical-menu-btn" class="el-icon-s-fold"></i>
					<i style="margin-left:15px;" @click="reload" class="el-icon-refresh hidden-sm-and-down"></i>
				</div>
				<div class="d-flex hidden-sm-and-down">
					<el-breadcrumb separator="/" style="margin-left:30px;">
						<el-breadcrumb-item v-for="item in levelList" :key="item.path">
							<a v-if="item.title == '首页'" :href="item.path">{{item.title}}</a>
							<span v-else>{{item.title}}</span>
						</el-breadcrumb-item>
					</el-breadcrumb>
				</div>
			</div>
		</div>
		<div class="d-flex">
			<div class="iconbutton">
				<el-tooltip content="清除缓存" effect="dark" placement="bottom">
					<i @click="clearCache()" class="icontool el-icon-delete"></i>
				</el-tooltip>
			</div>
		
			
			<div class="iconbutton">
				
				<el-dropdown trigger="click" placement="bottom" style="cursor: pointer;margin-right:15px;">
					<span class="el-dropdown-link">
						<?php echo session('admin.username'); ?><i style="margin-left:0px; font-size:100%" class="icontool el-icon-arrow-down"></i>
					</span>
					<el-dropdown-menu slot="dropdown">
						<el-dropdown-item icon="el-icon-lock" @click.native.prevent="passwordDialogStatus = true">修改密码</el-dropdown-item>
						<el-dropdown-item icon="el-icon-back" @click.native.prevent="logout">退出</el-dropdown-item>
					</el-dropdown-menu>
				</el-dropdown>
			</div>
			<div class="iconbutton" style="margin-left:0">
				<i class="bx bx-cog bx-spin right-bar-toggle"></i>
			</div>
		</div>
	</div>
	
	<el-dialog title="重置密码" style="margin-top:100px;" width="450px"  :visible="passwordDialogStatus" :before-close="closeForm" append-to-body>
		<el-form :size="size" ref="form" :model="form" :rules="rules" label-width="80px">
			<el-row>
				<el-col :span="24">
					<el-form-item label="新密码" prop="password">
						<el-input  show-password autoComplete="off" v-model="form.password"  clearable placeholder="请输入密码"/>
					</el-form-item>
				</el-col>
			</el-row>
			<el-row>
				<el-col :span="24">
					<el-form-item label="确认密码" prop="repassword">
						<el-input  show-password autoComplete="off" v-model="form.repassword"  clearable placeholder="请输入确认密码"/>
					</el-form-item>
				</el-col>
			</el-row>
		</el-form>
		<div slot="footer" class="dialog-footer">
			<el-button :size="size" :loading="loading" type="primary" @click="submit" >
				<span v-if="!loading">确 定</span>
				<span v-else>提 交 中...</span>
			</el-button>
			<el-button :size="size" @click="closeForm">取 消</el-button>
		</div>
	</el-dialog>
</header>

<script>
new Vue({
	el: '#page-topbar',
	data(){
		var validatePass2 = (rule, value, callback) => {
			if(value === '') {
				callback(new Error('请再次输入密码'))
			}else if (value !== this.form.password) {
				callback(new Error('两次输入密码不一致!'))
			}else {
				callback()
			}
		}
		return {
			form: {
				password:'',
				repassword:'',
			},
			url:{},
			levelList:[],
			notice:[],
			passwordDialogStatus:false,
			loading:false,
			size:'small',
			urlobj:{},//这里是判断如果是弹窗链接的话 不显示头部
			rules: {
                password: [{ required: true, message: '密码不能为空', trigger: 'blur' }],
				repassword:[
					{required: true, validator: validatePass2, trigger: 'blur'},
				],
			}
		}
	},
	mounted(){
		if(sessionStorage.getItem(base_url+'breadcrumb')){
			const menuList = JSON.parse(sessionStorage.getItem(base_url+'breadcrumb'))
			this.url = new URL(window.location.href)
			let menus = this.getMenus(menuList)
			let home = [{title:'首页', path:base_url+'/Index/main.html'}]
			if (menus !== undefined) {
				if(this.url.pathname !== base_url+'/Index/main.html' && this.url.href !== base_url+'/Index/main.html'){
					menus = home.concat(menus)
				}
			}else{
				menus = home
			}
			
			this.levelList = menus
		}
	},
	methods:{
		getMenus(menuList,arr,z){
            arr = arr || []
            z = z || 0
            for (let i = 0; i < menuList.length; i++) {
                let item = menuList[i]
                arr[z] = item
                if(this.url.pathname === menuList[i].url || this.url.href === menuList[i].url){
                   return arr.slice(0,z + 1)
                }
                if(menuList[i].children && menuList[i].children.length){
                   let res = this.getMenus(menuList[i].children,arr,z+1)
                   if(res){
                       return res
                   }
                }
            }
        },
		submit(){
			this.$refs['form'].validate(valid => {
				if(valid) {
					this.loading = true
					axios.post(base_url+'/Base/resetPwd',this.form).then(res => {
						if(res.data.status == 200){
							this.$message({message: '操作成功', type: 'success'})
							this.closeForm()
						}else{
							this.$message.error('修改失败')
						}
					}).catch(()=>{
						this.loading = false
					})
				}
			})
		},
		closeForm(){
			this.passwordDialogStatus = false
			this.loading = false
			if (this.$refs['form']!==undefined) {
				this.$refs['form'].resetFields()
			}
		},
		getNotice(){
			axios.post(base_url+'/Index/getNotice').then(res => {
				if(res.data.status == 200){
					this.notice = res.data.data
				}
			})
		},
		clearCache(){
			this.$confirm('确定清除缓存吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(()=>{
				axios.post(base_url+'/Base/clearCache').then(res => {
					if(res.data.status == 200){
						this.$message({message: '操作成功', type: 'success'})
					}
				})
			})
		},
		logout(){
			this.$confirm('确定注销并且退出系统?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(()=>{
				axios.get(base_url+'/Login/logout').then(res => {
					if(res.data.status == 200){
						sessionStorage.setItem(base_url+'breadcrumb','')
						Cookies.set(base_url+'menu','')
						top.parent.frames.location.href = base_url+'/login/index'
					}
				})
			})
		},
		reload(){
			location.reload()
		},
	},
})
</script>

<div id="app" class="page-content" :style="!urlobj.dialogstate ? 'margin-top:60px;':'margin-top:10px;'">
	<tab-tag v-if="!urlobj.dialogstate"></tab-tag>
	
<div style="margin:0 15px 15px 15px;">
<el-card shadow="never" style="min-height:650px;">	
			<div v-if="search_visible" id="search" class="search">
				<el-form ref="form" size="small" :model="searchData" inline>
	                <el-form-item label="分组">
            			<select-page :is_clear="is_clear" url="/Socktscate/getRobot_id" :selectval.sync="searchData.cateid"></select-page>
            		</el-form-item>
					<el-form-item label="状态">
						<el-select style="width:150px" v-model="searchData.status" filterable clearable placeholder="请选择">
							<el-option key="0" label="开启" value="1"></el-option>
							<el-option key="1" label="关闭" value="0"></el-option>
						</el-select>
					</el-form-item>

					<el-form-item label="创建时间">
						<el-date-picker type="daterange" v-model="searchData.create_time" clearable range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期"></el-date-picker>
					</el-form-item>
					<search-tool :search_data.sync="searchData" @refesh_list="index"></search-tool>
				</el-form>
			</div>
			<div class="btn-group" style="margin-top:10px;margin-bottom:10px;">
				<div>
					<el-button v-for="item in button_group" :key="item.access" v-if="checkPermission(item.access,'<?php echo implode(',',session('admin.access')); ?>','<?php echo session('admin.role_id'); ?>',[1])" :disabled="$data[item.disabled]" :type="item.color" size="mini" :icon="item.icon" @click="fn(item.clickname)">
						<span v-if="item.batch" v-text="$data['batchUpdateStatus']?'批量保存':'批量编辑'"></span>
						<span v-else v-text="item.name"></span>
					</el-button>
				</div>
				<div><table-tool :search_visible.sync="search_visible"  @refesh_list="index"></table-tool></div>
			</div>
			<el-table :row-class-name="rowClass" @selection-change="selection"  @row-click="handleRowClick"  row-key="id"  :header-cell-style="{ background: '#eef1f6', color: '#606266' }" v-loading="loading"  ref="multipleTable" border class="eltable" :data="list"  style="width: 100%">
				<el-table-column align="center" type="selection" width="42"></el-table-column>
				<el-table-column align="center"  property="class_name" label="分类" show-overflow-tooltip>
					<template slot-scope="scope">
						<span v-if="scope.row.socktscate">{{scope.row.socktscate.class_name}}</span>
						<span v-else >-</span>
					</template>
				</el-table-column>				
				<el-table-column align="center"  property="ip" label="IP" show-overflow-tooltip ></el-table-column>
				<el-table-column align="center"  property="port" label="端口" show-overflow-tooltip ></el-table-column>
				<el-table-column align="center"  property="username" label="用户名" show-overflow-tooltip ></el-table-column>
				<el-table-column align="center"  property="password" label="密码" show-overflow-tooltip ></el-table-column>
				<el-table-column align="center"  property="user_count" label="控制数" show-overflow-tooltip ></el-table-column>
				<el-table-column align="center"  property="status" label="状态" show-overflow-tooltip width="80">
					<template slot-scope="scope">
						<el-switch :active-value="1" :inactive-value="0" v-model="scope.row.status" @change="updateExt(scope.row,'status')" :loading="scope.row.statusLoading" ></el-switch>
					</template>
				</el-table-column>
				<el-table-column align="center"  property="addtime" label="创建时间" show-overflow-tooltip width="">
					<template slot-scope="scope">
						{{parseTime(scope.row.addtime,'{y}-{m}-{d}')}}
					</template>
				</el-table-column>
				<el-table-column :fixed="ismobile()?false:'right'" label="操作" align="center" width="180">
					<template slot-scope="scope">
						<div v-if="scope.row.id">
							<el-button v-if="checkPermission('/admin/Sockts/update.html','<?php echo implode(",",session("admin.access")); ?>','<?php echo session("admin.role_id"); ?>',[1])" size="mini" icon="el-icon-edit" type="primary" @click="update(scope.row)" >修改</el-button>
							<el-button v-if="checkPermission('/admin/Sockts/delete.html','<?php echo implode(",",session("admin.access")); ?>','<?php echo session("admin.role_id"); ?>',[1])" size="mini" icon="el-icon-delete" type="danger" @click="del(scope.row)" >删除</el-button>
						</div>
					</template>
				</el-table-column>
			</el-table>
			<Page :total="page_data.total" :page.sync="page_data.page" :limit.sync="page_data.limit" @pagination="index" />
		
</el-card>





<!--添加-->
<Add :show.sync="dialog.addDialogStatus" size="small" @refesh_list="index"></Add>
<!--修改-->
<Update :info="updateInfo" :show.sync="dialog.updateDialogStatus" size="small" @refesh_list="index"></Update>
<!--导入弹窗-->
<Simport :show.sync="dialog.importDataDialogStatus" size="small" @refesh_list="index"></Simport>
<!--导出弹窗-->
<el-dialog title="导出进度条" :visible="dumpshow" :before-close="closedialog" width="500px">
	<el-progress :percentage="percentage"></el-progress>
</el-dialog>

</div>

</div>

<div class="right-bar" id="rightbar">
	<div data-simplebar class="h-100">
		<div class="rightbar-title flex align-items-center bg-dark p-3">
			<h5 class="m-0 me-2 text-white">主题设置</h5>
			<a href="javascript:void(0);" class="right-bar-toggle ms-auto">
				<i class="mdi mdi-close noti-icon"></i>
			</a>
		</div>
		<div class="drawer-container">
			<div class="drawer-item">
				<span>标签页</span>
				<el-switch @change="selectTag" :active-value="1" :inactive-value="0" v-model="setting.tagsView" class="drawer-switch" />
			</div>
			<div class="drawer-item">
				<p>顶部背景色</p>
				<el-radio-group v-model="setting.topbg" @change="selectTopBg" size="mini">
					<el-radio-button label="light">白色</el-radio-button>
					<el-radio-button label="blank">黑色</el-radio-button>
					<el-radio-button label="dark">蓝色</el-radio-button>
				</el-radio-group>
			</div>
			<div class="drawer-item">
				<p>侧栏背景色</p>
				<el-radio-group v-model="setting.sidebg" @change="selectSideBg" size="mini">
					<el-radio-button label="dark">黑色</el-radio-button>
					<el-radio-button label="brand">蓝色</el-radio-button>
					<el-radio-button label="light">白色</el-radio-button>
				</el-radio-group>
			</div>
        </div>
	</div>
</div>
<div class="rightbar-overlay"></div>
<script>
var siteconfig 
if(Cookies.get(base_url+'siteconfig')){
	siteconfig = JSON.parse(Cookies.get(base_url+'siteconfig'))
	document.body.setAttribute('data-topbar', siteconfig.topbg)
	parent.document.body.setAttribute('data-sidebar', siteconfig.sidebg)
	var classname = !siteconfig.tagsView ? 'hiddenbox' : 'showbox'
	document.getElementById('app').setAttribute('tag-box', classname)
}

new Vue({
	el: '#rightbar',
	data(){
		return {
			setting:{
				tagsView:1,
				topbg:'light',
				sidebg:'dark',
			}
		}
	},
	mounted(){
		if(Cookies.get(base_url+'siteconfig')){
			this.setting = JSON.parse(Cookies.get(base_url+'siteconfig'))
		}
	},
	methods:{
		selectTopBg(val){
			document.body.setAttribute('data-topbar', val)
			Cookies.set(base_url+'siteconfig',JSON.stringify(this.setting))
		},
		selectSideBg(val){
			parent.document.body.setAttribute('data-sidebar', val)
			Cookies.set(base_url+'siteconfig',JSON.stringify(this.setting))
		},
		selectTag(val){
			var classname = !val ? 'hiddenbox' : 'showbox'
			document.getElementById('app').setAttribute('tag-box', classname)
			Cookies.set(base_url+'siteconfig',JSON.stringify(this.setting))
		}
	},
})
</script>

<script src="/assets/js/app.js"></script>
<script src="/assets/libs/xlsx/xlsx.core.min.js"></script>
<script src="/components/admin/sockts/add.js?v=<?php echo rand(1000,9999)?>"></script>
<script src="/components/admin/sockts/update.js?v=<?php echo rand(1000,9999)?>"></script>
<script src="/components/admin/sockts/simport.js?v=<?php echo rand(1000,9999)?>"></script>
<script src="/components/admin/sockts/dialogUrl.js?v=<?php echo rand(1000,9999)?>"></script>

<script>
new Vue({
	el: '#app',
	components:{
	},
	data: function() {
		return {
			dialog: {
				addDialogStatus : false,
				updateDialogStatus : false,
				importDataDialogStatus : false,
				dialogUrlDialogStatus : false,			
			},
			searchData:{},
			button_group:[
				{name:'添加',color:'success',access:'/admin/Sockts/add.html',icon:'el-icon-plus',disabled:'',clickname:'add'},
				{name:'导入',color:'warning',access:'/admin/Sockts/importData.html',icon:'el-icon-upload',disabled:'',clickname:'importData'},
				{name:'导出',color:'primary',access:'/admin/Sockts/dumpdata.html',icon:'el-icon-download',disabled:'',clickname:'dumpdata'},
				{name:'删除',color:'danger',access:'/admin/Sockts/delete.html',icon:'el-icon-delete',disabled:'multiple',clickname:'del'},	
			//	{name:'检测',color:'success',access:'/admin/Sockts/detection.html',icon:'el-icon-check',disabled:'',clickname:'detection'},
			],
			loading: false,
			page_data: {
				limit: 20,
				page: 1,
				total:20,
			},
			ids: [],
			single:true,
			multiple:true,
			search_visible:true,
			list: [],
			updateInfo:{},
			dialogUrlInfo:{},			
			exceldata:[],
			dumppage:1,
			ws:{},
			dumpshow:false,
			percentage:0,
			filename:'',
			
		}
	},
	methods:{
		index(){
			let param = {limit:this.page_data.limit,page:this.page_data.page}
			Object.assign(param, this.searchData)
			this.loading = true
			axios.post(base_url + '/Sockts/index',param).then(res => {
				if(res.data.status == 200){
					this.list = res.data.data.data
					this.page_data.total = res.data.data.total
					this.loading = false
				}else{
					this.$message.error(res.data.msg);
				}
			})
		},
		add(){
			this.dialog.addDialogStatus = true
		},
		
		updateExt(row,field){
			if(row.id){
				axios.post(base_url + '/Sockts/updateExt',{id:row.id,[field]:row[field]}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
					}else{
					    
						this.$message.error(res.data.msg)
					}
				})
			}
		},
		
		update(row){
			let id = row.id ? row.id : this.ids.join(',')
			console.log(id)
			axios.post(base_url + '/Sockts/getUpdateInfo',{id:id}).then(res => {
				if(res.data.status == 200){
					this.dialog.updateDialogStatus = true
					this.updateInfo = res.data.data
				}else{
					this.$message.error(res.data.msg)
				}
			})
		},
		detection(row){
		    // 显示加载提示（非全屏，顶部/中间提示）
            const loadingInstance = this.$loading({
                lock: true, // 锁定屏幕，禁止滚动
                text: '检测中，请稍后...', // 提示文本
                spinner: 'el-icon-loading', // 加载图标
                background: 'rgba(0, 0, 0, 0.7)' // 背景遮罩
              });

			let id = row.id ? row.id : this.ids.join(',')
			console.log(id)
			axios.post(base_url + '/Sockts/detection',{id:id}).then(res => {
			    loadingInstance.close();
				if(res.data.status == 200){
			        this.$message({message: res.data.msg, type: 'success'})
					this.index()
				}else{
					this.$message.error(res.data.msg)
				}
			})
            .catch(err => {
              // 请求失败（如网络错误）：关闭加载提示 + 显示错误
              loadingInstance.close();
              this.$message.error('检测请求失败，请检查网络或重试');
              console.error('检测接口异常：', err);
            });
		},
		del(row){
			this.$confirm('确定操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				let ids = row.id ? row.id : this.ids.join(',')
				axios.post(base_url + '/Sockts/delete',{id:ids}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
					}else{
						this.$message.error(res.data.msg)
					}
				})
			}).catch(() => {})
		},
		importData(){
			this.dialog.importDataDialogStatus = true
		},
		dumpdata(){
			this.$confirm('确定操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				this.dumpshow = true
				this.confirmdumpdata()
			}).catch(() => {})
		},
		confirmdumpdata() {
			let ids = this.ids.join(',')
			axios.post(base_url + '/Sockts/dumpdata', { page: this.dumppage,id:ids }).then(res => {
				console.log(res.data);
				if (res.data.data.length > 0) {
					if (this.dumppage == 1) {
						// 正确处理表头：使用制表符连接数组元素
						this.txtdata = res.data.header.join('\t') + '\n';
					}
					// 处理数据行：直接使用后端已经格式化好的字符串
					res.data.data.forEach((item) => {
						this.txtdata += item + '\n'; // 直接添加后端返回的格式化字符串
					});
					this.percentage = res.data.percentage;
					this.filename = res.data.filename;
					this.dumppage = this.dumppage + 1;
					this.confirmdumpdata();
				} else {
					// 创建一个Blob对象，设置为文本格式
					const blob = new Blob([this.txtdata], { type: 'text/plain;charset=utf-8' });
					// 创建下载链接
					const url = URL.createObjectURL(blob);
					const a = document.createElement('a');
					a.href = url;
					a.download = this.filename.endsWith('.txt') ? this.filename : this.filename + '.txt';
					// 触发下载
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
					// 释放URL对象
					URL.revokeObjectURL(url);
					this.dumpshow = false;
				}
			});
		},	
		closedialog(){
			this.dumpshow = false
		},
		selection(selection) {
			this.ids = selection.map(item => item.id)
			this.single = selection.length != 1
			this.multiple = !selection.length
		},
		handleRowClick(row, rowIndex,event){
			if(event.target.className !== 'el-input__inner'){
				this.$refs.multipleTable.toggleRowSelection(row)
			}
		},
		rowClass ({ row, rowIndex }) {
			for(let i=0;i<this.ids.length;i++) {
				if (row.membe_id === this.ids[i]) {
					return 'rowLight'
				}
			}
		},
		fn(method){
			this[method](this.ids)
		},
	},
	mounted(){
		this.index()
	},
})
</script>

</body>
</html>