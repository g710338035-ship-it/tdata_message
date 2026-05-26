Vue.component('Detail', {
	template: `
		<el-dialog title="查看详情" width="600px" @open="open" class="icon-dialog" :visible.sync="show" :before-close="closeForm" append-to-body>
			<table cellpadding="0" cellspacing="0" class="table table-bordered" align="center" width="100%" style="word-break:break-all; margin-bottom:50px;  font-size:13px;">
				<tbody>
					<tr>
						<td class="title" width="100">用户名：</td>
						<td>
							{{form.username}}
						</td>
					</tr>
					<tr>
						<td class="title" width="100">性别：</td>
						<td>
							<span v-if="form.sex == '1'">男</span>
							<span v-if="form.sex == '2'">女</span>
						</td>
					</tr>
					<tr>
						<td class="title" width="100">手机号：</td>
						<td>
							{{form.mobile}}
						</td>
					</tr>
					<tr>
						<td class="title" width="100">头像：</td>
						<td>
							<el-image v-if="form.pic" class="table_list_pic" :src="form.pic"  :preview-src-list="[form.pic]"></el-image>
						</td>
					</tr>
					<tr>
						<td class="title" width="100">邮箱：</td>
						<td>
							{{form.email}}
						</td>
					</tr>
					<tr>
						<td class="title" width="100">状态：</td>
						<td>
							<span v-if="form.status == '1'">开启</span>
							<span v-if="form.status == '0'">关闭</span>
						</td>
					</tr>
					<tr>
						<td class="title" width="100">积分：</td>
						<td>
							{{form.amount}}
						</td>
					</tr>
					<tr>
						<td class="title" width="100">省市区：</td>
						<td>
							{{form.ssq}}
						</td>
					</tr>
					<tr>
						<td class="title" width="100">创建时间：</td>
						<td>
							{{parseTime(form.create_time,'{y}-{m}-{d}')}}
						</td>
					</tr>
				</tbody>
			</table>
		</el-dialog>
	`
	,
	props: {
		show: {
			type: Boolean,
			default: true
		},
		size: {
			type: String,
			default: 'mini'
		},
		info: {
			type: Object,
		},
	},
	data() {
		return {
			form:{
			},
		}
	},
	methods: {
		open(){
			axios.post(base_url+'/Membe/detail',this.info).then(res => {
				this.form = res.data.data
			})
		},
		closeForm(){
			this.$emit('update:show', false)
		}
	}
})
