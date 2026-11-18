<?php
/**
 * Unit tests for the XML-RPC codec. Run: php tests/CodecTest.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/XmlRpcClient.php';

use IbsProbe\XmlRpcClient as C;
use IbsProbe\XmlRpcFault;
use IbsProbe\XmlRpcTransportError;

$fails = 0;
$n = 0;
function check(string $name, bool $cond): void
{
    global $fails, $n;
    $n++;
    echo ($cond ? "ok   " : "FAIL ") . $name . "\n";
    if (!$cond) {
        $fails++;
    }
}
function throws(callable $f, string $class): bool
{
    try {
        $f();
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

// --- encoding
$x = C::encodeRequest('user.getUserInfo', [(object) ['auth_name' => 'a', 'normal_username' => 'علی<&>']]);
check('request has methodName', str_contains($x, '<methodName>user.getUserInfo</methodName>'));
check('struct encoded', str_contains($x, '<struct><member><name>auth_name</name>'));
check('xml special chars escaped', str_contains($x, 'علی&lt;&amp;&gt;'));
check('empty stdClass -> empty struct', C::encodeValue(new stdClass()) === '<value><struct></struct></value>');
check('empty array -> empty array', C::encodeValue([]) === '<value><array><data></data></array></value>');
check('list -> array', str_contains(C::encodeValue([1, 2]), '<array><data><value><int>1</int>'));
check('bool true', C::encodeValue(true) === '<value><boolean>1</boolean></value>');
check('float', C::encodeValue(1.5) === '<value><double>1.5</double></value>');
check('float zero', C::encodeValue(0.0) === '<value><double>0</double></value>');
check('small float precision', C::encodeValue(0.0009765625) === '<value><double>0.0009765625</double></value>');
check('int64 -> double', str_contains(C::encodeValue(5000000000), '<double>'));
check('null rejected', throws(fn () => C::encodeValue(null), InvalidArgumentException::class));
check('invalid utf8 rejected', throws(fn () => C::encodeValue("\xff\xfe"), InvalidArgumentException::class));
check('bad method rejected', throws(fn () => C::encodeRequest('a b', []), InvalidArgumentException::class));
check('NaN rejected', throws(fn () => C::encodeValue(NAN), InvalidArgumentException::class));

// --- decoding (samples shaped like Python 2 xmlrpclib output)
$resp = "<?xml version='1.0'?>\n<methodResponse>\n<params>\n<param>\n<value><struct>\n<member>\n<name>1000</name>\n<value><struct>\n"
    . "<member><name>basic_info</name><value><struct><member><name>credit</name><value><double>1.5</double></value></member>"
    . "<member><name>online_status</name><value><boolean>0</boolean></value></member></struct></value></member>\n"
    . "<member><name>attrs</name><value><struct><member><name>normal_username</name><value><string>u1</string></value></member>"
    . "<member><name>comment</name><value>untyped</value></member></struct></value></member>\n"
    . "</struct></value>\n</member>\n</struct></value>\n</param>\n</params>\n</methodResponse>\n";
$d = C::decodeResponse($resp);
check('decode nested struct', $d['1000']['basic_info']['credit'] === 1.5);
check('decode boolean', $d['1000']['basic_info']['online_status'] === false);
check('decode untyped as string', $d['1000']['attrs']['comment'] === 'untyped');
check('decode array of int', C::decodeResponse('<methodResponse><params><param><value><array><data><value><int>5</int></value><value><i4>6</i4></value></data></array></value></param></params></methodResponse>') === [5, 6]);
check('decode utf8', C::decodeResponse('<?xml version="1.0" encoding="utf-8"?><methodResponse><params><param><value><string>گروه</string></value></param></params></methodResponse>') === 'گروه');

$fault = '<methodResponse><fault><value><struct><member><name>faultCode</name><value><int>1</int></value></member>'
    . '<member><name>faultString</name><value><string>Invalid Username or Password</string></value></member></struct></value></fault></methodResponse>';
try {
    C::decodeResponse($fault);
    check('fault raised', false);
} catch (XmlRpcFault $f) {
    check('fault raised', $f->getCode() === 1 && $f->getMessage() === 'Invalid Username or Password');
}
check('empty body -> transport error', throws(fn () => C::decodeResponse(''), XmlRpcTransportError::class));
check('garbage -> transport error', throws(fn () => C::decodeResponse('<html>500</html>'), XmlRpcTransportError::class));
$xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><methodResponse><params><param><value><string>&x;</string></value></param></params></methodResponse>';
check('DOCTYPE/XXE rejected', throws(fn () => C::decodeResponse($xxe), XmlRpcTransportError::class));

// --- round trip through own encoder
$req = C::encodeRequest('x.y', [(object) ['a' => [1, 'b', ['k' => true]], 'f' => 2.25]]);
$asResp = str_replace(['<methodCall><methodName>x.y</methodName>', '</methodCall>'], ['<methodResponse>', '</methodResponse>'], $req);
check('round trip', C::decodeResponse($asResp) === ['a' => [1, 'b', ['k' => true]], 'f' => 2.25]);

echo "\n$n tests, $fails failures\n";
exit($fails ? 1 : 0);
