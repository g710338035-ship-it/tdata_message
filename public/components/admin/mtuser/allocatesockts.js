Vue.component('Allocatesockts', {
	template: `
		<el-dialog title="代理分配" width="600px" class="icon-dialog" :visible.sync="show" @open="open" :before-close="closeForm" append-to-body>
			<el-form :size="size" ref="form" :model="form" :rules="rules" :label-width=" ismobile()?'90px':'16%'">
				<el-row >
					<el-col :span="24">
						<el-form-item label="自定义" prop="socktswb">
							<el-input  type="textarea" autoComplete="off" v-model="form.socktswb"  :autosize="{ minRows: 2, maxRows: 4}" clearable placeholder="请输入批量随机格式如：127.0.0.1:80##username##password 不输入则分配数据库中的代理IP"/>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="代理分组" prop="socktsid">
							<select-page v-if="show" url="/Socktscate/getRobot_id" :selectval.sync="form.socktsid"></select-page>
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
		info: {
			type: Object,
		},
	},
	data(){
		return {
			form: {
				
			},
		
			loading:false,
			rules: {
			}
		}
	},
	methods: {
		open(){
			this.form = this.info
			console.log(this.form);
		
		},
		submit(){
			this.$refs['form'].validate(valid => {
				if(valid) {
					this.loading = true
					axios.post(base_url + '/Mtuser/allocateSockets',this.form).then(res => {
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
