<?php
namespace app\service;

use think\facade\Log;
use think\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
class TelegramTdata
{
    private $apiId;
    private $apiHash;
    private $pythonScriptPath; // Python脚本路径
    private $httpClient;

    public function __construct(Client $httpClient = null)
    {
       
        // Python脚本路径（根据实际存放位置调整）
        $this->pythonScriptPath = app()->getRootPath() . 'python_scripts/telegram_manager.py';
        // 初始化Guzzle客户端
        $this->httpClient = $httpClient ?? new Client([
            'timeout'         => 60.0,      // 整体超时时间
            'connect_timeout' => 10.0,      // 连接超时时间
            'read_timeout'    => 30.0,      // 读取超时时间
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'TelegramApiClient/1.0'
            ]
        ]);
    }

    private function callPythonScript($action,$last_api_address, $tdataPath = null,$sessionPath=null, $proxy = null, $params = [])
    {
        
        // Flask 服务的基础 URL
        $baseUrl = $last_api_address; // 替换为你的 Flask 服务地址
        $url = $baseUrl . '/telegram_action';
    
        // 基础请求数据
        $requestData = [
            'action'    => $action
        ];

        // 添加tdata路径
        if ($tdataPath) {
            //$requestData['tdata_path'] = $tdataPath;
            $requestData['tdata_path'] = $sessionPath; 
        }

        // 处理代理信息
        if ($proxy) {
            $proxyParts = explode('##', $proxy);
            if (count($proxyParts) >= 3) {
                list($ipPort, $username, $password) = $proxyParts;
                $requestData['proxy'] = "socks5://{$username}:{$password}@{$ipPort}";
            } else {
                $error = "代理格式错误，正确格式应为: ip:port##username##password";
                log::error($error, ['proxy' => $proxy]);
                throw new \Exception($error);
            }
        }

        // 合并额外参数
        $requestData = array_merge($requestData, $params);

        try {
            $pythonCallStartTime = microtime(true);
            Log::info("开始时间：" . date('Y-m-d H:i:s'));
            // 记录请求信息
            /*log::info('调用Telegram接口', [
                'url'    => $url,
                'action' => $action,
                'params' => $requestData
            ]);*/

            // 发送POST请求
            $response = $this->httpClient->post($url, [
                'json' => $requestData
            ]);
            log::info('接口响应原始数据'.json_encode($requestData));
            // 获取响应内容
            $responseBody = $response->getBody()->getContents();
            log::info('接口响应原始数据'.$responseBody);

            // 解析JSON响应
            $result = json_decode($responseBody, true);
            $pythonCallEndTime = microtime(true);
            $pythonCallCost = round($pythonCallEndTime - $pythonCallStartTime, 2);
            
            // 检查JSON解析错误
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = "JSON解析失败: " . json_last_error_msg();
                log::error($error.$action.$responseBody);
                throw new \Exception($error);
            }

             Log::info("接口调用成功：耗时：{$pythonCallCost}秒");
        

            return $result;

        } catch (RequestException $e) {
            // 处理HTTP请求异常
            $errorDetails = [];
            $errorMessage = "请求接口失败: ";

            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorMessage .= "状态码: {$statusCode}, 错误内容: {$errorBody}";
                $errorDetails['status_code'] = $statusCode;
                $errorDetails['response_body'] = $errorBody;
            } else {
                $errorMessage .= $e->getMessage();
            }

            $errorDetails['action'] = $action;
            $errorDetails['exception'] = $e->getMessage();
            $errorDetails['trace'] = $e->getTraceAsString();
            
            log::error($errorMessage, $errorDetails);
            throw new \Exception($errorMessage);

        } catch (GuzzleException $e) {
            // 处理其他Guzzle异常
            $errorMessage = "Guzzle请求异常: " . $e->getMessage();
            log::error($errorMessage, [
                'action' => $action,
                'trace'  => $e->getTraceAsString()
            ]);
            throw new \Exception($errorMessage);

        } catch (\Exception $e) {
            // 处理其他异常
            log::error('接口调用发生异常'.json_encode([
                'action'  => $action,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]));
            throw $e;
        }
    }
    /**
     * 检测tdata账号有效性（支持代理）
     * @param string $tdataPath tdata路径
     * @param string $proxy 代理信息
     * @return array
     */
    public function checkTdata($tdataPath,$last_api_address,$sessionPath, $proxy = null)
    {
        return $this->callPythonScript('check_tdata',$last_api_address, $tdataPath,$sessionPath, $proxy);
    }


    /**
     * 测试代理有效性
     * @param string $proxy 代理信息（格式：protocol://username:password@ip:port）
     * @return array
     */
    public function testProxy($proxy)
    {
        return $this->callPythonScript('test_proxy', null, $proxy);
    }

    
    /**
     * 修改账号密码
     * @param string $tdataPath tdata路径
     * @param string $currentPassword 当前密码
     * @param string $newPassword 新密码
     * @param string $proxy 代理信息（可选）
     * @return array
     */
    public function changePassword($tdataPath,$last_api_address,$sessionPath, $currentPassword, $newPassword, $proxy = null)
    {
        $params = [
            'current_password' => $currentPassword,
            'new_password' => $newPassword
        ];
        return $this->callPythonScript('change_password', $last_api_address,$tdataPath,$sessionPath, $proxy,$params);
    }

    /**
     * 更新头像
     * @param string $tdataPath tdata路径
     * @param string $photoPath 头像图片路径（相对于public目录）
     * @param string $proxy 代理信息（可选）
     * @return array
     */
    public function updateProfilePhoto($tdataPath,$last_api_address,$sessionPath, $photoPath, $proxy = null)
    {
        // 处理图片路径（转为绝对路径）
        $resolvedPhotoPath = app()->getRootPath() . 'public/' . $photoPath;
        if (!file_exists($resolvedPhotoPath)) {
            throw new Exception("头像文件不存在: {$resolvedPhotoPath}");
        }

        $params = ['photo_path' => $resolvedPhotoPath];
        return $this->callPythonScript('update_photo', $last_api_address,$tdataPath,$sessionPath, $proxy,$params);
    }

    /**
     * 修改昵称（名和姓）
     * @param string $tdataPath tdata路径
     * @param string $firstName 名
     * @param string $lastName 姓（可选）
     * @param string $proxy 代理信息（可选）
     * @return array
     */
    public function updateNickname($tdataPath,$last_api_address,$sessionPath, $firstName, $lastName = '', $proxy = null)
    {
        $params = [
            'first_name' => $firstName,
            'last_name' => $lastName
        ];
        return $this->callPythonScript('update_nickname',$last_api_address, $tdataPath,$sessionPath, $proxy,$params);
    }

    /**
     * 修改用户名（@标识）
     * @param string $tdataPath tdata路径
     * @param string $username 新用户名
     * @param string $proxy 代理信息（可选）
     * @return array
     */
    public function updateUsername($tdataPath,$last_api_address,$sessionPath, $username, $proxy = null)
    {
        $params = ['username' => $username];
        return $this->callPythonScript('update_username', $last_api_address,$tdataPath,$sessionPath, $proxy,$params);
    }

    /**
     * 修改个人签名
     * @param string $tdataPath tdata路径
     * @param string $bio 个人签名内容
     * @param string $proxy 代理信息（可选）
     * @return array
     */
    public function updateBio($tdataPath,$last_api_address,$sessionPath, $bio, $proxy = null)
    {
        $params = ['bio' => $bio];
        return $this->callPythonScript('update_bio', $last_api_address,$tdataPath,$sessionPath, $proxy,$params);
    }
     
}
    