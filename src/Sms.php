<?php

namespace ThedoRap;

use mysql_xdevapi\Exception;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;
use TencentCloud\Sms\V20210111\SmsClient;

class Sms
{
    /**
     * 默认配置文件
     * @param string $Method 请求方式
     * @param string $Timeout 请求超时时间
     * @param string $Endpoint 指定接入地域域名(默认就近接入)
     * @param string $SignMethod 指定签名算法
     * @param string $Region 地域 默认 ap-guangzhou
     * @param string $RedisIdent Redis前缀标识
     * @param string $RedisTimeout Redis过期时间 秒
     */
    public $Method = "GET", $Timeout = 30, $Endpoint = "sms.tencentcloudapi.com", $RedisIdent = "ReaperSms", $RedisTimeout = 300;

    /**
     * 短信配置文件
     * @param string $secretId secretId
     * @param string $secretKey secretKey
     * @param string $AppId AppId
     * @param int $signName 短信类型：0表示普通短信, 1表示营销短信
     * @param int $templateId 模板号
     * @param array $param 短信参数 [参数1，参数2，...]
     * @param array $mobile 手机号 [18888888888,15555555555] 可批量
     * @param array $Redis 验证短信 1启用 Redis 0 关闭
     * @param string $SignMethod 指定签名算法
     * @param string $Region 地域 默认 ap-guangzhou
     */
    public $secretId, $secretKey, $AppId, $signName, $templateId, $param, $mobile, $Redis = [], $SignMethod, $Region;


    /**
     * 腾讯云配置文件
     * @param string $secretId secretId
     * @param string $secretKey secretKey
     * @param string $AppId Appid
     * @param string $signName 短信类型：0表示普通短信, 1表示营销短信
     * @param string $SignMethod 指定签名算法
     * @param string $Region 地域 ap-guangzhou
     * @return object
     */
    public static function config($secretId, $secretKey, $AppId, $signName = 0, $SignMethod = "TC3-HMAC-SHA256", $Region = "ap-guangzhou")
    {
        if (empty($secretId) || empty($secretKey) || empty($AppId) || empty($signName)) {
            throw new Exception("secretId||secretKey||AppId 为配置文件不能为空");
        }
        $SmsConfig = new Sms();
        $SmsConfig->secretId = $secretId;
        $SmsConfig->secretKey = $secretKey;
        $SmsConfig->AppId = $AppId;
        $SmsConfig->signName = $signName;
        $SmsConfig->SignMethod = $SignMethod;
        $SmsConfig->Region = $Region;
        return $SmsConfig;
    }

    /**
     * Redis配置文件
     * @param string $host 服务器连接地址
     * @param int $port 端口号
     * @param string $password 连接密码
     * @param int $RedisTimeout Redis 过期时间
     * @param int $expire 默认全局过期时间，单位秒。
     * @param int $db 缓存库选择
     * @param int $timeout 连接超时时间/秒
     * @return object
     */
    public function RedisConfig($host = '127.0.0.1', $port = 6379, $password = "", $RedisTimeout = 300, $expire = 3600, $db = 0, $timeout = 10)
    {
        $config = [
            'host' => $host,
            'port' => $port,
            'expire' => $expire,
            'password' => $password,
            'db' => $db,
            'timeout' => $timeout
        ];
        $this->Redis = $config;
        $this->RedisTimeout = $RedisTimeout;
        return $this;
    }

    /**
     * 短信发送模板和传参
     * @param int $templateId 模板号
     * @param array $param 短信参数
     * @return object
     * */
    public function content($templateId, $param)
    {
        try {
            if (!is_array($param)) {
                throw new Exception("param 短信参数必须为数组");
            }
            if (empty($templateId)) {
                throw new Exception("templateId 短信模板不能为空");
            }

        } catch (Exception $e) {
            echo "捕获到异常:" . $e->getMessage();
        }

        $this->templateId = $templateId;
        $this->param = $param;
        return $this;
    }

    /**
     * 发送的手机号
     * @param array $mobile 手机号 [18888888888,15555555555] 可批量
     * @return object
     * */
    public function mobile($mobile)
    {
        if (empty($mobile) && is_array($mobile)) {
            throw new Exception("mobile 不能为 null 或者 mobile 数组格式错误");
        }
        foreach ($mobile as $key => $value) {
            if (!preg_match("/^1[3456789]\d{9}$/", $value)) {
                throw new Exception("循环得出 有一个手机号格式错误 必须为中国大陆的手机号");
            }
            $mobile[$key] = "+86" . $value;
        }
        $this->mobile = $mobile;
        return $this;
    }


    /**
     * 发短信
     * @return bool
     **/
    public function get()
    {
        try {
            $cred = new Credential($this->secretId, $this->secretKey);
            $httpProfile = new HttpProfile();
            $httpProfile->setReqMethod($this->Method);
            $httpProfile->setReqTimeout($this->Timeout);
            $httpProfile->setEndpoint($this->Endpoint);
            $clientProfile = new ClientProfile();
            $clientProfile->setSignMethod($this->SignMethod);
            $clientProfile->setHttpProfile($httpProfile);
            $client = new SmsClient($cred, $this->Region, $clientProfile);
            $req = new SendSmsRequest();
            $req->SmsSdkAppId = $this->AppId;
            $req->SignName = $this->signName;
            $req->SmsType = 0;
            $req->ExtendCode = "0";
            $req->PhoneNumberSet = $this->mobile;
            $req->TemplateId = $this->templateId;
            $req->TemplateParamSet = $this->param;
            $client->SendSms($req);
            $this->RedisRecord();
            return true;
        } catch (TencentCloudSDKException $e) {
            return false;
        }
    }

    /**
     * 循环存Redis
     **/
    protected function RedisRecord()
    {
        foreach ($this->mobile as $item) {
            if (!empty($this->Redis)) {
                $redis = new Redis($this->Redis);
                $redisName = $this->RedisIdent . "_" . $this->mobile;
                $isRedis = $redis::get($redisName);
                if (!empty($isRedis)) {
                    $arrayIsRedis = json_decode($isRedis, 1);
                    $arrayIsRedis[$this->templateId] = $this->param;
                    $redis::setnx($redisName, json_encode($arrayIsRedis, JSON_UNESCAPED_UNICODE), $this->RedisTimeout);
                }
            }
        }
    }
}
