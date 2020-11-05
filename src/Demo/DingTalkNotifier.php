<?php

namespace Sayhey\Jobs\Demo;

use Sayhey\Jobs\Interfaces\NotifierInterface;

/**
 * 钉钉机器人通知
 * 
 */
class DingTalkNotifier implements NotifierInterface
{

    private $api = 'https://oapi.dingtalk.com/robot/send?access_token=';
    private $prefix = '';
    private $token = '';

    /**
     * 构造方法
     * @param array $params
     */
    public function __construct(array $params)
    {
        if (!empty($params['token']) && is_string($params['token'])) {
            $this->token = $params['token'];
        }
        if (!empty($params['prefix']) && is_string($params['prefix'])) {
            $this->prefix = $params['prefix'];
        }

        $this->api .= $this->token;
    }

    /**
     * 发送消息
     * @param string $msg
     */
    public function send($msg)
    {
        if (!$this->token) {
            return false;
        }

        $data = [
            'msgtype' => 'text',
            'text' => ['content' => $this->prefix . '#' . $msg]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=utf-8'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $ret = curl_exec($ch);
        curl_close($ch);

        return $ret;
    }

}
