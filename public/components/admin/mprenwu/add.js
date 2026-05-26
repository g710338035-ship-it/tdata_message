Vue.component('Add', {
	template: `
		<el-dialog title="添加" width="700px" class="icon-dialog" :visible.sync="show" @open="open" :before-close="closeForm" append-to-body>
			<el-form :size="size" ref="form" :model="form" :rules="rules" :label-width=" ismobile()?'90px':'16%'">
				<el-row >
					<el-col :span="24">
						<el-form-item label="任务名称" prop="title">
							<el-input  v-model="form.title" autoComplete="off" clearable  placeholder="请输入任务名称"></el-input>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="选择群标签" prop="url">
							<select-page v-if="show" url="/Mprenwu/getGrouptag_id" :selectval.sync="form.mpgt_id"></select-page>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row>
                  <el-col :span="24">
                    <el-form-item label="过滤类型" prop="filterType">
                      <el-select v-model="form.filterType" placeholder="请选择过滤类型">
                        <el-option v-for="type in filterTypes" :key="type.value" :label="type.label" :value="type.value"></el-option>
                      </el-select>
                    </el-form-item>
                  </el-col>
                </el-row>
                <!--el-row>
                  <el-col :span="24">
                    <el-form-item label="过滤值" prop="filterValue">
                      <template v-if="form.filterType === 'user_id'">
                        <el-input type="textarea"
                          v-model="form.filterValue" 
                          autoComplete="off" 
                          clearable 
                          placeholder="请输入用户 ID，多个 ID 一行一条分隔">
                        </el-input>
                      </template>
                      <template v-else-if="form.filterType === 'keyword'">
                        <el-input type="textarea"
                          v-model="form.filterValue" 
                          autoComplete="off" 
                          clearable 
                          placeholder="请输入关键词，多个关键词用一行一条分隔">
                        </el-input>
                      </template>
                      <template v-else>
                        <el-input type="textarea"
                          v-model="form.filterValue" 
                          autoComplete="off" 
                          clearable 
                          placeholder="请输入过滤值">
                        </el-input>
                      </template>
                    </el-form-item>
                  </el-col>
                </el-row-->
			    <el-row >
					<el-col :span="24">
						<el-form-item label="推送到群" prop="url">
							<select-page v-if="show" url="/Mpgroup/getRobot_id" :selectval.sync="form.mp_gid"></select-page>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="备注" prop="note">
							<el-input  v-model="form.note" autoComplete="off" clearable  placeholder="请输入备注"></el-input>
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
			     title: '',
                mp_id: '',
                mp_gid: '',
                status: 1,
                filterType: 'user_id', // 默认过滤类型
                filterValue: ''
			},
		   
			loading:false,
			rules: {
			},
				filterTypes: [
				{ label: '全部推送', value: 'user_id' }, 
                { label: '关键词推送', value: 'keyword' },
                { label: '新加入推送', value: 'newuser' }
              ]
		}
	},
	methods: {
		open(){
		},
		submit(){
			this.$refs['form'].validate(valid => {
				if(valid) {
					this.loading = true
					axios.post(base_url + '/Mprenwu/add',this.form).then(res => {
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
