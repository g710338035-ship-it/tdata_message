<?php
namespace app\admin\controller;
use think\facade\Db;
use think\Controller;

class NodeApi extends Controller {
    // 节点注册
    public function register() {
        $data = $this->request->json();
        $serverIp = $data['server_ip'];
        $port = $data['node_port'];

        // 存在则更新，不存在则新增
        $node = Db::name('server_nodes')
            ->where('server_ip', $serverIp)
            ->where('node_port', $port)
            ->find();

        if ($node) {
            Db::name('server_nodes')->where('id', $node['id'])->update([
                'status' => 1,
                'last_heartbeat' => time()
            ]);
        } else {
            Db::name('server_nodes')->insert([
                'server_ip' => $serverIp,
                'node_port' => $port,
                'status' => 1,
                'last_heartbeat' => time(),
                'create_time' => time()
            ]);
        }

        return json(['code' => 200, 'msg' => '注册成功']);
    }

    // 节点心跳
    public function heartbeat() {
        $data = $this->request->json();
        $rows = Db::name('server_nodes')
            ->where('server_ip', $data['server_ip'])
            ->where('node_port', $data['node_port'])
            ->update([
                'last_heartbeat' => time(),
                'load' => $data['load'],
                'status' => 1
            ]);

        return json(['code' => 200, 'msg' => '心跳更新成功']);
    }
}