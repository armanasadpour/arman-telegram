<?php

namespace App\Ibs;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Dependency-free XML-RPC encoder/decoder (ext-xmlrpc no longer exists in PHP 8).
 * Proven against the production IBSng in phase 1. Also used by the test fake server.
 */
final class XmlRpcCodec
{
    /** @param list<mixed> $params */
    public static function encodeRequest(string $method, array $params): string
    {
        if (! preg_match('/^[A-Za-z0-9_.]+$/', $method)) {
            throw new InvalidArgumentException('Invalid method name');
        }
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n<methodCall><methodName>$method</methodName><params>";
        foreach ($params as $p) {
            $out .= '<param>'.self::encodeValue($p).'</param>';
        }

        return $out.'</params></methodCall>';
    }

    public static function encodeResponse(mixed $value): string
    {
        return '<?xml version="1.0"?>'."\n<methodResponse><params><param>".self::encodeValue($value ?? '').'</param></params></methodResponse>';
    }

    public static function encodeFault(int $code, string $message): string
    {
        return '<?xml version="1.0"?>'."\n<methodResponse><fault>".self::encodeValue(['faultCode' => $code, 'faultString' => $message]).'</fault></methodResponse>';
    }

    /**
     * list -> <array>; assoc array / object -> <struct>; [] -> <array> (pass (object) [] for an empty struct).
     * null is rejected: IBSng (Python 2 xmlrpclib, allow_none=False) cannot accept <nil/>.
     */
    public static function encodeValue(mixed $v): string
    {
        return match (true) {
            $v === null => throw new InvalidArgumentException('NULL is not supported by IBSng XML-RPC'),
            is_bool($v) => '<value><boolean>'.($v ? '1' : '0').'</boolean></value>',
            is_int($v) => ($v > 2147483647 || $v < -2147483648) ? '<value><double>'.$v.'</double></value>' : '<value><int>'.$v.'</int></value>',
            is_float($v) => is_finite($v) ? '<value><double>'.rtrim(rtrim(sprintf('%.12F', $v), '0'), '.').'</double></value>' : throw new InvalidArgumentException('Non-finite float'),
            is_string($v) => mb_check_encoding($v, 'UTF-8')
                ? '<value><string>'.htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</string></value>'
                : throw new InvalidArgumentException('String is not valid UTF-8'),
            is_object($v) => self::encodeStruct(get_object_vars($v)),
            is_array($v) => ($v === [] || array_is_list($v))
                ? '<value><array><data>'.implode('', array_map([self::class, 'encodeValue'], $v)).'</data></array></value>'
                : self::encodeStruct($v),
            default => throw new InvalidArgumentException('Unsupported type '.gettype($v)),
        };
    }

    /** @param array<array-key,mixed> $v */
    private static function encodeStruct(array $v): string
    {
        $out = '<value><struct>';
        foreach ($v as $k => $item) {
            $out .= '<member><name>'.htmlspecialchars((string) $k, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</name>'.self::encodeValue($item).'</member>';
        }

        return $out.'</struct></value>';
    }

    /** @return mixed decoded value; throws IbsException on fault, IbsUnavailableException on garbage */
    public static function decodeResponse(string $xml): mixed
    {
        $root = self::load($xml, 'methodResponse');
        if ($fault = self::child($root, 'fault')) {
            $f = self::decodeValue(self::req($fault, 'value'));
            $raw = is_array($f) ? (string) ($f['faultString'] ?? '') : '';
            [$code, $msg] = str_contains($raw, '|') ? explode('|', $raw, 2) : ['UNKNOWN', $raw];

            throw new IbsException(trim($code), trim($msg), $raw);
        }

        return self::decodeValue(self::req(self::req(self::req($root, 'params'), 'param'), 'value'));
    }

    /** @return array{0:string,1:list<mixed>} [method, params] — used by the fake server */
    public static function decodeRequest(string $xml): array
    {
        $root = self::load($xml, 'methodCall');
        $method = trim(self::req($root, 'methodName')->textContent);
        $params = [];
        if ($ps = self::child($root, 'params')) {
            foreach ($ps->childNodes as $p) {
                if ($p instanceof DOMElement && $p->nodeName === 'param') {
                    $params[] = self::decodeValue(self::req($p, 'value'));
                }
            }
        }

        return [$method, $params];
    }

    private static function load(string $xml, string $rootName): DOMElement
    {
        if (trim($xml) === '') {
            throw new IbsUnavailableException('Empty response from IBSng');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok || $doc->doctype !== null || $doc->documentElement?->nodeName !== $rootName) {
            throw new IbsUnavailableException('Invalid XML-RPC '.$rootName);
        }

        return $doc->documentElement;
    }

    private static function decodeValue(DOMElement $value): mixed
    {
        $typed = null;
        foreach ($value->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $typed = $c;
                break;
            }
        }
        if ($typed === null) {
            return $value->textContent;
        }
        $t = $typed->textContent;

        switch ($typed->nodeName) {
            case 'string': return $t;
            case 'int': case 'i4': case 'i8': return (int) trim($t);
            case 'boolean': return trim($t) === '1';
            case 'double': return (float) trim($t);
            case 'nil': return null;
            case 'base64': return base64_decode(trim($t), true);
            case 'dateTime.iso8601': return trim($t);
            case 'array':
                $out = [];
                foreach (self::req($typed, 'data')->childNodes as $c) {
                    if ($c instanceof DOMElement && $c->nodeName === 'value') {
                        $out[] = self::decodeValue($c);
                    }
                }

                return $out;
            case 'struct':
                $out = [];
                foreach ($typed->childNodes as $m) {
                    if ($m instanceof DOMElement && $m->nodeName === 'member') {
                        $out[self::req($m, 'name')->textContent] = self::decodeValue(self::req($m, 'value'));
                    }
                }

                return $out;
        }

        throw new IbsUnavailableException('Unknown XML-RPC type '.$typed->nodeName);
    }

    private static function child(DOMElement $el, string $name): ?DOMElement
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && $c->nodeName === $name) {
                return $c;
            }
        }

        return null;
    }

    private static function req(DOMElement $el, string $name): DOMElement
    {
        return self::child($el, $name) ?? throw new IbsUnavailableException("Malformed XML-RPC: <$name> missing");
    }
}
