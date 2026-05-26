<?php /*a:2:{s:67:"/www/wwwroot/tdata.tgbota.top/app/admin/view/mttask/updatetask.html";i:1765540614;s:66:"/www/wwwroot/tdata.tgbota.top/app/admin/view/common/container.html";i:1729069940;}*/ ?>
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
<el-card shadow="never" class="card" style="min-height:650px;">
        
        <el-row :gutter="10">
          <el-col :span="16"> 
            <el-form size="mini" ref="form" :model="form" label-width="80px" :rules="rules">
              <el-row>
                <el-col :span="24">
                  <el-form-item label="任务名字" prop="title">
                    <el-input v-model="form.title" autoComplete="off" clearable placeholder="请输入任务名字"></el-input>
                  </el-form-item>
                </el-col>                
              </el-row>

              <!-- 群剧本话术类型字段 -->
              <el-row>
                <el-col>
                  <el-form-item label="账号组" prop="account_group">
                    <div v-if="accountGroupComponentVisible">                   
                    <select-page :is_clear="is_clear" url="/Mtcate/getRobot_id" :selectval.sync="form.account_group" @update:selectval="fetchAccountsByGroup" ></select-page>
                    </div>                   
                  </el-form-item>
                </el-col>
                </el-row>  
              <el-row>
                <el-col>
                  <el-form-item label="群列表" prop="group_list">
                    <el-input v-model="form.group_list" placeholder="请输入群列表，多个群用逗号分隔"></el-input>
                  </el-form-item>
                </el-col>
              </el-row>
              <el-row>
                <el-col>
                  <el-form-item label="同时执行" prop="concurrent">
                    <el-input v-model.number="form.concurrent" type="number" placeholder="请输入同时执行数"></el-input>
                  </el-form-item>
                </el-col>
              </el-row>
              <el-row>
                <el-col>
                  <el-form-item label="循环次数" prop="xhnum">
                    <el-input v-model.number="form.xhnum" min="1" type="number" placeholder="请输入循环次数"></el-input>
                  </el-form-item>
                </el-col>
              </el-row> 
              <!-- 消息发送设置 -->
              <div class="el-row" v-for="(message, index) in displayedMessages" :key="index">
                <el-form-item :label="'发送' + (index + 1 + (currentPage-1)*pageSize)" style="width: 100%;">
                  <el-row :gutter="10">
                    <el-col :span="4">
                      <el-select v-model="message.sendUser" placeholder="请选择账号" style="width: 100%;">
                        <el-option v-for="(item, idx) in accountList" :key="idx" :label="item.nickName" :value="item.id"></el-option>
                      </el-select>
                    </el-col>
                    <el-col :span="3">
                      <el-select v-model="message.feedbackType" placeholder="回复" style="width: 100%;" :disabled="index == 0&&currentPage==1"  @visible-change="onFeedbackTypeChange(message, index + (currentPage-1)*pageSize)">
                        <el-option label="不回复" value="none"></el-option>
                        <el-option
                            v-for="k in cindex"
                            :label="'回复' + k"
                            :value="k"
                        ></el-option>
                      </el-select>
                    </el-col>
                    <el-col :span="3">
                      <el-select v-model="message.sendType" placeholder="请选择类型" style="width: 100%;" @change="onSendTypeChange(message, index)">
                        <el-option label="不发消息" value="none"></el-option>
                        <el-option label="文本" value="text"></el-option>
                        <el-option label="图片" value="image"></el-option>
                        <el-option label="图片+文本" value="image_text"></el-option>                        
                      </el-select>
                    </el-col>
                    <el-col :span="8"> 
                          <!-- 根据发送类型显示不同的内容输入 -->
                      <div v-if="message.sendType !== 'none'" class="mt-2">
                        <el-row :gutter="10">
                          <!-- 文本输入 -->
                          <el-col :span="24" v-if="message.sendType === 'text' || message.sendType === 'image_text'">
                            <el-form-item :prop="'messages.' + (index + (currentPage-1)*pageSize) + '.text'" :rules="{required: message.sendType === 'text' || message.sendType === 'image_text', message: '请输入文本内容', trigger: 'blur'}">
                              <el-input v-model="message.text" type="textarea" :rows="1" placeholder="请输入文本内容"></el-input>
                            </el-form-item>
                          </el-col>
                          
                          <!-- 图片上传 -->
                          <el-col :span="24" v-if="message.sendType === 'image' || message.sendType === 'image_text'">
                            <el-form-item :prop="'messages.' + (index + (currentPage-1)*pageSize) + '.images'" :rules="{required: message.sendType === 'image' || message.sendType === 'image_text', message: '请上传图片', trigger: 'change'}">
                              <Upload  size="mini"  file_type="images" upload_type = '2' :images.sync="message.images"></Upload>                             
                            </el-form-item>                
                          </el-col>
                          
                        </el-row>
                      </div>
                    </el-col>
                    <el-col :span="3">
                      <el-input v-model.number="message.delay" type="number" placeholder="延时(秒)" min="1" max="60" 
                              style="width: 100%;"></el-input>
                    </el-col>
                    <el-col :span="2">
                      <el-button type="danger" @click="deleteMessage(index + (currentPage-1)*pageSize)" size="mini" v-if="index + (currentPage-1)*pageSize > 0">
                        <i class="el-icon-delete"></i> 删除
                      </el-button>
                    </el-col>
                  </el-row>
                </el-form-item>
              </div>

              <!-- 数据导入导出 -->
              <el-row>                
                  <el-upload 
                    :action="importUrl"
                    accept=".txt" 
                    list-type="text"
                    :on-success="handleImportSuccess"
                    :before-upload="beforeImport"
                    style="display: inline-block; margin-right: 5px;">
                    <el-button type="success" size="small">
                      <i class="el-icon-upload2"></i> 导入
                    </el-button>
                  </el-upload>
               
                  <el-button type="info" size="small" @click="exportData">
                    <i class="el-icon-download"></i> 导出
                  </el-button>
                  <el-button type="primary" size="small" @click="addMessage">
                    <i class="el-icon-plus"></i> 新增
                  </el-button>
               
              </el-row>

              <!-- 分页组件 - 根据消息数量进行分页 -->
              <div class="el-row justify-end">
                <el-col :span="24">
                  <el-pagination
                    v-model:current-page="currentPage"
                    v-model:page-size="pageSize"
                    :page-sizes="[5, 10, 20,50,100,200]"
                    layout="total, sizes, prev, pager, next, jumper"
                    :total="form.messages.length"
                    @size-change="handleSizeChange"
                    @current-change="handleCurrentChange"
                  >
                    <template #extra>
                      共 {{ form.messages.length }} 条发送设置
                    </template>
                  </el-pagination>
                </el-col>
              </div>
              
              <el-row>
                <div style="padding-top: 10px; text-align: center;">
                  <el-button type="danger" size="mini" icon="el-icon-refresh-left"  @click="back">退出</el-button>
                  <el-button type="danger" size="mini"  icon="el-icon-delete"  @click="resetForm">清理</el-button>
                  <el-button type="primary" size="mini"  icon="el-icon-arrow-right"  @click="submit">确定</el-button>
                </div>
              </el-row>
            </el-form>
          </el-col>
          
          <el-col :span="8"> 
            <div style="padding-top: 40px; padding-left: 20px; font-size: 13px;">
              <el-divider><i class="el-icon-info"></i> 延时</el-divider>
              <span style="color: rgb(144, 147, 153); line-height: 20px;">
                <p>收到消息后等待多久回复本条消息</p>
              </span>
              <el-divider><i class="el-icon-info"></i> 同时执行</el-divider>
              <span style="color: rgb(144, 147, 153); line-height: 20px;">
                <p>执行线程数即同时执行的账号数</p>
              </span>
              <el-divider><i class="el-icon-info"></i> 描述</el-divider>
              <span style="color: rgb(144, 147, 153); line-height: 20px;">
<div>1.支持私有群和公开群<br>2.支持多个群同时进行同一个剧本<br>3.支持指定账号发送内容<br>4.多任务间的同一个账号可互相关联<br>5.封号自动补充账号<br>6.停止任务后重新启动会按进度继续任务<br>7.支持回复指定消息<br>8.支持点赞指定消息<br></div>
              </span>
              
            </div>
          </el-col>
        </el-row>   
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
<script src="/assets/libs/vuedragable/Sortable.min.js"></script>
<script src="/assets/libs/vuedragable/vuedraggable.umd.min.js"></script>
<script>
    new Vue({
      el: '#app',
      components:{
        'draggable':window.vuedraggable,
      },
      data() {
        return {
          active: 0,
          accountGroupComponentVisible: true,
          form: {
            xhnum:1,  
            title: '',         
            concurrent: 1000,            
            account_group: '',
            group_list: '',
            messages: [
              { 
                sendType: 'text', 
                delay: 1, 
                text: '', 
                images: [],                
                feedbackType: 'none',                
              }
            ]
          },
          cindex:0,
          currentPage: 1,
          pageSize: 10,
          totalItems: 100,
          rules: {
            title: [
              { required: true, message: '请输入任务名字', trigger: 'blur' }
            ],
            account_group: [
              { required: true, message: '请选择账号组', trigger: 'blur' }
            ],
            concurrent: [
              { required: true, message: '请输入同时执行数', trigger: 'blur' },
              { type: 'number', message: '同时执行数必须为数字值', trigger: 'blur' }
            ]
          },
          accountList: [],
          uploadUrl: base_url + '/Upload/Upload',
          importUrl: base_url +'/Mttask/import',
          exportUrl: '/export',
          taskId: 0, // 新增：任务ID
          is_clear:false,
        }
      },
      computed: {
        // 当前页显示的消息
        displayedMessages() {
          if (!this.form || !this.form.messages) {
            return [];
          }
          
          const start = (this.currentPage - 1) * this.pageSize;
          const end = start + this.pageSize;
          return this.form.messages.slice(start, end);
        }
      },
      mounted() {
        // 获取URL中的任务ID
        const urlParams = new URLSearchParams(window.location.search);
        this.taskId = urlParams.get('id');
        
        if (this.taskId) {
            // 加载任务数据
            this.loadTaskData();
           
        }
      },
      
      methods: {
         // 新增刷新组件方法
        refreshAccountGroupComponent() {
          this.accountGroupComponentVisible = false;
          this.$nextTick(() => {
            this.accountGroupComponentVisible = true;
          });
        },
        // 从服务器获取任务数据
        loadTaskData() {
            // 显示加载状态
            const loading = this.$loading({
                lock: true,
                text: '加载任务数据中...',
                spinner: 'el-icon-loading',
                background: 'rgba(0, 0, 0, 0.1)'
            });
            
            // 发送请求获取任务数据
            axios.post(base_url + '/Mttask/getInfo', {id: this.taskId}).then(res => {
                loading.close();
                if (res.data.status == 200) {
                    this.form = res.data.data;
                    console.log(this.form.messages); 
                    this.setDefaultVal('messages');
                    // 确保messages字段存在
                    if (!this.form.messages) {
                        this.form.messages = [
                            { 
                                sendType: 'text', 
                                delay: 1, 
                                text: '', 
                                images: [],                                
                                feedbackType: 'none',                               
                            }
                        ];
                    }
                    // ---------- 关键：打印每个message.images ----------
                      // 遍历 messages，转换 images 格式
                      this.form.messages.forEach(message => {
                        // 如果 images 是字符串数组，转为 { url: ... } 格式的对象数组
                        if (Array.isArray(message.images)) {
                          message.images = message.images.map(imgPath => ({
                            url: imgPath  // 将字符串路径赋值给 url 属性
                          }));
                        } else {
                          // 确保 images 始终是数组
                          message.images = [];
                        }
                      });
                    // 如果账号组已选择，获取账号列表
                    if (this.form.account_group) {
                      
                          this.form.account_group = res.data.data.account_group;
                          this.fetchAccountsByGroup(this.form.account_group);                          
                          // 刷新组件
                          this.refreshAccountGroupComponent();                     
                       
                    }
                   
                } else {
                    this.$message.error(res.data.message || '获取任务数据失败');
                    // 失败后返回列表页
                    setTimeout(() => {
                        window.location.href = base_url + '/Mttask/index';
                    }, 1000);
                }
            }).catch(err => {
                loading.close();
                this.$message.error('网络错误，请稍后再试');
                console.error(err);
                // 失败后返回列表页
                setTimeout(() => {
                    window.location.href = base_url + '/Mttask/index';
                }, 1000);
            });
        },
        onFeedbackTypeChange(message, currentIndex) {
         this.cindex=currentIndex;
        },
        setDefaultVal(key){
          if (key === 'messages' && Array.isArray(this.form[key])) {
            this.form[key].forEach(item => {
              // 确保每个 message 的 images 是数组（存储图片URL对象）
              if (item.images == null || item.images === '') {
                item.images = [];
              } else if (!Array.isArray(item.images)) {
                // 若后端返回的是字符串（单图URL），转为数组格式统一处理
                item.images = [item.images];
              }
            });
          }
        },
        // 根据账号组获取账号列表
        fetchAccountsByGroup(value) {
          console.log('选中的账号组ID:', value);
          this.form.account_group = value; // 手动更新表单值
          if (!this.form.account_group) {
            this.accountList = [];
            return;
          }
          
          // 显示加载状态
          const loading = this.$loading({
            lock: true,
            text: '加载账号中...',
            spinner: 'el-icon-loading',
            background: 'rgba(0, 0, 0, 0.1)'
          });
          
          // 发送请求获取账号列表
          axios.post(base_url + '/Mttask/getByGroup', {
            group_id: this.form.account_group
          }).then(res => {
           
            loading.close();
            if (res.data.status == 200) {
              console.log(res.data.data);
              this.accountList = res.data.data || [];
              this.reassignMessagesToNewGroup();
            } else {
              this.$message.error(res.data.message || '获取账号列表失败');
              this.accountList = [];
            }
          }).catch(err => {
            loading.close();
            this.$message.error('网络错误，请稍后再试');
            console.error(err);
            this.accountList = [];
          });
        },
        // 将现有消息重新分配到新账号组
        reassignMessagesToNewGroup() {
          if (this.accountList.length === 0) return;
          
          // 遍历所有消息，重新分配账号
          this.form.messages = this.form.messages.map(msg => {
            // 生成新的随机索引
            //const randomIndex = Math.floor(Math.random() * this.accountList.length);
            
             // 根据index设置账号
            let sendUser = '';
            if (msg.index !== undefined && this.accountList.length > 0) {
              // 使用index对账号列表长度取模来分配账号
              const accountIndex = msg.index % this.accountList.length;
              sendUser = this.accountList[accountIndex].id;
            } else if (this.accountList.length > 0) {
              // 如果没有index，使用随机分配作为备选
              const randomIndex = Math.floor(Math.random() * this.accountList.length);
              sendUser = this.accountList[randomIndex].id;
            }
            
            return {
              ...msg,
              // 替换为新账号组中的随机账号
              sendUser: sendUser
            };
          });
          
          // 提示用户账号已重新分配
          this.$message.info(`账号组已切换，已为 ${this.form.messages.length} 条消息重新分配账号`);
        },
        
        // 当发送类型改变时的处理
        onSendTypeChange(message, index) {
            // 根据新的类型重置相关字段
            if (message.sendType !== 'image' && message.sendType !== 'image_text') {
                message.images = [];
            }
           
            if (message.sendType !== 'forward') {
                message.forwardId = '';
            }
            if (message.sendType !== 'text' && message.sendType !== 'image_text') {
                message.text = '';
            }
        },
        
        // 新增消息发送设置
        addMessage() {
            this.form.messages.push({
                sendType: 'text',
                delay: 1,
                text: '',
                images: [],               
                feedbackType: 'none',
            });
            
            // 如果新增后当前页显示不下所有消息，自动切换到最后一页
            const totalPages = Math.ceil(this.form.messages.length / this.pageSize);
            if (this.currentPage < totalPages) {
                this.currentPage = totalPages;
            }
        },
        
        // 删除消息发送设置
        deleteMessage(index) {
            if (this.form.messages.length > 1) {
                this.form.messages.splice(index, 1);
                
                // 如果删除后当前页没有消息了，自动切换到上一页
                const totalPages = Math.max(1, Math.ceil(this.form.messages.length / this.pageSize));
                if (this.currentPage > totalPages) {
                    this.currentPage = totalPages;
                }
            } else {
                this.$message.warning('至少保留一个发送设置');
            }
        },
        
        
        // 导入导出相关方法
        beforeImport(file) {
          if (!this.form.account_group) {
            this.$message.error('请先选择账号组！');
            return false; // 阻止导入
          }  
          const isTxt = file.type === 'text/plain';
          console.log(file.type);
          if (!isTxt) {
            this.$message.error('请上传txt格式文件!');
          }
          return isTxt;
        },
        handleImportSuccess(response, file, fileList) {
            if (response.status === 200) {
              const rawData = response.data || {};
              const importedMessages = Array.isArray(rawData) 
                ? rawData 
                : (rawData.data || []);
              
              if (importedMessages.length === 0) {
                this.$message.warning('导入的文件中无有效消息数据');
                return;
              }
              
              let newMessages = [];
              let validCount = 0;
              
              // 处理导入的数据
              importedMessages.forEach((msg, idx) => {
                // 判断消息类型（兼容旧格式）
                let msgType = msg.sendType || 'text';
                if (msg.type !== undefined) {
                  msgType = msg.type === 2 ? 'image' : 
                           msg.type === 1 ? 'text' : 
                           msg.type === 3 ? 'image_text' : 'text';
                }
                
                // 判断延迟时间
                let delay = msg.delay || 1;
                if (msg.wait !== undefined) {
                  delay = parseInt(msg.wait) || 1;
                }
                
                // 处理文本内容
                let text = msg.text || '';
                if (msg.content !== undefined) {
                  text = msg.content;
                }
                
                // 处理图片
                let images = msg.images || [];
                if (msg.file && !images.length) {
                  images = [{ url: msg.file }];
                }
                
                // 根据index设置账号
                let sendUser = '';
                if (msg.index !== undefined && this.accountList.length > 0) {
                  // 使用index对账号列表长度取模来分配账号
                  const accountIndex = msg.index % this.accountList.length;
                  sendUser = this.accountList[accountIndex].id;
                } else if (this.accountList.length > 0) {
                  // 如果没有index，使用随机分配作为备选
                  const randomIndex = Math.floor(Math.random() * this.accountList.length);
                  sendUser = this.accountList[randomIndex].id;
                }
                
                const newMsg = {
                  sendType: msgType,
                  delay: delay,
                  text: text,
                  images: images,
                  feedbackType: msg.reply || 'none',
                  index: msg.index || idx,
                  replyTo: msg.reply || '',
                  sendUser: sendUser
                };
                
                newMessages.push(newMsg);
                validCount++;
              });
              
              // 根据导入模式处理消息
              
              this.form.messages = newMessages;
              this.$message.success(`覆盖导入成功，共导入 ${validCount} 条消息`);
              this.currentPage = 1;
              
            } else {
              this.$message.error('导入失败: ' + (response.message || '未知错误'));
            }
          },
          
          // 修改导出方法，导出与导入一致的格式
          exportData() {
            const exportMessages = this.form.messages.map((msg, index) => {
              // 导出为通用格式，可以重新导入
              let type = 1; // 默认文本
              if (msg.sendType === 'image') type = 2;
              else if (msg.sendType === 'image_text') type = 3;
              
              return {
                // 兼容字段
                wait: msg.delay || 1,
                type: type,
                content: msg.text || '',
                file: (msg.images && msg.images.length > 0) ? msg.images[0].url : '',
                index: msg.index,
                reply: msg.feedbackType || ''
              };
            });
            
            const blob = new Blob([JSON.stringify(exportMessages, null, 2)], {type: 'text/plain'});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `task_${this.form.title || 'messages'}_${new Date().getTime()}.txt`;
            link.click();
            URL.revokeObjectURL(url);
         },
        
        // 提交表单
        submit() {
            this.$refs['form'].validate(valid => {
                if(valid) {
                    // 显示加载状态
                    const loading = this.$loading({
                        lock: true,
                        text: '提交中...',
                        spinner: 'el-icon-loading',
                        background: 'rgba(0, 0, 0, 0.1)'
                    });
                    
                    // 准备表单数据
                    const formData = {
                        ...this.form,
                        id: this.taskId, // 确保ID被传递
                        // 处理messages中的文件数据，只保留服务器返回的URL
                        messages: this.form.messages.map(msg => {
                            return {
                                ...msg,
                                images: msg.images.map(img => img.url || ''),                                
                            }
                        })
                    };
                    
                    // 提交数据到服务器
                    axios.post(base_url + '/Mttask/save', formData).then(res => {
                        loading.close();
                        if(res.data.status == 200){
                            this.$message({message: '提交成功', type: 'success'});
                            // 提交成功后可以跳转到列表页或做其他操作
                            setTimeout(() => {
                                window.location.href = base_url + '/Mttask/index';
                            }, 1000);
                        } else {
                            this.$message.error(res.data.message || '提交失败');
                        }
                    }).catch(err => {
                        loading.close();
                        this.$message.error('网络错误，请稍后再试');
                        console.error(err);
                    });
                } else {
                    this.$message.error('表单验证失败，请检查输入');
                    return false;
                }
            });
        },
        
        // 返回
        back() {
            // 退出操作逻辑
            if (confirm('确定要退出吗？未保存的数据将会丢失。')) {
                window.history.back();
            }
        },
        
        // 重置表单
        resetForm() {
            if (confirm('确定要清理所有数据吗？')) {
                // 重新加载任务数据
                if (this.taskId) {
                    this.loadTaskData();
                } else {
                    // 如果没有任务ID，重置为默认值
                    this.form = {
                        title: '',          
                        concurrent: 1000,                    
                        account_group: 0,
                        group_list: '',
                        xhnum:1,
                        messages: [
                            { 
                                sendType: 'text', 
                                delay: 1, 
                                text: '', 
                                images: [],                                
                            }
                        ]
                    };
                }
                
                this.currentPage = 1;
                this.$refs['form'].resetFields();
            }
        },
        
        // 分页相关方法
        handleSizeChange(val) {
            console.log(`每页 ${val} 条`);
            this.pageSize = val;
        },
        
        handleCurrentChange(val) {
            console.log(`当前页: ${val}`);
            this.currentPage = val;
        }
      }
    });
  </script>

</body>
</html>