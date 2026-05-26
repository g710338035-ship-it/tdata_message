Vue.component('Add', {
	template: `
		<el-dialog title="添加" width="600px" class="icon-dialog" :visible.sync="show" @open="open" :before-close="closeForm" append-to-body>
			<el-form :size="size" ref="form" :model="form" :rules="rules" :label-width=" ismobile()?'90px':'16%'">
				<el-row >
					<el-col :span="24">
						<el-form-item label="代理分组" prop="skcateid">
							<select-page v-if="show" url="/Socktscate/getRobot_id" :selectval.sync="form.skcateid"></select-page>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="IP" prop="ip">
							<el-input  v-model="form.ip" autoComplete="off" clearable  placeholder="请输入ip"></el-input>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="端口" prop="port">
							 <el-input v-model="form.port" autoComplete="off" clearable placeholder="请输入端口"></el-input>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="用户名" prop="username">
							<el-input  v-model="form.username" autoComplete="off" clearable  placeholder="请输入username"></el-input>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="密码" prop="password">
							<el-input  v-model="form.password" autoComplete="off" clearable  placeholder="请输入密码"></el-input>
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
				status:1,
				ip: '',
        		port: ''
			},
			loading:false,
			rules: {
				ip: [
				{ required: true, message: '请输入IP地址', trigger: 'blur' },
				],
				port: [
					{ required: true, message: '请输入端口号', trigger: 'blur' },
					{ 
						validator: (rule, value, callback) => {
						const numValue = Number(value);
						if (isNaN(numValue)) {
							callback(new Error('端口号必须为数字'));
						} else if (numValue < 1 || numValue > 65535) {
							callback(new Error('端口号范围为1-65535'));
						} else {
							callback();
						}
						}, 
						trigger: 'blur' 
					}
				],
				skcateid: [
				{ required: true, message: '请选择代理分组', trigger: 'change' }
				]
			}
		}
	},
	methods: {
		open(){
		},
		submit(){
			this.$refs['form'].validate(valid => {
				if(valid) {
					this.loading = true
					axios.post(base_url + '/Sockts/add',this.form).then(res => {
						if(res.data.status == 200){
							this.$message({message: res.data.msg, type: 'success'})
							this.$emit('refesh_list')
							this.closeForm()
						}else{
							this.loading = false
							this.$message.error(res.data.msg)
						}
					}).catch(()=>{
						this.loading = false
					})
				}
			})
		},
		closeForm(){
			this.$emit('update:show', false)
			this.loading = false
			if (this.$refs['form']!==undefined) {
				this.$refs['form'].resetFields()
			}
		},
	}
})
