<?php /*a:2:{s:62:"/www/wwwroot/tdata.tgbota.top/app/admin/view/mttask/index.html";i:1765707613;s:66:"/www/wwwroot/tdata.tgbota.top/app/admin/view/common/container.html";i:1729069940;}*/ ?>
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
		<el-form-item label="用户名">
			<el-input id="username" v-model="searchData.username"  style="width:150px;" placeholder="请输入用户名"></el-input>
		</el-form-item>
		
		<el-form-item label="运行状态">
			<el-select style="width:150px" v-model="searchData.status" filterable clearable placeholder="请选择">
				<el-option key="0" label="未运行" value="1"></el-option>
				<el-option key="1" label="运行中" value="2"></el-option>
				<el-option key="2" label="已完成" value="3"></el-option>
				<el-option key="3" label="失败" value="4"></el-option>
				<el-option key="4" label="已停止" value="5"></el-option>
				<el-option key="5" label="无账号" value="6"></el-option>
			</el-select>
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
	<el-table-column align="center" property="id" label="ID"  width="42"></el-table-column>
	<el-table-column align="center"  property="title" label="任务名称"  ></el-table-column>

	<el-table-column align="center"  property="mtcate.class_name" label="账号组"  ></el-table-column>
	<el-table-column align="center" label="总数" >
      <template slot-scope="scope">
        <!-- 确保数值存在，避免 undefined 导致计算错误 -->
        {{ (scope.row.success_count || 0) + (scope.row.fail_count || 0) }}
      </template>
    </el-table-column>
	<el-table-column align="center"  property="success_count" label="成功数"  ></el-table-column>
	<el-table-column align="center"  property="fail_count" label="失败数"  ></el-table-column>
	
	<el-table-column align="center"  property="status" label="运行状态">
		<template slot-scope="scope">
			<el-tag v-if="scope.row.status == 1" type="info">未运行</el-tag>
			<el-tag v-if="scope.row.status == 2" type="success">运行中</el-tag>
			<el-tag v-if="scope.row.status == 3" type="primary">已完成</el-tag>
			<el-tag v-if="scope.row.status == 4" type="primary">启动失败</el-tag>
			<el-tag v-if="scope.row.status == 5" type="warning">已停止</el-tag>
			<el-tag v-if="scope.row.status == 6" type="warning">无账号</el-tag>
		</template>
	</el-table-column>
	<el-table-column align="center"  property="create_time" label="创建时间"  width="">
		<template slot-scope="scope">
			{{parseTime(scope.row.create_time,'{y}-{m}-{d}  {h}:{i}')}}
		</template>
	</el-table-column>
	<el-table-column :fixed="ismobile()?false:'right'" label="操作" align="center"  width="320">
		<template slot-scope="scope">
			<div v-if="scope.row.id">
			    	<!-- 开始任务按钮 - 只在未运行或已停止状态显示 -->
				<el-button v-if="(scope.row.status == 1 || scope.row.status == 3 || scope.row.status == 5 || scope.row.status == 4 || scope.row.status == 6) && checkPermission('/admin/Mttask/start.html','<?php echo implode(",",session("admin.access")); ?>','<?php echo session("admin.role_id"); ?>',[1])" 
					size="mini" icon="el-icon-play" type="success" 
					@click="startTask(scope.row)" >
					开始
				</el-button>
				
				<!-- 停止任务按钮 - 只在运行中状态显示 -->
				<el-button v-if="scope.row.status == 2 && checkPermission('/admin/Mttask/stop.html','<?php echo implode(",",session("admin.access")); ?>','<?php echo session("admin.role_id"); ?>',[1])" 
					size="mini" icon="el-icon-stop" type="warning" 
					@click="stopTask(scope.row)" >
					停止
				</el-button>
			    
			    
				<el-button v-if="scope.row.status != 2 && checkPermission('/admin/Mttask/update.html','<?php echo implode(",",session("admin.access")); ?>','<?php echo session("admin.role_id"); ?>',[1])" size="mini" icon="el-icon-edit" type="primary" @click="update(scope.row)" >修改</el-button>
				<el-button v-if="scope.row.status != 2 && checkPermission('/admin/Mttask/delete.html','<?php echo implode(",",session("admin.access")); ?>','<?php echo session("admin.role_id"); ?>',[1])" size="mini" icon="el-icon-delete" type="danger" @click="del(scope.row)" >删除</el-button>
			    
			    <el-button v-if="scope.row.status != 1" size="mini" icon="el-icon-delete" type="info" @click="tasklog(scope.row)" >日志</el-button>
			
			</div>
		</template>
	</el-table-column>
</el-table>
<Page :total="page_data.total" :page.sync="page_data.page" :limit.sync="page_data.limit" @pagination="index" />
</el-card>


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
				detailDialogStatus : false,				
			},
			searchData:{},
			button_group:[
				{name:'添加',color:'success',access:'/admin/Mttask/add.html',icon:'el-icon-plus',disabled:'',clickname:'add'},		
				{name:'删除',color:'danger',access:'/admin/Mttask/delete.html',icon:'el-icon-delete',disabled:'multiple',clickname:'del'},
				//{name:'批量开始',color:'success',access:'/admin/Mttask/start.html',icon:'el-icon-play',disabled:'multiple',clickname:'batchStartTask'},				
			//	{name:'批量停止',color:'warning',access:'/admin/Mttask/stop.html',icon:'el-icon-stop',disabled:'multiple',clickname:'batchStopTask'},
			],
			loading: false,
			page_data: {
				limit: 30,
				page: 1,
				total:30,
			},
			ids: [],
			single:true,
			multiple:true,
			search_visible:true,
			list: [],
			updateInfo:{},			
			exceldata:[],
			dumppage:1,
			ws:{},
			dumpshow:false,
			percentage:0,
			filename:'',
			refreshTimer: null,
            refreshingTasks: []
		}
	},
	mounted(){
        // 每10秒检查一次运行中任务的状态
        this.refreshTimer = setInterval(() => {
            console.log(123);
        }, 1000);
    },
    // 在组件销毁前清除定时器
    beforeDestroy() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
        }
    },
	methods:{
		index(){
			let param = {limit:this.page_data.limit,page:this.page_data.page}
			Object.assign(param, this.searchData)
			this.loading = true
			axios.post(base_url + '/Mttask/index',param).then(res => {
				if(res.data.status == 200){
					this.list = res.data.data.data
					this.page_data.total = res.data.data.total
					this.loading = false
				    this.refreshTimer = setInterval(() => {
                        this.checkRunningTasks();
                    }, 30000);
				}else{
					this.$message.error(res.data.msg);
				}
			})
		},
		add(){
			const baseUrl = base_url + '/Mttask/addtask';
        	window.location.href = baseUrl;
		},
		
		updateExt(row,field){
			if(row.id){
				axios.post(base_url + '/Mttask/updateExt',{id:row.id,[field]:row[field]}).then(res => {
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
			
			const baseUrl = base_url + '/Mttask/updatetask?id='+id;
        	window.location.href = baseUrl;
		},
		del(row){
			this.$confirm('确定操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				let ids = row.id ? row.id : this.ids.join(',')
				axios.post(base_url + '/Mttask/delete',{id:ids}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
					}else{
						this.$message.error(res.data.msg)
					}
				})
			}).catch(() => {})
		},

	    // 开始单个任务
		startTask(row) {
		
			axios.post(base_url + '/Mttask/start', {id: row.id}).then(res => {
				if(res.data.status == 200){
					this.$message({message: res.data.msg, type: 'success'})
					setTimeout (() => {
                        this.index ()
                        }, 2000) 
				}else{
					this.$message.error(res.data.msg)
				}
			})
		
		},

		// 停止单个任务
		stopTask(row) {
			this.$confirm('确定要停止此任务吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				axios.post(base_url + '/Mttask/stop', {id: row.id}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
					}else{
						this.$message.error(res.data.msg)
					}
				})
			}).catch(() => {})
		},
        tasklog(row) {
            if (!row.id) {
                this.$message.error('任务ID不存在');
                return;
            }
            const url = `/uploads/task_logs/${row.id}.txt`;
            // 创建一个隐藏的 a 标签用于下载
            const link = document.createElement('a');
            link.href = url;
            link.download = `tasklog_${row.id}.txt`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },
        checkRunningTasks() {
            console.log(1);
            // 获取所有运行中任务的ID
            const runningTaskIds = this.list
                .filter(task => task.status === 2) // 运行中
                .map(task => task.id);
            
            if (runningTaskIds.length > 0) {
                axios.post(base_url + '/Mttask/checkStatus', {ids: runningTaskIds}).then(res => {
                    if(res.data.status == 200 && res.data.data) {
                        // 更新运行中任务的状态
                        res.data.data.forEach(updatedTask => {
                            const taskIndex = this.list.findIndex(task => task.id === updatedTask.id);
                            if (taskIndex !== -1) {
                                this.$set(this.list, taskIndex, {...this.list[taskIndex], ...updatedTask});
                            }
                        });
                    }
                });
            }
        },
		// 批量开始任务
		batchStartTask() {
			if(this.ids.length === 0) {
				this.$message.warning('请选择要开始的任务')
				return
			}
			
			this.$confirm(`确定要开始选中的 ${this.ids.length} 个任务吗?`, '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'info'
			}).then(() => {
				axios.post(base_url + '/Mttask/start', {id: this.ids.join(',')}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
					}else{
						this.$message.error(res.data.msg)
					}
				})
			}).catch(() => {})
		},

		// 批量停止任务
		batchStopTask() {
			if(this.ids.length === 0) {
				this.$message.warning('请选择要停止的任务')
				return
			}
			
			this.$confirm(`确定要停止选中的 ${this.ids.length} 个任务吗?`, '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				axios.post(base_url + '/Mttask/stop', {id: this.ids.join(',')}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
					}else{
						this.$message.error(res.data.msg)
					}
				})
			}).catch(() => {})
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