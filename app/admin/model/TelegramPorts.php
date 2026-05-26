<?php
namespace app\admin\model;

use think\Model;
use app\admin\model\Mtuser;
class TelegramPorts extends Model
{
    protected $name = 'telegram_ports';
    
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
    
    /**
     * 获取可用的端口列表
     */
    public static function getAvailablePorts(): array
    {
        $ports = self::where('status', 1)
            ->field('id, port, host, weight, current_connections, max_connections')
            ->select()
            ->toArray();
            
        // 更新当前连接数为真实数据
        foreach ($ports as &$port) {
            $port['current_connections'] = self::getRealConnectionCount($port['port']);
        }
        
        return $ports;
    }
    /**
     * 获取端口的真实连接数（基于mtuser表）并更新数据库
     */
    public static function getRealConnectionCount(int $port): int
    {
        // 统计真实连接数
        $realCount = Mtuser::where('api_port', $port)->where('account_status', '正常')->count();
        
        // 更新TelegramPorts表中的current_connections字段
        self::where('port', $port)->update(['current_connections' => $realCount]);
        
        return $realCount;
    }
    /**
     * 根据端口号获取配置
     */
    public static function getPortConfig(int $port): ?array
    {
        $config = self::where('port', $port)
            ->where('status', 1)
            ->find();
            
        return $config ? $config->toArray() : null;
    }
    
    /**
     * 增加连接数
     */
    public static function incrementConnections(int $port): bool
    {
        $model = self::where('port', $port)->find();
        if ($model && $model->current_connections < $model->max_connections) {
            $model->current_connections = self::getRealConnectionCount($port);
            return $model->save();
        }
        return false;
    }
    
    /**
     * 减少连接数
     */
    public static function decrementConnections(int $port): bool
    {
        $model = self::where('port', $port)->find();
        if ($model && $model->current_connections > 0) {
            $model->current_connections = self::getRealConnectionCount($port);
            return $model->save();
        }
        return false;
    }
    

}