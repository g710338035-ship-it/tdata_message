Vue.component('simport', {
    template: `
    <el-dialog title="导入txt" style="margin-top:100px;" width="600px" :visible.sync="show" @close="closeForm" append-to-body>
        <el-form :size="size" ref="form" :model="form" :rules="rules" :label-width=" ismobile()?'90px':'16%'">
            <el-row >
                <el-col :span="24">
                    <el-form-item label="代理分组" prop="skcateid">
                        <select-page v-if="show" url="/Socktscate/getRobot_id" :selectval.sync="form.skcateid"></select-page>
                    </el-form-item>
                </el-col>
            </el-row>
        </el-form>      
            <el-upload v-if="!process" class="upload-demo" action :auto-upload="false" :show-file-list="false" :on-change="choose_file">
                <el-button size="mini" icon="el-icon-upload" type="primary">请选择导入txt文件</el-button> <span style="color:#ff0000">{{file.name}}</span>
            </el-upload>
          
        <el-progress v-else :percentage="percentage"></el-progress>
        
        <div style="margin: 15px 0; padding: 10px; background: #f5f7fa; border-radius: 4px;">
            <div  v-if="txtData.length > 0 && !process" style="margin-bottom: 5px;">检测到 <font color=red>{{txtData.length}}</font> 条有效代理数据</div>
            <div style="color: #666; font-size: 12px;">支持格式：ip:port、ip:port##username##password、socks5://user:pass@host:port等</div>
        </div>
        
        <div slot="footer" class="dialog-footer">
            <el-button :size="size" :loading="loading" type="primary" @click="submit" >
                <span v-if="!loading">确 定</span>
                <span v-else>提 交 中...</span>
            </el-button>
            <el-button :size="size" @click="closeForm">取 消</el-button>
        </div>
    </el-dialog>
    `,
    props: {
        show: {
            type: Boolean,
            default: true
        },
        size: {
            type: String,
            default: 'mini'
        },
        import_url:{
            type:String
        }
    },
    data() {
        return {
            file: "",
            form: {},
            rules: {
                skcateid: [
				    { required: true, message: '请选择代理分组', trigger: 'change' }
				]
            },
            process:false,
            loading:false,
            txtData:[],
            percentage:0,
            page:1,
            limit:200,
        }
    },
    methods: {
        choose_file(file) {
            // 检查文件类型
            const fileType = file.name.split('.').pop().toLowerCase();
            if(fileType !== 'txt') {
                this.$message.error('请选择TXT格式文件');
                return;
            }
            this.file = file
            this.readTxtFile(file)
        },
        readTxtFile(file) {
            const fileReader = new FileReader()
            fileReader.onload = (ev) => {
                try{
                    const content = ev.target.result;
                    // 按行分割文本内容
                    const lines = content.split(/\r\n|\n|\r/);
                    
                    // 解析每一行数据
                    this.txtData = lines.map(line => {
                        if(!line.trim()) return null; // 跳过空行
                        
                        // 尝试识别格式
                        let item = null;
                        // 格式0: socks5://user:pass@host:port 或 socks5h://...
                        if(line.includes('://')) {
                            item = this.parseProxyUrl(line.trim());
                        }
                        // 格式1: ip:port##username##password
                        else if(line.includes('##')) {
                            const parts = line.split('##');
                            if(parts.length >= 2) {
                                const [ipPort, username, password] = parts;
                                const [ip, port] = ipPort.split(':');
                                item = {
                                    ip: ip ? ip.trim() : '',
                                    port: port ? port.trim() : '',
                                    username: username ? username.trim() : '',
                                    password: password ? password.trim() : ''
                                };
                            }
                        } 
                        // 格式2: ip:port:username:password
                        else if((line.match(/:/g) || []).length >= 3) {
                            const parts = line.split(':');
                            if(parts.length >= 4) {
                                item = {
                                    ip: parts[0] ? parts[0].trim() : '',
                                    port: parts[1] ? parts[1].trim() : '',
                                    username: parts[2] ? parts[2].trim() : '',
                                    password: parts.slice(3).join(':').trim() // 处理密码中可能包含的冒号
                                };
                            }
                        }
                        // 格式3: ip:port
                        else if(line.includes(':')) {
                            const [ip, port] = line.split(':');
                            item = {
                                ip: ip ? ip.trim() : '',
                                port: port ? port.trim() : '',
                                username: '',
                                password: ''
                            };
                        }
                        
                        // 验证IP和端口格式
                        if(item && (!item.ip || !item.port)) {
                            return null;
                        }
                        
                        return item;
                    }).filter(item => item !== null);
                    
                    if(this.txtData.length === 0) {
                        this.$message.error('文件内容格式不正确，未找到有效数据');
                    }
                }catch(e){
                    this.$message.error('文件读取失败，请检查文件格式');
                }
            }
            fileReader.readAsText(file.raw, 'UTF-8');
        },
        parseProxyUrl(url) {
            try {
                // 先去除协议头
                if (!url.includes('://')) {
                    return null;
                }
                
                const [protocol, rest] = url.split('://');
                if (!protocol.startsWith('socks5')) {
                    return null;
                }
                
                // 检查是否有认证信息
                let username = '';
                let password = '';
                let hostPort = rest;
                
                // 如果有 @ 符号，表示有认证信息
                if (rest.includes('@')) {
                    const [auth, server] = rest.split('@');
                    hostPort = server;
                    
                    // 认证信息中可能有冒号分隔用户名密码
                    if (auth.includes(':')) {
                        [username, password] = auth.split(':');
                    } else {
                        username = auth;
                    }
                }
                
                // 解析主机和端口
                if (!hostPort.includes(':')) {
                    return null;
                }
                
                const lastColonIndex = hostPort.lastIndexOf(':');
                const host = hostPort.substring(0, lastColonIndex);
                const port = hostPort.substring(lastColonIndex + 1);
                
                // 验证端口是否为数字
                if (!/^\d+$/.test(port)) {
                    return null;
                }
                
                return {
                    ip: host.trim(),
                    port: port.trim(),
                    username: username.trim(),
                    password: password.trim(),
                    protocol: protocol
                };
                
            } catch(e) {
                console.error('解析代理URL失败:', url, e);
                return null;
            }
        },
        submit(){
          
            if(!this.form.skcateid) {
                this.$message.error('请选择代理分组');
                return;
            }

            if(this.txtData.length === 0) {
                this.$message.error('没有可导入的数据');
                return;
            }

            
            this.process = true
            this.loading = true
            let data = this.getData()
            let total_page = Math.ceil(this.txtData.length/this.limit)
            this.percentage = Math.ceil(this.page*100/total_page)
            
            axios.post(base_url+'/Sockts/import', {
                skcateid: this.form.skcateid,
                data: data
            }).then(res => {
                if(res.data.status == 200){
                    if(this.page <= total_page-1){
                        this.page = this.page +1
                        this.submit()
                    }else{
                        this.$message({message: '导入完成', type: 'success'})
                        this.$emit('refesh_list')
                        this.closeForm()
                    }
                } else {
                    this.$message.error(res.data.msg || '导入失败');
                    this.loading = false;
                }
            }).catch(()=>{
                this.loading = false;
                this.$message.error('网络错误，请重试');
            })
        },
        getData(){
            let perdata = []
            for(let i=(this.page-1)*this.limit; i<this.page*this.limit; i++){
                if(this.txtData[i]){
                    perdata.push(this.txtData[i])
                }
            }
            return perdata
        },
        closeForm(){
            this.$emit('update:show', false)
            this.file = ''
            this.process = false
            this.percentage = 0
            this.loading = false
            this.page = 1
            this.limit = 200
            this.txtData = []
        }
    }
});