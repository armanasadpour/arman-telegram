<?php
declare(strict_types=1);

final class Browser
{
    public array $jar = [];
    public array $last = [];
    public function __construct(private string $base) {}

    public function req(string $method, string $path, array $form = [], array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $h = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36'];
        if ($this->jar) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->jar), $this->jar));
        }
        foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => $h, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
        if ($method === 'POST') {
            if (!isset($headers['Origin'])) { $h[] = 'Origin: ' . $this->base; curl_setopt($ch, CURLOPT_HTTPHEADER, $h); }
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $raw = (string)curl_exec($ch);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $head = substr($raw, 0, $hs);
        $body = substr($raw, $hs);
        $hdrs = [];
        $cookies = [];
        foreach (explode("\r\n", $head) as $line) {
            if (!str_contains($line, ':')) { continue; }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $hdrs[strtolower($k)][] = $v;
            if (strtolower($k) === 'set-cookie') {
                $cookies[] = $v;
                [$nv] = explode(';', $v, 2);
                [$n, $val] = explode('=', $nv, 2);
                if (str_contains($v, 'Max-Age=0')) { unset($this->jar[$n]); } else { $this->jar[$n] = $val; }
            }
        }
        return $this->last = compact('status', 'body', 'hdrs', 'cookies');
    }

    public function get(string $p): array { return $this->req('GET', $p); }

    /** Fetches the page to get a CSRF token then posts the form. */
    public function post(string $p, array $form, ?string $tokenFrom = null): array
    {
        if (!isset($this->jar['__Host-csrf'])) { $this->get($tokenFrom ?? ('/' . explode('/', ltrim($p, '/'))[0] . '/')); }
        return $this->req('POST', $p, $form + ['_csrf' => $this->jar['__Host-csrf'] ?? '']);
    }

    public function follow(): array
    {
        $loc = $this->last['hdrs']['location'][0] ?? null;
        if ($loc === null) { throw new RuntimeException('no redirect, status ' . $this->last['status']); }
        return $this->get(preg_replace('#^https?://[^/]+#', '', $loc));
    }
}

$pass = 0; $fail = 0;
function check(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✔\033[0m $name\n"; } else { $fail++; echo "  \033[31m✘ $name\033[0m $extra\n"; }
}
function between(string $s, string $a, string $b): string { $i = strpos($s, $a); if ($i === false) return ''; $i += strlen($a); return substr($s, $i, (int)strpos($s, $b, $i) - $i); }
function secret(string $html): string { return html_entity_decode(trim(strip_tags(between($html, '<div class="secret">', '</div>')))); }
function lastOtp(string $root): string { $l = trim((string)@file_get_contents("$root/storage/logs/sms-test.log")); $rows = explode("\n", $l); return explode("\t", end($rows))[3] ?? ''; }


function finish(): void { global $pass, $fail; echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0); }
