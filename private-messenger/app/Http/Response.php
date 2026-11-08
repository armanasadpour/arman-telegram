<?php
declare(strict_types=1);

namespace PM\Http;

final class Response
{
    /** @var array<string, string> */
    public array $headers = [];
    /** @var list<string> */
    private array $cookies = [];

    public function __construct(public string $body = '', public int $status = 200, array $headers = [])
    {
        foreach ($headers as $k => $v) {
            $this->headers[$k] = $v;
        }
    }

    /**
     * The single, uniform "nothing here" response. Mimics Apache's default 404 page so that
     * the root of the domain and every unknown path look like an empty host.
     */
    public static function notFound(): self
    {
        $body = "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n<title>404 Not Found</title>\n"
            . "</head><body>\n<h1>Not Found</h1>\n<p>The requested URL was not found on this server.</p>\n</body></html>\n";
        return new self($body, 404, ['Content-Type' => 'text/html; charset=iso-8859-1']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function error(string $message = 'خطای داخلی', int $status = 500): self
    {
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return self::html("<!doctype html><html lang=\"fa\" dir=\"rtl\"><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex\"><title>خطا</title><p>{$msg}</p></html>", $status);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * @param array{expires?:int, httponly?:bool, samesite?:string} $opts
     * Cookies use the __Host- prefix: Secure, Path=/, no Domain.
     */
    public function cookie(string $name, string $value, array $opts = []): self
    {
        $parts = [$name . '=' . rawurlencode($value), 'Path=/', 'Secure'];
        if (($opts['httponly'] ?? true) === true) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=' . ($opts['samesite'] ?? 'Strict');
        if (isset($opts['expires'])) {
            $parts[] = 'Max-Age=' . max(0, $opts['expires'] - time());
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $opts['expires']) . ' GMT';
        }
        $this->cookies[] = implode('; ', $parts);
        return $this;
    }

    public function forgetCookie(string $name): self
    {
        $this->cookies[] = $name . '=; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        return $this;
    }

    /** @return list<string> */
    public function cookieHeaders(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
            foreach ($this->cookies as $c) {
                header('Set-Cookie: ' . $c, false);
            }
        }
        echo $this->body;
    }
}
