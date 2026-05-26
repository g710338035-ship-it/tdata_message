<?php
namespace app\common;
use think\facade\Db;
use think\facade\Cache;
use GuzzleHttp\Client;

class NodeManager {
    private $client;
    private $redis;

    public function __construct() {
        $this->client = new Client(['timeout' => config('telegram.node_check.timeout')]);
        $this->redis = Cache::store('redis')->handler();
    }

    /**
     * 获取所有健康节点（跨服务器）
     */
    public function getHealthyNodes(): array {
        $cacheKey = 'healthy_nodes';
        $cached = Cache::get($cacheKey);
        if ($cached) return $cached;

        $now = time();
        $expireTime = $now - config('telegram.node_check.heartbeat_expire');
        $nodes = Db::name('server_nodes')
            ->where('status', 1)
            ->where('last_heartbeat', '>=', $expireTime)
            ->field('server_ip, node_port, load, weight')
            ->select()
            ->each(function ($item) {
                $item['node_key'] = "{$item['server_ip']}:{$item['node_port']}";
                return $item;
            })
            ->toArray();

        Cache::set($cacheKey, $nodes, 10); // 缓存10秒
        return $nodes;
    }

    /**
     * 负载均衡选择节点（同机房优先+低负载）
     */
    public function selectNode(): array {
        $healthyNodes = $this->getHealthyNodes();
        if (empty($healthyNodes)) {
            throw new \Exception('无可用健康节点，请检查Python服务');
        }

        // 同机房判断（前3段IP一致）
        $localIp = $this->getLocalIp();
        $localNodes = [];
        $remoteNodes = [];
        foreach ($healthyNodes as $node) {
            if ($this->isSameNetwork($localIp, $node['server_ip'])) {
                $localNodes[] = $node;
            } else {
                $remoteNodes[] = $node;
            }
        }
        $candidates = $localNodes ?: $remoteNodes;

        // 按负载/权重排序（负载越低越优先）
        usort($candidates, function ($a, $b) {
            $ratioA = $a['load'] / $a['weight'];
            $ratioB = $b['load'] / $b['weight'];
            return $ratioA - $ratioB;
        });

        return $candidates[0];
    }

    /**
     * 更新节点负载
     */
    public function updateLoad(string $serverIp, int $port, int $delta = 1) {
        Db::name('server_nodes')
            ->where('server_ip', $serverIp)
            ->where('node_port', $port)
            ->inc('load', $delta)
            ->update();
    }

    /**
     * 获取本地服务器IP
     */
    private function getLocalIp(): string {
        return $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
    }

    /**
     * 判断是否同网段
     */
    private function isSameNetwork(string $ip1, string $ip2): bool {
        $seg1 = explode('.', $ip1);
        $seg2 = explode('.', $ip2);
        return count($seg1) === 4 && count($seg2) === 4
            && $seg1[0] === $seg2[0]
            && $seg1[1] === $seg2[1]
            && $seg1[2] === $seg2[2];
    }
    
     public function getBindNode(string $account): ?array {
        $bind = Db::name('account_node')
            ->where('account', $account)
            ->find();
        if (!$bind) return null;

        // 检查绑定节点是否健康
        $now = time();
        $expireTime = $now - config('telegram.node_check.heartbeat_expire');
        $node = Db::name('server_nodes')
            ->where('server_ip', $bind['server_ip'])
            ->where('node_port', $bind['node_port'])
            ->where('status', 1)
            ->where('last_heartbeat', '>=', $expireTime)
            ->find();

        return $node ? [
            'server_ip' => $node['server_ip'],
            'node_port' => $node['node_port'],
            'node_key' => "{$node['server_ip']}:{$node['node_port']}",
            'load' => $node['load'],
            'weight' => $node['weight']
        ] : null;
    }

    /**
     * 更新账号与节点的绑定关系
     */
    public function updateAccountBind(string $account, string $serverIp, int $nodePort) {
        $nodeKey = "{$serverIp}:{$nodePort}";
        // 更新绑定表
        Db::name('account_node')
            ->where('account', $account)
            ->save([
                'server_ip' => $serverIp,
                'node_port' => $nodePort,
                'last_used_time' => time()
            ]);
        // 若不存在则新增
        if (Db::name('account_node')->getLastInsID() === 0) {
            Db::name('account_node')->insert([
                'account' => $account,
                'server_ip' => $serverIp,
                'node_port' => $nodePort,
                'last_used_time' => time()
            ]);
        }
        // 更新账号表（可选）
        Db::name('mtuser')->where('account', $account)->update(['bind_node' => $nodeKey]);
    }
}