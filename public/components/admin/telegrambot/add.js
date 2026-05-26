Vue.component('Add', {
	template: `
		<el-dialog title="添加" width="600px" class="icon-dialog" :visible.sync="show" @open="open" :before-close="closeForm" append-to-body>
			<el-form :size="size" ref="form" :model="form" :rules="rules" :label-width=" ismobile()?'90px':'16%'">
				<el-row >
					<el-col :span="24">
						<el-form-item label="机器人名称" prop="bot_name">
							<el-input  v-model="form.bot_name" autoComplete="off" clearable  placeholder="请输入用户名"></el-input>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="机器人Token" prop="bot_token">
							<el-input  v-model="form.bot_token" autoComplete="off" clearable  placeholder="请输入用户名"></el-input>
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
					    <el-form-item label="夜间模式" prop="logo">
							<el-time-picker
                              v-model="form.starttime"
                              :picker-options="startPickerOptions"
                              placeholder="开始时间"
                              format="HH:mm"
                              value-format='HH:mm'
                            ></el-time-picker>
                            
                            <el-time-picker
                              v-model="form.endtime"
                              :picker-options="endPickerOptions"
                              placeholder="结束时间"
                              format="HH:mm"
                              value-format='HH:mm'
                            ></el-time-picker>
						</el-form-item>
				    </el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="创建时间" prop="create_time">
							<el-date-picker value-format="yyyy-MM-dd HH:mm:ss" type="date" v-model="form.create_time" clearable placeholder="请输入创建时间"></el-date-picker>
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
			this.form.create_time = parseTime(this.form.create_time)
		},
		submit(){
			this.$refs['form'].validate(valid => {
				if(valid) {
					this.loading = true
					axios.post(base_url + '/Telegrambot/update',this.form).then(res => {
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
