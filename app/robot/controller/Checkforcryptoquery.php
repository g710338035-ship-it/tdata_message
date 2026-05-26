<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;


class Checkforcryptoquery extends Apibot
{
    public function __construct()
    {
      
    }
    // 检查消息中是否包含加密货币代码并获取价格
    public function checkForCryptoQuery($chatId, $text, $userId, $token,$messageId)
    {
       
                $symbol = strtoupper(trim($text));
                
                if (preg_match('/^(\w+)(币|USD|USDT)?$/', $symbol, $matches)) {
                    $currency = strtoupper($matches[1]); // 获取货币名称
                 
                 if (strpos($currency, 'USDT') !== false) {
                        $currency = str_replace('USDT', '', $currency); // 删除 "USDT"
                    }
               
                    // 根据输入判断货币对格式
                    if (strpos($text, '币') !== false) {
                        $currencyPair = $currency . '-USDT'; // 转换为 OKX 需要的格式
                    } else {
                        $currencyPair = $currency . '-USD'; // 转换为 OKX 需要的格式
                    }
                
                   
                    $data = $this->getCryptoPrice($currencyPair);
                    $datamarket= $this->getCryptoMarket($currencyPair);
                
                   if ($data['code']==0&&$datamarket['code']==0) {
                       $candles['data'] = array_reverse($data['data']);
                       $candles['market']=$datamarket['data'];
                       $imagePath= $this->generateKLineChart($candles,$currencyPair);
                       
                       $market=$candles['market'][0];
                       
                        $volCcy24h=$market['volCcy24h'];
                        $ts=(int)$market['ts']/1000;
                        $UTCts = date('Y-m-d H:i:s', $ts); 
                        $last=$market['last'];
                       $trs=" $currencyPair ( $UTCts UTC)\nPrice $last \nVolume $volCcy24h ";
                        // log::write($imagePath);
                        // 推送到 Telegram
                        $messageData = [
                            'chat_id' => $chatId,
                            'caption' => $trs,
                            'reply_to_message_id' => $messageId,
                            'photo' => config('app.domainurl').'/'.$imagePath, // 使用 CURLFile 发送图片
                        ];
                       $sss= send($token, 'sendPhoto', $messageData);
                       
                        // 删除本地生成的图片
                        $publicPath = public_path();
                        $imageDel = $publicPath. $imagePath; // 确保获取到正确的路径
                       // log::info($imageDel); // 记录图片的完整路径
                        
                        if (file_exists($imageDel)) {
                            unlink($imageDel); // 删除文件
                            //log::info("图片已删除: " . $imageDel);
                        } else {
                           // log::error("图片不存在: " . $imageDel);
                        }
                        
                        return true; 
                      
                    } else {
                        
                        return false; 
                      
                    }
                }
                
       return false;  // 没有检测到加密货币查询
    }
    //k线图
    protected function generateKLineChart($candles,$cdoe)
    {
        $data=$candles['data'];
        $market=$candles['market'][0];
        $bigwidth = 1000;
        $bigheight = 500;
        $width = 800;
        $height = 400;
        $image = imagecreatetruecolor($bigwidth, $bigheight);

        // 设定颜色
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 255, 255, 255);
        $green = imagecolorallocate($image, 0, 255, 0);
        $red = imagecolorallocate($image, 255, 0, 0);
        $gray = imagecolorallocate($image, 200, 200, 200);
        
        // 背景颜色
        $bgColor = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $bgColor);
        
        
        $offsetY = 50; // 向下移动的像素
        // 计算最大值和最小值
        $highValues = array_column($data, 2);
        $lowValues = array_column($data, 3);
        $maxValue = max($highValues);
        $minValue = min($lowValues);
        $scale = $maxValue - $minValue;
       // log::write($scale);
        
        // 设置字体路径
       
         $fontPath = '/www/wwwroot/klbot.globaldoge.site/public/static/simhei.ttf'; 
        // 在图片上方添加 BTCUSD 价格
        $lastPrice = end($data)[1]; // 假设第四个元素是最新的价格
        imagettftext($image, 15, 0, 360, 30, $black, $fontPath, "$cdoe 价格");
        
        // 右侧显示最高价和最低价
        $last=$market['last'];
        $open24h=$market['open24h'];
        $high24h=$market['high24h'];
        $low24h=$market['low24h'];
        $volCcy24h=$market['volCcy24h'];
        $ts=(int)$market['ts']/1000;
        $UTCts = date('Y-m-d H:i:s', $ts); 
        
        $texts = [
            "行情信息" => 30,
            "成交价" => 20 + $offsetY,
            "$last" => 50 + $offsetY,
            "开盘价" => 80 + $offsetY,
            "$open24h" => 110 + $offsetY,
            "最高价" => 140 + $offsetY,
            "$high24h" => 170 + $offsetY,
            "最低价" => 200 + $offsetY,
            "$low24h" => 230 + $offsetY,
            "成交量" => 260 + $offsetY,
            "$volCcy24h" => 290 + $offsetY,
            "UTC时间" => 320 + $offsetY,
            "$UTCts" => 350 + $offsetY,
        ];
        // 逐行处理文字居中
        foreach ($texts as $text => $yPosition) {
            // 获取文本边界框
            $bbox = imagettfbbox(13, 0, $fontPath, $text);
            
            // 计算文本宽度
            $textWidth = $bbox[2] - $bbox[0];
            
            // 计算居中的 X 坐标
            $x = (200 - $textWidth) / 2;
            
            // 绘制文字
            imagettftext($image, 10, 0, $width +$x, $yPosition, $black, $fontPath, $text);
        }
        
        /*imagettftext($image, 16, 0, $width +80, 30, $black, $fontPath, "行情信息");
        imagettftext($image, 15, 0, $width +10, 20 + $offsetY, $black, $fontPath, "成交价: $last");
        
        imagettftext($image, 15, 0, $width +10, 50 + $offsetY, $black, $fontPath, "开盘价: $open24h");
        imagettftext($image, 15, 0, $width +10, 80 + $offsetY, $black, $fontPath, "最高价: $high24h");
        imagettftext($image, 15, 0, $width +10, 110 + $offsetY, $black, $fontPath, "最低价: $low24h");
        imagettftext($image, 15, 0, $width +10, 140 + $offsetY, $black, $fontPath, "成交量: $volCcy24h");
        imagettftext($image, 15, 0, $width +10, 170 + $offsetY, $black, $fontPath, "UTC时间: $UTCts");*/
        
        imageline($image, $width, 0, $width, 500, $black); 
        
        
        imageline($image, 0, $height - 20+$offsetY , $width, $height - 20+$offsetY, $black); // X 轴
        imageline($image, 60, 0+$offsetY, 60, $height+$offsetY, $black); // Y 轴
        
        // 绘制价格标记和虚线
        $priceStep = ($height - 40) / 10; // Y 轴价格间隔
        for ($i = 0; $i <= 10; $i++) {
            $y = $height - 20 - $i * $priceStep+$offsetY;
            imageline($image, 60, $y, $width, $y, $gray); // 虚线
            // 标记价格
            $price = round($i * (max(array_column($data, 3)) - min(array_column($data, 2))) / 10 + min(array_column($data, 2)), 2);
            imagestring($image, 3, 5, $y - 10, $price, $black);
        }
        
        // 画 K 线
        $numData = count($data);
        $barWidth = ($width - 70) / $numData;

        foreach ($data as $index => $candle) {
            
            $open = (float)$candle[1];
            $close = (float)$candle[4];
            $high = (float)$candle[2];
            $low = (float)$candle[3];
            

            // 计算坐标
            $x = 70 + $index * $barWidth;
            $openY = $height-20 - (($open - $minValue) * ($height / $scale))+$offsetY;
            $closeY = $height-20 - (($close - $minValue) * ($height / $scale))+$offsetY;
            $highY = $height-20 - (($high - $minValue) * ($height / $scale))+$offsetY;
            $lowY = $height-20 - (($low - $minValue) * ($height / $scale))+$offsetY;

            // 画线
            $color = $close >= $open ? imagecolorallocate($image, 0, 255, 0) : imagecolorallocate($image, 255, 0, 0);
            imageline($image, $x + $barWidth / 2, $highY, $x + $barWidth / 2, $lowY, $color); // 画上下影线
            imagefilledrectangle($image, $x + $barWidth / 4, $closeY, $x + 3 * $barWidth / 4, $openY, $color); // 画 K 线
        
        }
        
        foreach ($data as $index => $candle) {
            $timestamp = $candle[0]/1000; // 时间戳
            $date = date('m-d H:i', $timestamp); // 格式化时间
            if($index%20==0){
            $x = 70 + $index * $barWidth + $barWidth / 2; // X 轴位置
            imagestring($image, 3, $x - 15, $height - 15+$offsetY, $date, $black);
            }
        }
        $dir = public_path('Kupload'); // 获取 public 目录下的 Kupload 路径
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true); // 创建目录
        }
        
        // 生成图片
        $imagePath = $dir . '/k_line_chart' . time() . '.png';
        // 保存图像
        $imagePaths = 'Kupload/k_line_chart' . time() . '.png';
      
        imagepng($image,  $imagePath);
        imagedestroy($image);
        
        return $imagePaths;
       
    }
    // 从币安获取加密货币价格
    private function getCryptoPrice($cryptoCode)
    {
        //$apiUrl = "https://api.coingecko.com/api/v3/coins/{$cryptoCode}/ohlc?vs_currency=usd&days=1";
        $apiUrl = "https://www.okx.com/api/v5/market/index-candles?instId={$cryptoCode}&bar=1m&limit=100";
        //$apiUrl = "https://api.binance.com/api/v3/ticker/price?symbol={$cryptoCode}USDT"; // 获取对 USDT 的价格
       
        // 使用 cURL 或其他方式发送请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
    
        // 解析响应
        $data = json_decode($response, true);
       
        if (isset($data['code'])) {
            return $data; // 返回价格
        }
        return false; // 返回 null 表示获取失败
    }
    
     // 从币安获取加密货币价格
    private function getCryptoMarket($cryptoCode)
    {  echo $cryptoCode;
        //$apiUrl = "https://api.coingecko.com/api/v3/coins/{$cryptoCode}/ohlc?vs_currency=usd&days=1";
        $apiUrl = "https://www.okx.com/api/v5/market/ticker?instId={$cryptoCode}-SWAP";
        //$apiUrl = "https://api.binance.com/api/v3/ticker/price?symbol={$cryptoCode}USDT"; // 获取对 USDT 的价格
       
        // 使用 cURL 或其他方式发送请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
    
        // 解析响应
        $data = json_decode($response, true);
       
        if (isset($data['code'])) {
            return $data; // 返回价格
        }
        return false; // 返回 null 表示获取失败
    }
}
