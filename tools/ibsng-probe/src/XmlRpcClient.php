<?php
/**
 * Minimal, dependency-free XML-RPC client for IBSng A1.24.
 *
 * Why not ext-xmlrpc? It was removed from PHP core in PHP 8.0 and is not
 * available on most cPanel hosts. This class needs only ext-dom (enabled on
 * virtually every cPanel PHP build) and either ext-curl or allow_url_fopen.
 *
 * Wire facts verified from the IBSng A1.24 source (core/server/xmlrpcserver.py):
 *  - HTTP POST to path "/" on IBS_SERVER_PORT (default 1235), plain HTTP.
 *  - Every call takes exactly one struct param containing auth_name, auth_pass,
 *    auth_type plus the method's own arguments.
 *  - Application errors come back as XML-RPC <fault> with faultCode 1.
 *  - Unexpected server errors come back as HTTP 500 with an empty body.
 */

declare(strict_types=1);

namespace IbsProbe;

final class XmlRpcFault extends \RuntimeException {}
final class XmlRpcTransportError extends \RuntimeException {}

final class XmlRpcClient
{
    private string $url;
    private float $timeout;
    private float $connectTimeout;

    /** @var array<string,mixed>|null last request/response metadata (no secrets) */
    public ?array $lastMeta = null;

    public function __construct(string $host, int $port = 1235, string $path = '/', float $timeout = 15.0, float $connectTimeout = 5.0)
    {
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            throw new \InvalidArgumentException('Invalid host');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid port');
        }
        $this->url = 'http://' . $host . ':' . $port . ($path === '' ? '/' : $path);
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Call a remote method. $params is sent as the single struct parameter.
     * @param array<string,mixed> $params
     * @return mixed decoded result
     */
    public function call(string $method, array $params)
    {
        $xml = self::encodeRequest($method, [(object) $params]);
        $start = microtime(true);
        [$status, $body] = $this->post($xml);
        $this->lastMeta = [
            'method' => $method,
            'http_status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'response_bytes' => strlen($body),
        ];
        if ($status !== 200) {
            throw new XmlRpcTransportError("HTTP status $status from IBSng (server-side exception; see IBSng log)");
        }
        return self::decodeResponse($body);
    }

    /** @return array{0:int,1:string} */
    private function post(string $xml): array
    {
        $headers = ['Content-Type: text/xml', 'User-Agent: ibs-probe/1.0', 'Content-Length: ' . strlen($xml)];
        if (function_exists('curl_init')) {
            $ch = curl_init($this->url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xml,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '', // never route IBSng traffic through an env proxy
            ]);
            $body = curl_exec($ch);
            if ($body === false) {
                $err = curl_error($ch);
                $errno = curl_errno($ch);
                curl_close($ch);
                throw new XmlRpcTransportError("Transport error ($errno): $err");
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return [$status, (string) $body];
        }
        if (!ini_get('allow_url_fopen')) {
            throw new XmlRpcTransportError('Neither ext-curl nor allow_url_fopen is available');
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $xml,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents($this->url, false, $ctx);
        if ($body === false) {
            $e = error_get_last();
            throw new XmlRpcTransportError('Transport error: ' . ($e['message'] ?? 'unknown'));
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return [$status, $body];
    }

    // ------------------------------------------------------------------ encoding

    /** @param list<mixed> $params */
    public static function encodeRequest(string $method, array $params): string
    {
        if (!preg_match('/^[A-Za-z0-9_.]+$/', $method)) {
            throw new \InvalidArgumentException('Invalid method name');
        }
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<methodCall><methodName>" . $method . '</methodName><params>';
        foreach ($params as $p) {
            $out .= '<param>' . self::encodeValue($p) . '</param>';
        }
        return $out . '</params></methodCall>';
    }

    /**
     * PHP list            -> <array>
     * PHP assoc array / object (incl. empty stdClass) -> <struct>
     * Empty PHP array     -> <array> (pass (object)[] when an empty struct is needed)
     */
    public static function encodeValue($v): string
    {
        if ($v === null) {
            // IBSng (Python 2.4 xmlrpclib, allow_none=False) cannot accept <nil/>.
            throw new \InvalidArgumentException('NULL values are not supported by IBSng XML-RPC');
        }
        if (is_bool($v)) {
            return '<value><boolean>' . ($v ? '1' : '0') . '</boolean></value>';
        }
        if (is_int($v)) {
            if ($v > 2147483647 || $v < -2147483648) {
                // XML-RPC int is 32-bit; send large numbers as double to stay spec-compliant.
                return '<value><double>' . $v . '</double></value>';
            }
            return '<value><int>' . $v . '</int></value>';
        }
        if (is_float($v)) {
            if (!is_finite($v)) {
                throw new \InvalidArgumentException('Non-finite float');
            }
            return '<value><double>' . rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') . '</double></value>';
        }
        if (is_string($v)) {
            if (!mb_check_encoding($v, 'UTF-8')) {
                throw new \InvalidArgumentException('String is not valid UTF-8');
            }
            return '<value><string>' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string></value>';
        }
        if (is_object($v)) {
            $v = get_object_vars($v);
            return self::encodeStruct($v);
        }
        if (is_array($v)) {
            if ($v === [] || array_is_list($v)) {
                $out = '<value><array><data>';
                foreach ($v as $item) {
                    $out .= self::encodeValue($item);
                }
                return $out . '</data></array></value>';
            }
            return self::encodeStruct($v);
        }
        throw new \InvalidArgumentException('Unsupported type: ' . gettype($v));
    }

    /** @param array<array-key,mixed> $v */
    private static function encodeStruct(array $v): string
    {
        $out = '<value><struct>';
        foreach ($v as $k => $item) {
            $out .= '<member><name>' . htmlspecialchars((string) $k, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</name>'
                . self::encodeValue($item) . '</member>';
        }
        return $out . '</struct></value>';
    }

    // ------------------------------------------------------------------ decoding

    /** @return mixed */
    public static function decodeResponse(string $xml)
    {
        if (trim($xml) === '') {
            throw new XmlRpcTransportError('Empty response body');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // LIBXML_NONET: no network access; no LIBXML_NOENT: entities are not substituted (XXE-safe).
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->documentElement === null || $doc->documentElement->nodeName !== 'methodResponse') {
            throw new XmlRpcTransportError('Response is not a valid XML-RPC methodResponse');
        }
        if ($doc->doctype !== null) {
            throw new XmlRpcTransportError('DOCTYPE not allowed in response');
        }
        $root = $doc->documentElement;
        $fault = self::firstChildElement($root, 'fault');
        if ($fault !== null) {
            $val = self::decodeValueNode(self::requireChild($fault, 'value'));
            $code = is_array($val) ? (int) ($val['faultCode'] ?? 0) : 0;
            $msg = is_array($val) ? (string) ($val['faultString'] ?? '') : '';
            throw new XmlRpcFault($msg, $code);
        }
        $params = self::requireChild($root, 'params');
        $param = self::requireChild($params, 'param');
        return self::decodeValueNode(self::requireChild($param, 'value'));
    }

    /** @return mixed */
    private static function decodeValueNode(\DOMElement $value)
    {
        $typed = null;
        foreach ($value->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                $typed = $c;
                break;
            }
        }
        if ($typed === null) {
            return $value->textContent; // untyped value == string
        }
        $t = $typed->textContent;
        switch ($typed->nodeName) {
            case 'string':
                return $t;
            case 'int':
            case 'i4':
            case 'i8':
                return (int) trim($t);
            case 'boolean':
                return trim($t) === '1';
            case 'double':
                return (float) trim($t);
            case 'nil':
                return null;
            case 'base64':
                return base64_decode(trim($t), true);
            case 'dateTime.iso8601':
                return trim($t);
            case 'array':
                $out = [];
                $data = self::requireChild($typed, 'data');
                foreach ($data->childNodes as $c) {
                    if ($c instanceof \DOMElement && $c->nodeName === 'value') {
                        $out[] = self::decodeValueNode($c);
                    }
                }
                return $out;
            case 'struct':
                $out = [];
                foreach ($typed->childNodes as $m) {
                    if ($m instanceof \DOMElement && $m->nodeName === 'member') {
                        $name = self::requireChild($m, 'name')->textContent;
                        $out[$name] = self::decodeValueNode(self::requireChild($m, 'value'));
                    }
                }
                return $out;
        }
        throw new XmlRpcTransportError('Unknown XML-RPC type: ' . $typed->nodeName);
    }

    private static function firstChildElement(\DOMElement $el, string $name): ?\DOMElement
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->nodeName === $name) {
                return $c;
            }
        }
        return null;
    }

    private static function requireChild(\DOMElement $el, string $name): \DOMElement
    {
        $c = self::firstChildElement($el, $name);
        if ($c === null) {
            throw new XmlRpcTransportError("Malformed XML-RPC: <$name> missing inside <{$el->nodeName}>");
        }
        return $c;
    }
}
