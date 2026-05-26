Vue.component('Add', {
	template: `
		<el-dialog title="添加" width="600px" class="icon-dialog" :visible.sync="show" @open="open" :before-close="closeForm" append-to-body>
			<el-form :size="size" ref="form" :model="form" :rules="rules" :label-width=" ismobile()?'90px':'16%'">
			    <el-row >
					<el-col :span="24">
						<el-form-item label="选择机器人" prop="url">
							<select-page v-if="show" url="/Banwords/getRobot_id" :selectval.sync="form.bot_id"></select-page>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="发送类型" prop="type">
							<el-select style="width:100%" v-model="form.sendtype" filterable clearable placeholder="请选择">
								<el-option v-for="(item,i) in senddata" :key="i" :label="item.title" :value="item.sendtype"></el-option>
							</el-select>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="消息类型" prop="chattype">
							<el-select style="width:100%" v-model="form.chattype" filterable clearable placeholder="请选择">
								<el-option v-for="(item,i) in chatData" :key="i" :label="item.title" :value="item.chattype"></el-option>
							</el-select>
						</el-form-item>
					</el-col>
				</el-row>
				<el-row >
					<el-col :span="24">
						<el-form-item label="关键词" prop="word">
							<el-input  v-model="form.title" autoComplete="off" clearable  placeholder="请输入关键词"></el-input>
						</el-form-item>
					</el-col>
				</el-row>
				
				
				<!--el-row >
						<el-col :span="22">
							<el-form-item label="图片" prop="pic">
								<Upload v-if="show" size="small"  upload_type="1" file_type="image" :image.sync="form.pic"></Upload>
							</el-form-item>
						</el-col>
				</el-row-->
				<el-row>
						<el-col :span="24">
							<el-form-item label="描述" prop="content">
								<el-input  type="textarea" autoComplete="off" v-model="form.content"  :autosize="{ minRows: 2, maxRows: 4}" clearable placeholder="请输入描述"/>
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
			    sendtype:1,
				status:1,
			},
			senddata:[
		        {'sendtype':1,'title':'文字'},
		        {'sendtype':2,'title':'图片'},
		    ],
		    chatData:[
		        {'chattype':0,'title':'通用'},
		        {'chattype':1,'title':'私聊机器人'},
		        {'chattype':2,'title':'群组'},
		    ],
		    
			loading:false,
			rules: {
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
					axios.post(base_url + '/Telemessage/add',this.form).then(res => {
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
