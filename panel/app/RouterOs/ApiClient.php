<?php

namespace App\RouterOs;

/**
 * RouterOS classic API (TCP 8728, or TLS 8729 "api-ssl"): length-prefixed words, sentences end with an
 * empty word; replies !re (row) … !done, or !trap / !fatal on error. Login (RouterOS ≥ 6.43):
 * "/login =name= =password=". With api-ssl the certificate public key must match the pin.
 */
class ApiClient implements Client
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(private string $host, private int $port, private string $username, private string $password,
        private bool $tls, private ?string $pin, private int $timeout = 12) {}

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }

    public function get(string $path, array $query = []): array
    {
        $words = ['/'.Paths::check($path).'/print'];
        foreach ($query as $k => $v) {
            $words[] = '?'.$k.'='.$v;
        }

        return $this->command($words);
    }

    /** @param list<string> $words @return list<array<string,string>> */
    public function command(array $words): array
    {
        $this->connect();
        $this->writeSentence($words);
        $rows = [];
        $error = null;
        while (true) {
            $s = $this->readSentence();
            $type = array_shift($s);
            $attrs = self::attrs($s);
            if ($type === '!re') {
                $rows[] = $attrs;
            } elseif ($type === '!trap') {
                $error = $attrs['message'] ?? 'خطا';
            } elseif ($type === '!fatal') {
                throw new RouterOsException('روتر اتصال را بست: '.($attrs['message'] ?? implode(' ', $s)));
            } elseif ($type === '!done') {
                break;
            }
        }
        if ($error !== null) {
            throw new RouterOsException('روتر خطا داد: '.mb_substr($error, 0, 200));
        }

        return $rows;
    }

    private function connect(): void
    {
        if (is_resource($this->sock)) {
            return;
        }
        if ($this->tls && ! preg_match('#^sha256//[A-Za-z0-9+/=]{43,44}$#', (string) $this->pin)) {
            throw new RouterOsException('اثر انگشت گواهی روتر ثبت نشده است؛ ابتدا «دریافت اثر انگشت» را بزنید.');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true, 'SNI_enabled' => true]]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(($this->tls ? 'tls' : 'tcp').'://'.$this->host.':'.$this->port, $errno, $errstr, min(6, $this->timeout), STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            throw new RouterOsUnreachable(RestClient::network($errstr !== '' ? $errstr : 'error '.$errno));
        }
        if ($this->tls) {
            $cert = stream_context_get_params($sock)['options']['ssl']['peer_certificate'] ?? null;
            if (! $cert || ! hash_equals((string) $this->pin, Pin::fromCertificate($cert))) {
                fclose($sock);
                throw new RouterOsUnreachable('گواهی روتر با اثر انگشت ثبت‌شده مطابقت ندارد (گواهی عوض شده یا اتصال شنود می‌شود).');
            }
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        try {
            $this->writeSentence(['/login', '=name='.$this->username, '=password='.$this->password]);
            $s = $this->readSentence();
            if (($s[0] ?? '') !== '!done') {
                // drain until !done
                while (($s[0] ?? '') !== '!done' && ($s[0] ?? '') !== '!fatal') {
                    $s = $this->readSentence();
                }
                throw new RouterOsException('نام کاربری یا رمز میکروتیک اشتباه است، یا کاربر دسترسی api ندارد.');
            }
        } catch (RouterOsException $e) {
            @fclose($this->sock);
            $this->sock = null;
            throw $e;
        }
    }

    /** @param list<string> $words */
    private function writeSentence(array $words): void
    {
        $buf = '';
        foreach ($words as $w) {
            $buf .= self::encodeLength(strlen($w)).$w;
        }
        $buf .= "\0";
        if (@fwrite($this->sock, $buf) === false) {
            throw new RouterOsUnreachable('ارسال به روتر ممکن نشد.');
        }
    }

    /** @return list<string> */
    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $len = $this->readLength();
            if ($len === 0) {
                return $words;
            }
            $words[] = $this->readBytes($len);
        }
    }

    private function readLength(): int
    {
        $c = ord($this->readBytes(1));
        if ($c < 0x80) {
            return $c;
        }
        if ($c < 0xC0) {
            return (($c & 0x3F) << 8) | ord($this->readBytes(1));
        }
        if ($c < 0xE0) {
            $b = $this->readBytes(2);

            return (($c & 0x1F) << 16) | (ord($b[0]) << 8) | ord($b[1]);
        }
        if ($c < 0xF0) {
            $b = $this->readBytes(3);

            return (($c & 0x0F) << 24) | (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
        }

        return unpack('N', $this->readBytes(4))[1];
    }

    private function readBytes(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $chunk = fread($this->sock, $n - strlen($out));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                throw new RouterOsUnreachable(($meta['timed_out'] ?? false) ? 'روتر در زمان مقرر پاسخ نداد.' : 'اتصال با روتر قطع شد.');
            }
            $out .= $chunk;
        }

        return $out;
    }

    public static function encodeLength(int $len): string
    {
        return match (true) {
            $len < 0x80 => chr($len),
            $len < 0x4000 => pack('n', $len | 0x8000),
            $len < 0x200000 => substr(pack('N', $len | 0xC00000), 1),
            $len < 0x10000000 => pack('N', $len | 0xE0000000),
            default => "\xF0".pack('N', $len),
        };
    }

    /** @param list<string> $words @return array<string,string> */
    public static function attrs(array $words): array
    {
        $out = [];
        foreach ($words as $w) {
            if (str_starts_with($w, '=')) {
                $pos = strpos($w, '=', 1);
                if ($pos !== false) {
                    $out[substr($w, 1, $pos - 1)] = substr($w, $pos + 1);
                }
            }
        }

        return $out;
    }
}
