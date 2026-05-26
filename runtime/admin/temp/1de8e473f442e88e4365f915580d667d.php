<?php /*a:2:{s:62:"/www/wwwroot/tdata.tgbota.top/app/admin/view/mtuser/index.html";i:1770017644;s:66:"/www/wwwroot/tdata.tgbota.top/app/admin/view/common/container.html";i:1729069940;}*/ ?>
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
	
<div style="margin:0 15px 15px 15px; padding-bottom:40px">
<el-card shadow="never" style="min-height:650px;">
<div v-if="search_visible" id="search" class="search">
	<el-form ref="form" size="small" :model="searchData" inline>
		<el-form-item label="昵称/ID">
			<el-input id="nickName" v-model="searchData.nickName"  style="width:150px;" placeholder="账号昵称/用户Userid/ID"></el-input>
		</el-form-item>
		<el-form-item label="手机号">
			<el-input id="account" v-model="searchData.account"  style="width:150px;" placeholder="手机号"></el-input>
		</el-form-item>
		<el-form-item label="分组">
			<select-page :is_clear="is_clear" url="/Mtcate/getRobot_id" :selectval.sync="searchData.cateid"></select-page>
		</el-form-item>
		<el-form-item label="客服">
			<select-page :is_clear="is_clear" url="/Mtcustomer/getRobot_id" :selectval.sync="searchData.customid"></select-page>
		</el-form-item>
		<el-form-item label="在线状态">
			<el-select style="width:150px" v-model="searchData.online" filterable clearable placeholder="请选择">
				<el-option key="0" label="在线" value="1"></el-option>
				<el-option key="1" label="离线" value="0"></el-option>
			</el-select>
		</el-form-item>
		<el-form-item label="状态">
			<el-select style="width:150px" v-model="searchData.status" filterable clearable placeholder="请选择">
				<el-option key="0" label="开启" value="1"></el-option>
				<el-option key="1" label="关闭" value="0"></el-option>
			</el-select>
		</el-form-item>
        <el-form-item label="账号状态">
			<el-select     style="width:150px"     v-model="searchData.account_status"     filterable     clearable     placeholder="请选择">
                <el-option key="0" label="正常" value="正常"></el-option>
                <el-option key="1" label="封号" value="封号"></el-option>
                <el-option key="2" label="代理异常" value="代理异常"></el-option>
                <el-option key="3" label="异常" value="异常"></el-option>
                <el-option key="4" label="空号" value="空号"></el-option>
                <el-option key="5" label="注销" value="注销"></el-option>
                <el-option key="6" label="未授权" value="未授权"></el-option>
                <el-option key="7" label="退出" value="退出"></el-option>
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
		
		<el-dropdown trigger="click">
			<el-button  type="primary" size="mini">
				分配客服 <i class="el-icon-arrow-down el-icon--right"></i>
			</el-button>
			<template #dropdown>
				<el-dropdown-menu>
					<el-dropdown-item  @click.native="allocateCustom">分配</el-dropdown-item>
					<el-dropdown-item  @click.native="delCustom">删除</el-dropdown-item>
					
				</el-dropdown-menu>
			</template>
		</el-dropdown>

		<el-dropdown trigger="click">
			<el-button type="primary" size="mini">
				Sockts <i class="el-icon-arrow-down el-icon--right"></i>
			</el-button>
			<template #dropdown>
				<el-dropdown-menu>
					<el-dropdown-item @click.native="batchOperate('allocate')">分配</el-dropdown-item>
					<el-dropdown-item @click.native="batchOperate('deletesockets')">删除</el-dropdown-item>
				</el-dropdown-menu>
			</template>
		</el-dropdown>

		<el-dropdown trigger="click">
			<el-button type="primary" size="mini">
				更多操作 <i class="el-icon-arrow-down el-icon--right"></i>
			</el-button>
			<template #dropdown>
				<el-dropdown-menu>
					<el-dropdown-item @click.native="batchOperate('exitGroups')">一键退出群聊</el-dropdown-item>
					<el-dropdown-item @click.native="batchOperate('checkAccount')">检测账号</el-dropdown-item>
				</el-dropdown-menu>
			</template>
		</el-dropdown>
		<el-select
          v-model="searchData.account_status_dec"
          filterable
          clearable
          size='mini'
          placeholder="账户状态"
          @change="handleFilterChange"
          style="width:150px;">
          <el-option
            v-for="item in tagFilters"
            :key="item.value"
            :label="item.text"
            :value="item.value">
          </el-option>
        </el-select>
	</div>
	<div>
	    <table-tool :search_visible.sync="search_visible"  @refesh_list="index"></table-tool>
	   </div>
</div>

<!-- 任务进度展示区域 -->
<el-progress 
  v-if="showProgress" 
  :percentage="progressData.percentage" 
  v-bind="progressData.status === 'completed' ? { status: 'success' } : {}"
  :stroke-width="6"
  style="margin-bottom: 15px;"
>
  <template #format="percentage">
    <div>
      <span>处理进度: {{ percentage }}%</span>
      <span style="margin-left: 10px;">({{ progressData.completed }}/{{ progressData.total }})</span>
      <span v-if="progressData.status === 'completed'" style="margin-left: 10px;">
        成功: {{ progressData.success }}, 失败: {{ progressData.failed }}
      </span>
    </div>
  </template>
</el-progress>

<el-table :row-class-name="rowClass" @selection-change="selection"  @row-click="handleRowClick"  row-key="id"   :header-cell-style="{ background: '#eef1f6', color: '#606266' }" v-loading="loading"  ref="multipleTable" border class="eltable" :data="list" height="65vh" style="width: 100%" >
	<el-table-column align="center" type="selection" width="42"></el-table-column>
    <el-table-column align="center"  property="id" label="id">	</el-table-column>
	<el-table-column align="center"  property="avatar_url" label="头像" show-overflow-tooltip width="">
		<template slot-scope="scope">
			<div class="demo-image__preview">
				<el-image class="table_list_pic" :src="scope.row.avatar_url || 'https://s160.avatar.talk.zdn.vn/default'"  :preview-src-list="[scope.row.avatar_url || 'https://s160.avatar.talk.zdn.vn/default']"></el-image>
			</div>
		</template>
	</el-table-column>

	<el-table-column align="center"  property="region" label="国家" show-overflow-tooltip >	</el-table-column>

	 <el-table-column align="center"  property="account" label="手机" show-overflow-tooltip  width="170">
	    <template slot-scope="scope">
			{{scope.row.account}} <el-tag type="danger" v-if="scope.row.online" size="mini" >在线</el-tag>
			<el-tag type="info" size="mini"  v-else>离线</el-tag>
		</template>
	</el-table-column>
	<el-table-column align="center"  property="uuid" label="Userid" show-overflow-tooltip >	</el-table-column>
	<el-table-column align="center"  property="username" label="用户名" show-overflow-tooltip >	</el-table-column>
	<el-table-column align="center"  property="nickName" label="昵称" show-overflow-tooltip >	</el-table-column>
	<el-table-column align="center"  property="friends_count" label="好友" sortable show-overflow-tooltip >	</el-table-column>
	<el-table-column align="center"  property="groups_count" label="群" show-overflow-tooltip >	</el-table-column>
	<el-table-column align="center"  property="Mtcate.class_name" label="账号组" show-overflow-tooltip >
	</el-table-column>
	
	<el-table-column align="center"  property="proxyip" label="Sockts代理" show-overflow-tooltip >	</el-table-column>
	<el-table-column align="center"  property="Mtcustom.username" label="客服" show-overflow-tooltip >	</el-table-column>
	
	<el-table-column align="center"  property="account_status" label="账号状态" show-overflow-tooltip >
	    <template slot-scope="scope">
          <el-tag
            :type="getTagType(scope.row.account_status)"
            size="mini"
          >
            {{ scope.row.account_status }}
          </el-tag>
        </template>
	    
	 </el-table-column>
	
      <el-table-column
        prop="tag"
        label="账号描述"
        width="100"
        :filters="tagFilters"
        :filter-method="filterTag"
        filter-placement="bottom-end"
        align="center" show-overflow-tooltip>
        <template slot-scope="scope">
        <!-- 手动用el-tooltip包裹内容 -->
        <el-tooltip
          :content="getAccountDesc(scope.row)" 
          placement="top"  
          effect="dark"   
        >
          <!-- 同样需要溢出隐藏，确保长文本显示省略号 -->
          <div style="width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ getAccountDesc(scope.row) }}
          </div>
        </el-tooltip>
      </template>
      </el-table-column>

	<el-table-column align="center"  property="Adminuser.name" label="管理员" show-overflow-tooltip >
	    <template slot-scope="scope">
			<span v-if="scope.row.Adminuser">{{scope.row.Adminuser.name}}</span>
			<span v-else>通用</span>
		</template>
	</el-table-column>
	<el-table-column align="center"  property="addtime" label="创建时间" show-overflow-tooltip width="">
		<template slot-scope="scope">
			{{parseTime(scope.row.addtime,'{y}-{m}-{d}')}}
		</template>
	</el-table-column>
	<el-table-column :fixed="ismobile()?false:'right'" label="操作" align="center"  width="150">
		<template slot-scope="scope">
			<div v-if="scope.row.id">
			    
				<el-button v-if="checkPermission('/admin/Mtarchive/delete.html','<?php echo implode(",",session("admin.access")); ?>','<?php echo session("admin.role_id"); ?>',[1])" size="mini" icon="el-icon-delete" type="danger" @click="del(scope.row)" >删除</el-button>
			</div>
		</template>
	</el-table-column>
</el-table>
<div style="position: fixed;bottom: 0px;z-index: 999;left: 15px;display: flex;justify-content: start;background: rgb(255, 255, 255);right: 15px;align-items: center; color:#606266;font-size:13px">
    <div v-if="chooseNum>0" style="padding:15px 10px;">选中 <font color=red>{{chooseNum}}</font> 条</div>
    <Page :total="page_data.total" :page.sync="page_data.page" :limit.sync="page_data.limit" @pagination="index" />
</div>    
</el-card>
<!-- 返回顶部按钮 -->
<div id="backToTop" class="back-to-top" @click="scrollToTop">
  <el-button icon="el-icon-arrow-up" type="primary" size="mini" circle></el-button>
</div>
<!--修改-->
<Update :info="updateInfo" :show.sync="dialog.updateDialogStatus" size="small" @refesh_list="index"></Update>
<!--转移-->
<Transfer :info="transferInfo" :show.sync="dialog.transferDialogStatus" size="small" @refesh_list="index"></Transfer>

<Allocatecustom :info="allocatecustomInfo" :show.sync="dialog.allocatecustomDialogStatus" size="small" @refesh_list="index"></Allocatecustom>
<Allocatesockts :info="allocatesocktsInfo" :show.sync="dialog.allocatesocktsDialogStatus" size="small" @refesh_list="index"></Allocatesockts>
<!--修改昵称-->
<Upnick :info="upnickInfo" :show.sync="dialog.upnickDialogStatus" size="small" @refesh_list="index"></Upnick>
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
<script src="/components/admin/mtuser/update.js?v=<?php echo rand(1000,9999)?>"></script>
<script src="/components/admin/mtuser/upnick.js?v=<?php echo rand(1000,9999)?>"></script>
<script src="/components/admin/mtuser/transfer.js?v=<?php echo rand(1000,9999)?>"></script>
<script src="/components/admin/mtuser/allocatecustom.js?v=<?php echo rand(1000,9999)?>"></script>
<script src="/components/admin/mtuser/allocatesockts.js?v=<?php echo rand(1000,9999)?>"></script>

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
				upnickDialogStatus : false,
				detailDialogStatus : false,	
				transferDialogStatus:false,
				allocatesocktsDialogStatus:false,
				allocatecustomDialogStatus:false,	
			},
			searchData:{},
			button_group:[
				{name:'归档',color:'warning',access:'/admin/Mtuser/archive.html',icon:'el-icon-position',disabled:'multiple',clickname:'archive'},
				{name:'转移分组',color:'warning',access:'/admin/Mtuser/transfer.html',icon:'el-icon-sort',disabled:'multiple',clickname:'transfer'},
				{name:'修改资料',color:'primary',access:'/admin/Mtuser/update.html',icon:'el-icon-edit',disabled:'single',clickname:'update'},
				{name:'批量改昵称',color:'info',access:'/admin/Mtuser/upnick.html',icon:'el-icon-edit',disabled:'multiple',clickname:'upnick'},
				{name:'上线',color:'success',access:'/admin/Mtuser/accountup.html',icon:'el-icon-top',disabled:'multiple',clickname:'accountup'},
				{name:'下线',color:'danger',access:'/admin/Mtuser/accountdown.html',icon:'el-icon-bottom',disabled:'multiple',clickname:'accountdown'},
				
				{name:'批量删除',color:'danger',access:'/admin/Mtarchive/delete.html',icon:'el-icon-delete',disabled:'multiple',clickname:'del'},
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
			transferInfo:{},
			upnickInfo:{},
			allocatecustomInfo:{},	
			allocatesocktsInfo:{},
			codeInfo:{},
			exceldata:[],
			dumppage:1,
			ws:{},
			dumpshow:false,
			percentage:0,
			filename:'',
			is_clear:false,
			// 新增：任务进度相关
			showProgress: false,
			progressData: {
				total: 0,
				completed: 0,
				success: 0,
				failed: 0,
				status: '',
				percentage: 0
			},
			progressInterval: null,
			currentBatchId: '',
			 tagFilters: [],
			 chooseNum:0,
		}
	},
	created() {
  
        this.fetchTagFilters();
    },
	methods:{
		index(){
			let param = {limit:this.page_data.limit,page:this.page_data.page}
			Object.assign(param, this.searchData)
			this.loading = true
			axios.post(base_url + '/Mtuser/index',param).then(res => {
				if(res.data.status == 200){
					this.list = res.data.data.data
					this.page_data.total = res.data.data.total
					this.loading = false
				}else{
					this.$message.error(res.data.msg);
				}
			})
		},
		getTagType(status) {
            switch(status) {
              case '正常': return 'success';
              case '冻结': return 'danger';
              case '封号': return 'warning';
              case '异常': return 'info';
              case '空号': return 'warning';
              case '退出': return 'warning';
              case '注销': return 'info';
              case '未授权': return 'info';
              case '代理异常': return 'warning';
              default: return 'info';
            }
        },
        getAccountDesc(row) {
            let desc = row.account_status_desc;
            if (row.account_status === '正常' && row.loginnum) {
              desc +=  ' [' +row.loginnum+ ']';
            }
            return desc;
          },
        // 获取过滤器数据
        async fetchTagFilters() {
          try {
            const res = await axios.get(base_url + '/Mtuser/accountStatusDesc', {
                      params: { archive: 1 }  // 这里传查询参数
                    }); 
            this.tagFilters = res.data.data.map(item => ({
              text: `${item.account_status_desc} (${item.count})`,
              value: item.account_status_desc
            }));
          } catch (error) {
            console.error('获取过滤器失败', error);
          }
        },

    
        // 过滤方法
        filterTag(value, row) {
          //return true;    
          return row.account_status_desc === value;
        },
        handleChange(filters){
             console.log('过滤条件改变:', filters);
            console.log('tag过滤值:', filters.tag);
            const value = filters.tag ? filters.tag[0] : '';
            console.log(value);
            this.searchData.account_status_desc = value;
        
            this.page_data.page = 1;
            this.index(); // 请求后端
        },
        handleFilterChange(value){
            this.searchData.account_status_desc = value;
            this.page_data.page = 1;
            this.index();
            this.fetchTagFilters();
        },

		add(){
			this.dialog.addDialogStatus = true
		},
		upnick(row){
		    
			this.dialog.upnickDialogStatus = true
			this.upnickInfo = {id:row.id ? row.id : this.ids.join(',')}
		},	
		update(row){
			let id = row.id ? row.id : this.ids.join(',')
			axios.post(base_url + '/Mtuser/getUpdateInfo',{id:id}).then(res => {
				if(res.data.status == 200){
					this.dialog.updateDialogStatus = true
					this.updateInfo = res.data.data
				}else{
					this.$message.error(res.data.msg)
				}
			})
		},
		transfer(row){
			this.dialog.transferDialogStatus = true
			this.transferInfo = {id:row.id ? row.id : this.ids.join(',')}			
		},
		allocateCustom(row){
			
			this.dialog.allocatecustomDialogStatus = true
			this.allocatecustomInfo = {id:row.id ? row.id : this.ids.join(',')}		
			console.log(this.allocatecustomInfo);
		},
		//分配客服
		delCustom(row){
			this.$confirm('确定操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				let ids = row.id ? String(row.id) : this.ids.join(',')
				axios.post(base_url + '/Mtuser/delCustom',{id:ids}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						// 更新缓存
						//this.updateUserArchiveCache(ids.split(','));
						this.index()
					}else{
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
					}
				})
			}).catch(() => {})
		},

        delweb(row){
			this.$confirm('确定清理web操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
			//	let ids = row.id ? row.id : this.ids.join(',')
				let id = row.id
				axios.post(base_url + '/Mtuser/delweb',{id:id}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
						// 更新缓存
						//this.updateUserArchiveCache(ids.split(','));
					}else{
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
					}
				})
			}).catch(() => {})
		},
		del(row){
			this.$confirm('确定操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
			//	let ids = row.id ? row.id : this.ids.join(',')
				let ids = row.id ? String(row.id) : this.ids.join(',')
				axios.post(base_url + '/Mtarchive/delete',{id:ids}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
						// 更新缓存
						//this.updateUserArchiveCache(ids.split(','));
					}else{
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
					}
				})
			}).catch(() => {})
		},
		detail(row){
			this.dialog.detailDialogStatus = true
			this.detailInfo = {id:row.id ? row.id : this.ids.join(',')}
		},
        

		archive(row){
			this.$confirm('确定操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				let ids = row.id ? row.id : this.ids.join(',')
				axios.post(base_url + '/Mtuser/archive',{id:ids}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						this.index()
						// 更新缓存
						//this.updateUserArchiveCache(ids.split(','));
					}else{
						this.$message.error(res.data.msg)
					}
				})
			}).catch(() => {})
		},
		

		accountup(row){
			this.$confirm('确定操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				let ids = row.id ? row.id : this.ids.join(',')
				console.log(ids);
				axios.post(base_url + '/Mtuser/accountup',{id:ids}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						// 开始跟踪进度
						this.index()
						if(res.data.total>0){
			                this.startProgressTracking(res.data.batch_id, res.data.total)
						}
					}else{
						this.$message.error(res.data.msg)
					}
				})
			}).catch(() => {})
		},
		accountdown(row){
			this.$confirm('确定操作吗?', '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				let ids = row.id ? row.id : this.ids.join(',')
				axios.post(base_url + '/Mtuser/accountdown',{id:ids}).then(res => {
					if(res.data.status == 200){
						this.$message({message: res.data.msg, type: 'success'})
						// 开始跟踪进度
						this.index()
						if(res.data.total>0){
			                this.startProgressTracking(res.data.batch_id, res.data.total)
						}
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
			this.chooseNum=selection.length;
		},
		handleRowClick(row, rowIndex,event){
			if(event.target.className !== 'el-input__inner'){
				this.$refs.multipleTable.toggleRowSelection(row)
			}
		},
		rowClass ({ row, rowIndex }) {
			for(let i=0;i<this.ids.length;i++) {
				if (row.id === this.ids[i]) {
					return 'rowLight'
				}
			}
		},
		fn(method){
			this[method](this.ids)
		},
		batchOperate(action) {
			if (this.ids.length === 0) {
				this.$message.warning('请先选择需要操作的账号');
				return;
			}
			const actionTexts = {
				'allocate': '分配Sockts代理',
				'delete': '删除Sockts代理',
				'exitGroups': '一键退出群聊',
				'checkAccount': '检测账号',
				
			};
			
			const actionText = actionTexts[action] || action;
			this.$confirm(`确定要执行 ${actionText} 操作吗?`, '提示', {
				confirmButtonText: '确定',
				cancelButtonText: '取消',
				type: 'warning'
			}).then(() => {
				// 根据不同的操作类型执行不同的逻辑
				switch (action) {
					case 'allocate':
						// 分配逻辑
						this.dialog.allocatesocktsDialogStatus = true
						this.allocatesocktsInfo =  {id: this.ids.join(',')}	
						console.log(this.allocatesocktsInfo)
						break;
						
					case 'deletesockets':
						// 删除逻辑
						axios.post(base_url + '/Mtuser/deleteSockets', {id: this.ids.join(',')})
							.then(res => {
								if (res.data.status == 200) {
									this.$message({message: res.data.msg, type: 'success'});
									this.index();
								} else {
									this.$message.error(res.data.msg);
								}
							});
						break;

						
					case 'exitGroups':
						// 退出群聊逻辑
						axios.post(base_url + '/Mtuser/exitGroups', {id: this.ids.join(',')})
							.then(res => {
								if (res.data.status == 200) {
									this.$message({message: res.data.msg, type: 'success'});
									// 开始跟踪进度
						            if(res.data.total>0){
            			                this.startProgressTracking(res.data.batch_id, res.data.total)
            						}
								} else {
									this.$message.error(res.data.msg);
								}
							});
						break;
						
					case 'checkAccount':
						// 检测账号逻辑
						axios.post(base_url + '/Mtuser/checkAccount', {id: this.ids.join(',')})
							.then(res => {
								if (res.data.status == 200) {
									this.$message({message: res.data.msg, type: 'success'});
									// 开始跟踪进度
						            if(res.data.total>0){
            			                this.startProgressTracking(res.data.batch_id, res.data.total)
            						}
								} else {
									this.$message.error(res.data.msg);
								}
							});
						break;
						
				
						
					default:
						this.$message.warning('未知操作类型');
				}
			}).catch(() => {});
		},
		// 新增：开始跟踪任务进度
		startProgressTracking(batchId, total) {
			// 清除之前的定时器
			if (this.progressInterval) {
				clearInterval(this.progressInterval);
			}
			
			// 初始化进度数据
			this.currentBatchId = batchId;
			this.showProgress = true;
			this.progressData = {
				total: total,
				completed: 0,
				success: 0,
				failed: 0,
				status: 'undefined',
				percentage: 0
			};
			
			// 立即查询一次进度
			this.getTaskProgress();
			
			// 设置定时器，每2秒查询一次进度
			this.progressInterval = setInterval(() => {
				this.getTaskProgress();
			}, 2000);
		},
		// 新增：获取任务进度
		getTaskProgress() {
			if (!this.currentBatchId) return;
			
			axios.post(base_url + '/Mtuser/getTaskProgress', {batch_id: this.currentBatchId})
				.then(res => {
					if (res.data.status == 200) {
						const data = res.data.data;
						this.progressData = {
							...data,
							percentage: data.total > 0 ? Math.round((data.completed / data.total) * 100) : 0
						};
						// 新增：实时更新列表中的账号状态
                        if (data.updated_accounts && data.updated_accounts.length > 0) {
                            this.updateAccountStatusInList(data.updated_accounts);
                        }
						// 如果任务已完成，清除定时器
						if (data.status === 'completed') {
							clearInterval(this.progressInterval);
							this.progressInterval = null;
							// 延迟3秒后隐藏进度条（可选）
							setTimeout(() => {
								this.showProgress = false;
								// 刷新列表显示最新状态
								this.index();
							
							}, 2000);
						}
					} else {
						console.error('获取进度失败:', res.data.msg);
						// 如果任务不存在或已过期，清除定时器
						clearInterval(this.progressInterval);
						this.progressInterval = null;
						this.showProgress = false;
					}
				})
				.catch(err => {
					console.error('获取进度出错:', err);
					clearInterval(this.progressInterval);
					this.progressInterval = null;
					this.showProgress = false;
				});
		},
		// 新增：实时更新列表中的账号状态
        updateAccountStatusInList(updatedAccounts) {
            updatedAccounts.forEach(updatedAccount => {
                const index = this.list.findIndex(item => item.id === updatedAccount.id);
                if (index !== -1) {
                    // 使用 Vue.set 确保响应式更新
                    this.$set(this.list[index], 'account_status', updatedAccount.account_status);
                    this.$set(this.list[index], 'account_status_desc', updatedAccount.account_status_desc);
                }
            });
             // 更新筛选器数据（基于当前列表）
            this.updateTagFiltersFromList();
        },
        // 新增：从列表数据更新筛选器
        updateTagFiltersFromList() {
            const statusMap = {};
            
            this.list.forEach(item => {
                const desc = item.account_status_desc || '未知';
                if (!statusMap[desc]) {
                    statusMap[desc] = 0;
                }
                statusMap[desc]++;
            });
            
            this.tagFilters = Object.keys(statusMap).map(desc => ({
                text: `${desc} (${statusMap[desc]})`,
                value: desc
            })).sort((a, b) => b.text.localeCompare(a.text));
        },
        /**
		 * 更新用户归档缓存
		 * @param {Array} userIds - 用户ID数组
		 */
		updateUserArchiveCache(userIds) {
			if (!Array.isArray(userIds) || userIds.length === 0) {
				return;
			}
			
			// 调用后端API更新缓存
			axios.post(base_url + '/Mtuser/updateUserArchiveCache', {
				userIds: userIds
			}).then(res => {
				if (res.data.status !== 200) {
					console.warn('更新缓存失败:', res.data.msg);
				}
			}).catch(err => {
				console.error('更新缓存出错:', err);
			});
		},
	
	    scrollToTop() {
          window.scrollTo({
            top: 0,
            behavior: 'smooth'
          });
        },
        toggleBackToTop() {
          const backToTopBtn = document.getElementById('backToTop');
          backToTopBtn.style.display = window.scrollY > 200 ? 'block' : 'none';
        }
	},
	mounted(){
		this.index()
		window.addEventListener('scroll', this.toggleBackToTop);
        this.$once('hook:beforeDestroy', () => {
          window.removeEventListener('scroll', this.toggleBackToTop);
        });
	},
	// 页面销毁时清除定时器
	beforeDestroy() {
		if (this.progressInterval) {
			clearInterval(this.progressInterval);
		}
	}
})
</script>

</body>
</html>