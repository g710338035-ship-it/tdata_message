Vue.component('Add', {
	template: `
		<el-dialog title="添加" width="800px" class="icon-dialog" :visible.sync="show" @open="open" :before-close="closeForm" append-to-body>
			<el-form :size="size" ref="form" :model="form" :rules="rules" :label-width=" ismobile()?'90px':'16%'">
				<el-row >
					<el-col :span="24">
						<el-form-item label="群标签" prop="name">
							<el-input  v-model="form.name" autoComplete="off" clearable  placeholder="请输入群标签名称"></el-input>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="选择协议号" prop="mp_id">
							<select-page v-if="show" url="/Monitorphone/getRobot_id" :selectval.sync="form.mp_id"></select-page>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="状态" prop="status">
							<el-switch :active-value="1" :inactive-value="0" v-model="form.status"></el-switch>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="描述" prop="description">
							<el-input  v-model="form.description" autoComplete="off" clearable  placeholder="请输入描述"></el-input>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row>
					<el-col :span="24">
						<el-form-item label="群组" prop="role_type" v-if="form.mp_id">
							<el-tree class="tree-border" :data="options"  show-checkbox ref="menu" node-key="access" :check-strictly="false" empty-text="暂无数据" :props="defaultProps"></el-tree>
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
	`
	,
	components:{
	},
	props: {
		show: {
			type: Boolean,
			default: false
		},
		size: {
			type: String,
			default: 'small'
		},
	},
	data(){
		return {
			form: {
				name:'',
				status:1,
				description:'',
				mp_id: null
			},
			defaultProps: {
				label: "group_name"
			},
			options:[],
			loading:false,
			base:base_url,
			rules: {
				name:[
					{required: true, message: '名称不能为空', trigger: 'blur'},
				],
				mp_id:[
					{required: true, message: '协议号不能为空', trigger: 'blur'},
				],
			}
		}
	},
	watch: {
		show(value) {
            if (value) {
                // 初始化时清空群组数据
                this.options = [];
            }
        },
        'form.mp_id': {
            handler(newValue) {
                if (newValue) {
                    this.loadGroupList(newValue);
                } else {
                    // 当协议号为空时，清空群组数据
                    this.options = [];
                }
            },
            immediate: false // 不需要在初始化时触发
        }
	},
	methods: {
		open(){
		},
		submit(){
			this.$refs['form'].validate(valid => {
				if(valid) {
					this.loading = true
					this.form.access = this.getMenuAllCheckedKeys()
					axios.post(base_url + '/Mpgrouptag/add',this.form).then(res => {
						if(res.data.status == 200){
							this.$message({message: res.data.msg, type: 'success'})
							this.$emit('refesh_list')
							this.closeForm()
						}else{
							this.$message.error(res.data.msg)
						}
					}).catch(()=>{
						this.loading = false
					})
				}
			})
		},
		getMenuAllCheckedKeys() {
			let checkedKeys = this.$refs.menu.getCheckedKeys()
			let halfCheckedKeys = this.$refs.menu.getHalfCheckedKeys()
			checkedKeys.unshift.apply(checkedKeys, halfCheckedKeys)
			return checkedKeys
		},
		closeForm(){
			this.$emit('update:show', false)
			this.loading = false
			if (this.$refs['form']!==undefined) {
				this.$refs['form'].resetFields()
			}
		},
		loadGroupList(newValue) {
            axios.post(base_url + '/Mpgrouptag/getGrouplist', { newValue })
               .then(res => {
                    if (res.data.status == 200) {
                        this.options = res.data.menus;
                    } else {
                        console.error('请求失败，状态码不为200:', res.data);
                    }
                })
               .catch(error => {
                    console.error('请求出错:', error);
                });
        },
	}
})
