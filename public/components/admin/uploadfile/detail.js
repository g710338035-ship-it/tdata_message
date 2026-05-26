Vue.component('Detail', {
	template: `
		<el-dialog title="查看详情" width="600px" @open="open" class="icon-dialog" :visible.sync="show" :before-close="closeForm" append-to-body>
			<table cellpadding="0" cellspacing="0" class="table table-bordered" align="center" width="100%" style="word-break:break-all; margin-bottom:50px;  font-size:13px;">
				<tbody>
					<tr>
						<td class="title" width="100">标题：</td>
						<td>
							{{form.title}}
						</td>
					</tr>
					<tr>
						<td class="title" width="100">单图上传：</td>
						<td>
							<el-image v-if="form.pic" class="table_list_pic" :src="form.pic"  :preview-src-list="[form.pic]"></el-image>
						</td>
					</tr>
					<tr>
						<td class="title" width="100">单图2：</td>
						<td>
							<el-image v-if="form.pic_2" class="table_list_pic" :src="form.pic_2"  :preview-src-list="[form.pic_2]"></el-image>
						</td>
					</tr>
					<tr>
						<td class="title" width="100">多图上传：</td>
						<td>
						<div v-if="form.pics && form.pics.indexOf('[{') != -1" class="demo-image__preview">
							<el-image style="margin-right:5px" v-for="(item,i) in JSON.parse(form.pics)"  class="table_list_pic" :src="item.url" :key="i"  :preview-src-list="[item.url]"></el-image>
						</div>
						</td>
					</tr>
					<tr>
						<td class="title" width="100">单文件：</td>
						<td>
						<el-link v-if="form.file" style="font-size:13px;" :href="form.file" target="_blank">下载附件</el-link>
						</td>
					</tr>
					<tr>
						<td class="title" width="100">多文件：</td>
						<td>
						<div v-if="form.files && form.files.indexOf('[{') != -1">
							<el-link style="margin-right:5px; font-size:13px" v-for="(item,i) in JSON.parse(form.files)" target="_blank" :href="item.url"  :key="i">下载附件{{i+1}}</el-link>
						</div>
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
			axios.post(base_url+'/Uploadfile/detail',this.info).then(res => {
				this.form = res.data.data
			})
		},
		closeForm(){
			this.$emit('update:show', false)
		}
	}
})
