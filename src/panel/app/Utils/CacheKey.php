<?php

namespace App\Utils;

class CacheKey
{
    CONST KEYS = [
        'EMAIL_VERIFY_CODE' => '邮箱验证码',
        'LAST_SEND_EMAIL_VERIFY_TIMESTAMP' => '最后一次发送邮箱验证码时间',
        'SERVER_VMESS_ONLINE_USER' => '节点在线用户',
        'MULTI_SERVER_VMESS_ONLINE_USER' => '节点多服务器在线用户',
        'SERVER_VMESS_LAST_CHECK_AT' => '节点最后检查时间',
        'SERVER_VMESS_LAST_PUSH_AT' => '节点最后推送时间',
        'SERVER_VMESS_LOAD_STATUS' => '节点负载状态',
        'SERVER_VMESS_LAST_LOAD_AT' => '节点最后负载上报时间',
        'SERVER_VMESS_METRICS' => '节点运行指标',
        'SERVER_TROJAN_ONLINE_USER' => 'trojan节点在线用户',
        'MULTI_SERVER_TROJAN_ONLINE_USER' => 'trojan节点多服务器在线用户',
        'SERVER_TROJAN_LAST_CHECK_AT' => 'trojan节点最后检查时间',
        'SERVER_TROJAN_LAST_PUSH_AT' => 'trojan节点最后推送时间',
        'SERVER_TROJAN_LOAD_STATUS' => 'trojan节点负载状态',
        'SERVER_TROJAN_LAST_LOAD_AT' => 'trojan节点最后负载上报时间',
        'SERVER_TROJAN_METRICS' => 'trojan节点运行指标',
        'SERVER_SHADOWSOCKS_ONLINE_USER' => 'ss节点在线用户',
        'MULTI_SERVER_SHADOWSOCKS_ONLINE_USER' => 'ss节点多服务器在线用户',
        'SERVER_SHADOWSOCKS_LAST_CHECK_AT' => 'ss节点最后检查时间',
        'SERVER_SHADOWSOCKS_LAST_PUSH_AT' => 'ss节点最后推送时间',
        'SERVER_SHADOWSOCKS_LOAD_STATUS' => 'ss节点负载状态',
        'SERVER_SHADOWSOCKS_LAST_LOAD_AT' => 'ss节点最后负载上报时间',
        'SERVER_SHADOWSOCKS_METRICS' => 'ss节点运行指标',
        'SERVER_HYSTERIA_ONLINE_USER' => 'hysteria节点在线用户',
        'MULTI_SERVER_HYSTERIA_ONLINE_USER' => 'hysteria节点多服务器在线用户',
        'SERVER_HYSTERIA_LAST_CHECK_AT' => 'hysteria节点最后检查时间',
        'SERVER_HYSTERIA_LAST_PUSH_AT' => 'hysteria节点最后推送时间',
        'SERVER_HYSTERIA_LOAD_STATUS' => 'hysteria节点负载状态',
        'SERVER_HYSTERIA_LAST_LOAD_AT' => 'hysteria节点最后负载上报时间',
        'SERVER_HYSTERIA_METRICS' => 'hysteria节点运行指标',
        'SERVER_VLESS_ONLINE_USER' => 'vless节点在线用户',
        'MULTI_SERVER_VLESS_ONLINE_USER' => 'vless节点多服务器在线用户',
        'SERVER_VLESS_LAST_CHECK_AT' => 'vless节点最后检查时间',
        'SERVER_VLESS_LAST_PUSH_AT' => 'vless节点最后推送时间',
        'SERVER_VLESS_LOAD_STATUS' => 'vless节点负载状态',
        'SERVER_VLESS_LAST_LOAD_AT' => 'vless节点最后负载上报时间',
        'SERVER_VLESS_METRICS' => 'vless节点运行指标',
        'TEMP_TOKEN' => '临时令牌',
        'LAST_SEND_EMAIL_REMIND_TRAFFIC' => '最后发送流量邮件提醒',
        'SCHEDULE_LAST_CHECK_AT' => '计划任务最后检查时间',
        'REGISTER_IP_RATE_LIMIT' => '注册频率限制',
        'LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP' => '最后一次发送登入链接时间',
        'PASSWORD_ERROR_LIMIT' => '密码错误次数限制',
        'USER_SESSIONS' => '用户session',
        'FORGET_REQUEST_LIMIT' => '找回密码次数限制'
    ];

    public static function get(string $key, $uniqueValue)
    {
        if (!in_array($key, array_keys(self::KEYS))) {
            abort(500, 'key is not in cache key list');
        }
        return $key . '_' . $uniqueValue;
    }
}
