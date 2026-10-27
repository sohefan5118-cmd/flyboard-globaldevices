<?php
namespace App\Services;

/** Bounded subscription retrieval; no redirects, proxies, or node reachability probes. */
class FlyboardUpstreamFetcher
{
    public function fetch(string $url, string $ua = '', int $timeout = 30, int $maxBytes = 524288): array
    {
        // A separate CLI process isolates blocking resolver/libcurl code from Octane
        // and lets Symfony Process enforce a wall-clock deadline including DNS.
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, __DIR__.'/FlyboardTransport.php']);
        $process->setInput(json_encode(['url'=>$url,'ua'=>$ua,'timeout'=>$timeout,'maxBytes'=>$maxBytes], JSON_THROW_ON_ERROR));
        $process->setTimeout(max(3,min(30,$timeout))+1);
        try { $process->run(); }
        catch (\Throwable $e) { throw new \RuntimeException('Upstream wall-clock deadline exceeded'); }
        $result=json_decode($process->getOutput(),true);
        if (!$process->isSuccessful() || !is_array($result) || !isset($result['body'])) throw new \RuntimeException('Upstream transport or safety validation failed');
        return $result;
    }

    public function fetchDirect(string $url, string $ua='', int $timeout=30, int $maxBytes=524288): array
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http','https'], true)
            || empty($p['host']) || isset($p['user']) || isset($p['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \RuntimeException('Invalid upstream URL');
        }
        $host = strtolower(trim($p['host'], '[]'));
        $scheme = strtolower($p['scheme']);
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80,443], true)) throw new \RuntimeException('Only standard HTTP/HTTPS upstream ports are allowed');
        if (!preg_match('/^[a-z0-9.-]+$/D', $host) || strlen($host)>253) throw new \RuntimeException('Invalid upstream hostname');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            []
        );
        if (!$ips) throw new \RuntimeException('Upstream DNS resolution failed');
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || !self::publicIpv4($ip) || str_contains($ip, ':') || str_starts_with($ip, '100.64.')
                || (ip2long($ip) !== false && (ip2long($ip) & 0xffc00000) === ip2long('100.64.0.0'))) {
                // IPv6 is deliberately rejected until complete special-purpose range filtering is available.
                throw new \RuntimeException('Upstream must resolve only to public IPv4 addresses');
            }
        }
        $body = ''; $meta = ''; $overflow = false; $headerBytes = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => max(3, min(30, $timeout)),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_USERAGENT => preg_replace('/[\r\n]/', '', substr($ua ?: 'FlyBoard', 0, 512)),
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$overflow, $maxBytes) {
                if (strlen($body) + strlen($chunk) > max(1024, min(2097152, $maxBytes))) { $overflow = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$meta, &$headerBytes) {
                $headerBytes += strlen($line); if ($headerBytes > 65536) return 0;
                if (stripos($line, 'subscription-userinfo:') === 0) $meta = trim(substr($line, 22));
                return strlen($line);
            },
        ]);
        $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($overflow) throw new \RuntimeException('Upstream response exceeds size limit');
        if ($ok === false) throw new \RuntimeException('Upstream transport failed');
        if ($status < 200 || $status >= 300) throw new \RuntimeException('Upstream HTTP status ' . $status . '; redirects are not followed');
        return ['body' => $body, 'meta' => $meta];
    }
    public static function publicIpv4(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) return false;
        $n=ip2long($ip);
        foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $cidr) {
            [$net,$bits]=explode('/',$cidr); $mask=(-1 << (32-(int)$bits));
            if (($n & $mask)===(ip2long($net)&$mask)) return false;
        }
        return true;
    }
}
