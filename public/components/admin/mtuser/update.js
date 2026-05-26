Vue.component('Update', {
	template: `
		<el-dialog title="修改信息" width="600px" class="icon-dialog" :visible.sync="show" @open="open" :before-close="closeForm" append-to-body>
		

			<el-form :size="size" ref="form" :model="form" :rules="rules" :label-width="ismobile()?'90px':'16%'">
			    	<!-- 操作类型选择 -->
    			<el-form-item label="操作类型">
    				<el-select v-model="selectedType" placeholder="请选择修改类型" @change="handleTypeChange">
    					<el-option label="更新头像" value="avatar"></el-option>
    					<el-option label="修改昵称" value="nickname"></el-option>
    				</el-select>
    			</el-form-item>
			

				<!-- 头像更新 -->
				<template v-if="selectedType === 'avatar'">
					<el-row>
						<el-col :span="24">
							<el-form-item label="头像图片" prop="avatar">
								<Upload v-if="show" size="small"  upload_type="2" file_type="image" :image.sync="form.avatar"></Upload>
							</el-form-item>
						</el-col>
					</el-row>
				</template>

				<!-- 昵称修改 -->
				<template v-if="selectedType === 'nickname'">
					<el-row>
						<el-col :span="24">
							<el-form-item label="名" prop="first_name">
								<el-input v-model="form.first_name" autoComplete="off" clearable placeholder="请输入名"></el-input>
							</el-form-item>
						</el-col>
					</el-row>
					
					<el-row>
						<el-col :span="24">
							<el-form-item label="姓" prop="last_name">
								<el-input v-model="form.last_name" autoComplete="off" clearable placeholder="请输入姓"></el-input>
							</el-form-item>
						</el-col>
					</el-row>
				</template>

			
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
				id: '',
				title: '',
				phone: '',
				tdata_path: '',
				proxy: '',
				current_password: '',
				new_password: '',
				first_name: '',
				last_name: '',
				username: '',
				bio: '',
				avatar: ''
			},
			selectedType: '', // 选中的操作类型
			loading: false,
			rules: {
				tdata_path: [
					{ required: true, message: '请输入tdata路径', trigger: 'blur' }
				],
				current_password: [
					{ required: true, message: '请输入当前密码', trigger: 'blur' }
				],
				new_password: [
					{ required: true, message: '请输入新密码', trigger: 'blur' },
					{ min: 6, message: '密码长度不能少于6位', trigger: 'blur' }
				],
				first_name: [
					{ required: true, message: '请输入名', trigger: 'blur' }
				],
				username: [
					{ required: true, message: '请输入用户名', trigger: 'blur' },
					{ pattern: /^[a-zA-Z0-9_]{5,32}$/, message: '用户名只能包含字母、数字和下划线，长度5-32位', trigger: 'blur' }
				]
			}
		}
	},
	methods: {
		open(){
			// 初始化表单数据
			this.form = this.info
			// 默认选择基本信息修改
			
		},
		// 处理操作类型变更
		handleTypeChange(type){
			this.selectedType = type;
			// 重置当前类型不需要的字段
			Object.keys(this.form).forEach(key => {
				if(!this.getRequiredFields().includes(key) && key !== 'id' && key !== 'tdata_path' && key !== 'proxy'){
					this.form[key] = '';
				}
			});
		},
		// 获取当前操作类型需要的字段
		getRequiredFields(){
			const fields = {
				password: ['current_password', 'new_password'],
				avatar: ['avatar'],
				nickname: ['first_name'],
				username: ['username'],
				bio: ['bio']
			};
			return fields[this.selectedType] || [];
		},

		submit(){
			// 验证当前类型的必填字段
			const requiredFields = this.getRequiredFields();
			let isValid = true;
			
			requiredFields.forEach(field => {
				if(!this.form[field] && this.form[field] !== 0){
					this.$message.error(`请填写${this.getFieldLabel(field)}`);
					isValid = false;
				}
			});
			
			if(!isValid) return;
			
			this.loading = true;
			
			// 创建FormData用于文件上传
			const formData = new FormData();
			Object.keys(this.form).forEach(key => {
				
					formData.append(key, this.form[key] || '');
			
			});
			// 添加操作类型
			formData.append('operate_type', this.selectedType);
			
			axios.post(base_url + '/Mtuser/update', formData, {
				headers: {
					'Content-Type': 'multipart/form-data'
				}
			}).then(res => {
				if(res.data.status == 200){
					this.$message({message: res.data.msg, type: 'success'});
					this.$emit('refesh_list');
					this.closeForm();
				}else{
					this.$message.error(res.data.msg);
				}
				this.loading = false;
			}).catch(()=>{
				this.loading = false;
				this.$message.error('网络错误，请重试');
			});
		},
		// 获取字段标签文本
		getFieldLabel(field){
			const labels = {
				current_password: '当前密码',
				new_password: '新密码',
				first_name: '名',
				last_name: '姓',
				username: '用户名',
				bio: '个人签名',
				avatar: '头像图片'
			};
			return labels[field] || field;
		},
		closeForm(){
			this.$emit('update:show', false);
			this.loading = false;
			if (this.$refs['form'] !== undefined) {
				this.$refs['form'].resetFields();
			}
			// 清除头像预览URL
			if(this.form.avatar){
				URL.revokeObjectURL(this.form.avatar);
			}
		},
		ismobile(){
			// 移动端判断逻辑
			return window.innerWidth < 768;
		}
	}
})
