<?php /*a:2:{s:65:"/www/wwwroot/tdata.tgbota.top/app/admin/view/mtarchive/index.html";i:1770017635;s:66:"/www/wwwroot/tdata.tgbota.top/app/admin/view/common/container.html";i:1729069940;}*/ ?>
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
      <el-form-item label="账号昵称">
        <el-input id="nickName" v-model="searchData.nickName"  style="width:150px;" placeholder="账号昵称"></el-input>
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
      <el-form-item label="状态">
        <el-select style="width:150px" v-model="searchData.status" filterable clearable placeholder="请选择">
          <el-option key="0" label="开启" value="1"></el-option>
          <el-option key="1" label="关闭" value="0"></el-option>
        </el-select>
      </el-form-item>
      
      <el-form-item label="账号状态">
        <el-select style="width:150px" v-model="searchData.account_status" filterable clearable placeholder="请选择">
          <el-option key="0" label="正常" value="正常"></el-option>
          <el-option key="1" label="封号" value="封号"></el-option>
          <el-option key="2" label="代理异常" value="代理异常"></el-option>
          <el-option key="3" label="异常" value="异常"></el-option>
          <el-option key="4" label="空号" value="空号"></el-option>
          <el-option key="5" label="注销" value="注销"></el-option>
          <el-option key="6" label="冻结" value="冻结"></el-option>
          <el-option key="7" label="未授权" value="未授权"></el-option>
        </el-select>
      </el-form-item>
      <el-form-item label="账号描述">
        <el-select
          v-model="searchData.account_status_desc"
          filterable
          clearable
          placeholder="请选择"
          @change="handleFilterChange"
          style="width:150px;">
          <el-option
            v-for="item in tagFilters"
            :key="item.value"
            :label="item.text"
            :value="item.value">
          </el-option>
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
  
  <!-- 任务进度展示区域 -->
  <el-progress 
    v-if="showProgress" 
    :percentage="progressData.percentage" 
    v-bind="progressData.completed === progressData.total ? { status: 'success' } : {}"
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

  <el-table 
    :row-class-name="rowClass" 
    @selection-change="selection"  
    @row-click="handleRowClick"  
    row-key="id"  
    :header-cell-style="{ background: '#eef1f6', color: '#606266' }" 
    v-loading="loading"  
    ref="multipleTable" 
    border 
    class="eltable" 
    :data="list"  
    height="65vh"
    style="width: 100%"
  >
    <el-table-column align="center" type="selection" width="42"></el-table-column>

    <el-table-column align="center"  property="avatar_url" label="头像" show-overflow-tooltip width="100">
      <template slot-scope="scope">
        <div class="demo-image__preview">
          <el-image class="table_list_pic" :src="scope.row.avatar_url || 'https://s160.avatar.talk.zdn.vn/default'"  :preview-src-list="[scope.row.avatar_url || 'https://s160.avatar.talk.zdn.vn/default']"></el-image>
        </div>
      </template>
    </el-table-column>

    <el-table-column align="center"  property="region" label="国家" show-overflow-tooltip width="100"></el-table-column>
    <el-table-column align="center"  property="account" label="手机" show-overflow-tooltip  width="170">
      <template slot-scope="scope">
        {{scope.row.account}} 
      </template>
    </el-table-column>
    <el-table-column align="center"  property="uuid" label="UserId" show-overflow-tooltip ></el-table-column>
    <el-table-column align="center"  property="username" label="用户名" show-overflow-tooltip ></el-table-column>
    <el-table-column align="center"  property="nickName" label="昵称" show-overflow-tooltip ></el-table-column>
    <el-table-column align="center"  property="friends_count" label="好友" sortable show-overflow-tooltip width="100"></el-table-column>
    <el-table-column align="center"  property="groups_count" label="群" show-overflow-tooltip width="100"></el-table-column>
    <el-table-column align="center"  property="Mtcate.class_name" label="账号组" show-overflow-tooltip ></el-table-column>

    <el-table-column align="center"  property="customer" label="客服" show-overflow-tooltip ></el-table-column>
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
      @filter-change="handleChange"
      filter-placement="bottom-end"
      align="center">
      <template slot-scope="scope">
        <el-tooltip
          :content="scope.row.account_status_desc"  
          placement="top"  
          effect="dark"   
        >
          <div style="width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ scope.row.account_status_desc }}
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
        {{parseTime(scope.row.addtime,'{y}-{m}-{d} {h}:{i}')}}
      </template>
    </el-table-column>
    <el-table-column :fixed="ismobile()?false:'right'" label="操作" align="center"  width="100">
      <template slot-scope="scope">
        <div v-if="scope.row.id">
          <el-button v-if="checkPermission('/admin/Mtarchive/delete.html','<?php echo implode(",",session("admin.access")); ?>','<?php echo session("admin.role_id"); ?>',[1])" size="mini" icon="el-icon-delete" type="danger" @click="del(scope.row)" >删除</el-button>
        </div>
      </template>
    </el-table-column>
  </el-table>
  <Page :total="page_data.total" :page.sync="page_data.page" :limit.sync="page_data.limit" @pagination="index" />
</el-card>

<!-- 返回顶部按钮 -->
<div id="backToTop" class="back-to-top" @click="scrollToTop">
  <el-button icon="el-icon-arrow-up" type="primary" size="mini" circle></el-button>
</div>
<!-- 转移 -->
<Transfer :info="transferInfo" :show.sync="dialog.transferDialogStatus" size="small" @refesh_list="index"></Transfer>
<!-- 导入账号 -->
<Accountup :info="accountupInfo" :show.sync="dialog.accountupDialogStatus" size="small" @refesh_list="index" @track-progress="startProgressTracking" ></Accountup>

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
<script src="/components/admin/mtuser/transfer.js?v=<?php echo rand(1000,9999)?>"></script>
<script src="/components/admin/mtarchive/accountup.js?v=<?php echo rand(1000,9999)?>"></script>
<script>
new Vue({
  el: '#app',
  components:{},
  data: function() {
    return {
      dialog: {
        addDialogStatus : false,
        updateDialogStatus : false,
        transferDialogStatus: false,
        accountupDialogStatus: false,
      },
      searchData:{},
      button_group:[
        {name:'导入',color:'primary',access:'/admin/Mtarchive/accountup.html',icon:'el-icon-top',disabled:'',clickname:'accountup'},  
        {name:'启用',color:'success',access:'/admin/Mtuser/archiveup.html',icon:'el-icon-position',disabled:'multiple',clickname:'archive'},
        {name:'转移分组',color:'warning',access:'/admin/Mtuser/transfer.html',icon:'el-icon-sort',disabled:'multiple',clickname:'transfer'},
        {name:'批量删除',color:'danger',access:'/admin/Mtarchive/delete.html',icon:'el-icon-delete',disabled:'multiple',clickname:'del'},
        {name:'Tdata文件导出',color:'primary',access:'/admin/Mtuser/exportTdataZip.html',icon:'el-icon-download',disabled:'multiple',clickname:'exportTdata'},
      ],
      loading: false,
      page_data: {
        limit: 20,
        page: 1,
        total: 0,
      },
      ids: [],
      single: true,
      multiple: true,
      search_visible: true,
      list: [],
      updateInfo: {},
      transferInfo: {},
      accountupInfo: {},
      exceldata: [],
      dumppage: 1,
      ws: {},
      dumpshow: false,
      percentage: 0,
      filename: '',
      is_clear: false,
      // 任务进度相关
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
      tagFilters: []      
    }
  },
  created() {
    this.fetchTagFilters();
  },
  methods:{
    // 优化：实现无感刷新，只更新变化的数据
    index() {
      let param = {
        limit: this.page_data.limit,
        page: this.page_data.page,
        account_status_desc: this.searchData.account_status_desc
      };
      Object.assign(param, this.searchData);
      this.loading = true;
      
      axios.post(base_url + '/Mtarchive/index', param).then(res => {
        if (res.data.status == 200) {
          const newList = res.data.data.data;
          const newTotal = res.data.data.total;
          
          // 更新总数（如果有变化）
          if (this.page_data.total !== newTotal) {
            this.page_data.total = newTotal;
          }
          
          // 局部更新列表数据
          this.updateListData(newList);
          
          this.loading = false;
        } else {
          this.$message.error(res.data.msg);
          this.loading = false;
        }
      }).catch(() => {
        this.loading = false;
      });
    },
    
    // 局部更新列表数据，只更新变化的项
    updateListData(newList) {
      // 首次加载或第一页数据直接替换
      if (this.page_data.page === 1 && this.list.length === 0) {
        this.list = newList;
        return;
      }
      
      // 遍历新数据，更新或添加项
      newList.forEach(newItem => {
        const oldItemIndex = this.list.findIndex(oldItem => oldItem.id === newItem.id);
        
        if (oldItemIndex > -1) {
          // 只更新有变化的数据
          if (!this.isObjectEqual(this.list[oldItemIndex], newItem)) {
            this.list.splice(oldItemIndex, 1, { ...newItem });
          }
        } else {
          // 添加新项
          this.list.push(newItem);
        }
      });
      
      // 移除已不存在的项
      this.list = this.list.filter(oldItem => 
        newList.some(newItem => newItem.id === oldItem.id)
      );
    },
    
    // 比较对象是否相等（只比较关键字段）
    isObjectEqual(oldObj, newObj) {
      const compareFields = [
        'status', 'account_status', 'account_status_desc', 
        'online', 'task_status', 'friends_count', 'groups_count'
      ];
      
      return compareFields.every(field => {
        return oldObj[field] === newObj[field];
      });
    },
    
    getTagType(status) {
      switch(status) {
        case '正常': return 'success';
        case '冻结': return 'danger';
        case '封号': return 'warning';
        case '异常': return 'info';
        case '空号': return 'warning';
        case '注销': return 'info';
        case '未授权': return 'info';
        case '代理异常': return 'warning';
        default: return 'info';
      }
    },

    // 获取过滤器数据
    async fetchTagFilters() {
      try {
        const res = await axios.get(base_url + '/Mtuser/accountStatusDesc', {
          params: { archive: 0 }
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
        
        const value = filters.tag ? filters.tag[0] : '';
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
    
    accountup(){
      this.dialog.accountupDialogStatus = true;
    },

    update(row){
      let id = row.id ? row.id : this.ids.join(',');
      axios.post(base_url + '/Mtuser/getUpdateInfo', {id: id}).then(res => {
        if(res.data.status == 200){
          this.dialog.updateDialogStatus = true;
          this.updateInfo = res.data.data;
        } else {
          this.$message.error(res.data.msg);
        }
      });
    },
    exportTdata() {
        if (this.ids.length === 0) {
            this.$message.warning('请先选择需要导出的账号');
            return;
        }
    
        this.$confirm(
            `确定导出选中的 ${this.ids.length} 个账号的 tdata 吗？`,
            '提示',
            {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                type: 'warning'
            }
        ).then(() => {
    
            this.$message({
                message: '正在打包，请稍候…',
                type: 'info',
                duration: 2000
            });
    
            // 直接下载（不走 axios，避免 blob 处理复杂）
            const url = base_url + '/Mtuser/exportTdataZip?id=' + this.ids.join(',');
            window.open(url);
    
        }).catch(() => {});
    },
    transfer(row){
      this.dialog.transferDialogStatus = true;
      this.transferInfo = {id: row.id ? row.id : this.ids.join(',')};
    },
    
    del(row){
      this.$confirm('确定要删除吗?', '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      }).then(() => {
        let ids = row.id ? row.id : this.ids.join(',');
        axios.post(base_url + '/Mtarchive/delete', {id: ids}).then(res => {
          if(res.data.status == 200){
            this.$message.success(res.data.msg);
            this.index();
          } else {
            this.$message.error(res.data.msg);
          }
        });
      }).catch(() => {});
    },

    archive(row){
      this.$confirm('确定要启用吗?', '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      }).then(() => {
        let ids = row.id ? row.id : this.ids.join(',');
        axios.post(base_url + '/Mtuser/archiveup', {id: ids}).then(res => {
          if(res.data.status == 200){
            this.$message.success(res.data.msg);
            this.index();
          } else {
            this.$message.error(res.data.msg);
          }
        });
      }).catch(() => {});
    },
    
    // 开始跟踪任务进度
    startProgressTracking(batchId, total) {
      // 清除之前的定时器
      if (this.progressInterval) {
        clearInterval(this.progressInterval);
      }
      
      // 初始化进度数据
      this.currentBatchId = batchId;
      this.progressData = {
        total: total,
        completed: 0,
        success: 0,
        failed: 0,
        status: 'processing',
        percentage: 0
      };
      
      // 立即查询一次进度
      this.getTaskProgress();
      
      if(total > 0) {
        this.showProgress = true;
        // 设置定时器，每3秒查询一次进度（降低频率减少请求）
        this.progressInterval = setInterval(() => {
          this.getTaskProgress();
        }, 3000);
      }
    },
    
    // 获取任务进度
    getTaskProgress() {
      if (!this.currentBatchId) return;
      
      axios.post(base_url + '/Mtuser/getTaskProgress', {batch_id: this.currentBatchId})
        .then(res => {
          if (res.data.status == 200) {
            const data = res.data.data;
            const newProgress = {
              ...data,
              percentage: data.total > 0 ? Math.round((data.completed / data.total) * 100) : 0
            };
            
            // 只有进度变化时才更新UI
            if (newProgress.percentage !== this.progressData.percentage) {
              this.progressData = newProgress;
              // 进度变化时才刷新列表，减少更新频率
              this.index();
            }
            
            // 任务完成时处理
            if (data.total === data.completed) {
              clearInterval(this.progressInterval);
              this.progressInterval = null;
              
              setTimeout(() => {
                this.showProgress = false;
                this.index(); // 最后刷新一次确保数据最新
              }, 3000);
            }
          } else {
            console.error('获取进度失败:', res.data.msg);
            this.cleanupProgressTracking();
          }
        })
        .catch(err => {
          console.error('获取进度出错:', err);
          this.cleanupProgressTracking();
        });
    },
    
    // 清理进度跟踪资源
    cleanupProgressTracking() {
      clearInterval(this.progressInterval);
      this.progressInterval = null;
      this.showProgress = false;
    },
    
    selection(selection) {
      this.ids = selection.map(item => item.id);
      this.single = selection.length !== 1;
      this.multiple = !selection.length;
    },
    
    handleRowClick(row, rowIndex, event) {
      if(event.target.className !== 'el-input__inner') {
        this.$refs.multipleTable.toggleRowSelection(row);
      }
    },
    
    rowClass ({ row, rowIndex }) {
      for(let i = 0; i < this.ids.length; i++) {
        if (row.id === this.ids[i]) {
          return 'rowLight';
        }
      }
    },
    
    fn(method) {
      this[method](this.ids);
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
  mounted() {
    this.index();
    window.addEventListener('scroll', this.toggleBackToTop);
    
    // 组件销毁前清理资源
    this.$once('hook:beforeDestroy', () => {
      window.removeEventListener('scroll', this.toggleBackToTop);
      if (this.progressInterval) {
        clearInterval(this.progressInterval);
      }
    });
  }
})
</script>

</body>
</html>