Vue.component('Upnick', {
	template: `
		<el-dialog title="修改昵称信息" width="600px" class="icon-dialog" :visible.sync="show" @open="open" :before-close="closeForm" append-to-body>

			<el-form :size="size" ref="form" :model="form" :rules="rules" :label-width="ismobile()?'90px':'16%'">
                <el-row >
					<el-col :span="24">
						<el-form-item label="昵称文件" prop="file">
							<Upload v-if="show" size="small" file_type="file"  :file.sync="form.file"></Upload>
							<div style="margin-top: 10px; color: #666; font-size: 12px;">
                                请上传txt文件，每行一个昵称，将按顺序分配给选中的账户
                            </div>
						</el-form-item>
					</el-col>
				</el-row>
			    
			</el-form>

			<div slot="footer" class="dialog-footer">
				<el-button :size="size" :loading="loading" type="primary" @click="submit">
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
				
				file: '',
			},
		    is_clear:false,
			loading: false,
			rules: {
				file: [
					{ required: true, message: '请选择昵称文件', trigger: 'blur' }
				],
			}
		}
	},
	methods: {
		open(){
			this.form = this.info
		},
	

		submit(){
			this.$refs['form'].validate(valid => {
				if(valid) {
					this.loading = true
					axios.post(base_url + '/Mtuser/upnick',this.form).then(res => {
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
			this.$emit('update:show', false);
			this.loading = false;
			if (this.$refs['form'] !== undefined) {
				this.$refs['form'].resetFields();
			}
			this.is_clear=true
		}
	}
})
