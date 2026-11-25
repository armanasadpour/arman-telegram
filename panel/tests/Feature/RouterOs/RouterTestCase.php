<?php

namespace Tests\Feature\RouterOs;

use App\RouterOs\Pin;
use App\RouterOs\RestTransport;
use Tests\Fakes\FakeRestTransport;
use Tests\TestCase;

abstract class RouterTestCase extends TestCase
{
    /** @var list<resource> */
    private array $procs = [];

    /** A RouterOS 7.7 that is ready for L2TP and WireGuard. */
    protected function menus(): array
    {
        return [
            'system/resource' => [['version' => '7.7 (stable)', 'board-name' => 'x86', 'architecture-name' => 'x86_64', 'cpu-count' => '4', 'cpu-load' => '12',
                'free-memory' => '157286400', 'total-memory' => '1006632960', 'uptime' => '3w2d4h']],
            'system/identity' => [['name' => 'ir11']],
            'system/license' => [['nlevel' => '6', 'software-id' => 'ABCD-1234']],
            'ip/service' => [['name' => 'www-ssl', 'port' => '443', 'disabled' => 'false', 'address' => '5.6.7.8/32'], ['name' => 'api-ssl', 'port' => '8729', 'disabled' => 'false', 'address' => '5.6.7.8/32'],
                ['name' => 'api', 'port' => '8728', 'disabled' => 'false', 'address' => ''], ['name' => 'telnet', 'port' => '23', 'disabled' => 'false', 'address' => '']],
            'interface/l2tp-server/server' => [['enabled' => 'true', 'use-ipsec' => 'required', 'ipsec-secret' => 'TOPSECRET-PSK', 'default-profile' => 'l2tp-profile']],
            'ppp/profile' => [['name' => 'default'], ['name' => 'l2tp-profile', 'local-address' => '10.10.0.1', 'remote-address' => 'l2tp-pool', 'only-one' => 'yes']],
            'ip/pool' => [['name' => 'l2tp-pool', 'ranges' => '10.10.0.2-10.10.3.254']],
            'ppp/secret' => [['name' => 'old1', 'password' => 'p1'], ['name' => 'old2', 'password' => 'p2']],
            'interface/wireguard' => [['name' => 'wg1', 'listen-port' => '13231', 'public-key' => 'PUBKEY=', 'private-key' => 'PRIVATE=', 'running' => 'true']],
            'interface/wireguard/peers' => [['interface' => 'wg1', 'public-key' => 'P1=']],
            'ip/address' => [['address' => '10.20.0.1/22', 'interface' => 'wg1'], ['address' => '185.1.2.3/24', 'interface' => 'ether1']],
        ];
    }

    /** Start the API emulator; returns [port, pin|null]. */
    protected function startApiServer(bool $tls, ?array $menus = null, string $user = 'panel', string $password = 'S3cret!pw', bool $http = false): array
    {
        $dir = sys_get_temp_dir().'/ros-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/fx.json", json_encode(['user' => $user, 'password' => $password, 'menus' => $menus ?? $this->menus()]));
        $pin = null;
        $args = '';
        if ($tls) {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            $csr = openssl_csr_new(['commonName' => 'panel-cert'], $key);
            $crt = openssl_csr_sign($csr, null, $key, 365);
            openssl_x509_export($crt, $c);
            openssl_pkey_export($key, $k);
            file_put_contents("$dir/cert.pem", $c.$k);
            $pin = Pin::fromCertificate($c);
            $args = ' '.escapeshellarg("$dir/cert.pem");
        }
        if ($http) {
            $args .= ($tls ? '' : " ''").' http';
        }
        $port = random_int(20000, 40000);
        $proc = proc_open('exec php '.escapeshellarg(base_path('tests/Fakes/routeros-api-server.php')).' '.$port.' '.escapeshellarg("$dir/fx.json").$args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->procs[] = $proc;
        $this->assertSame("ready\n", fgets($pipes[1]));

        return [$port, $pin];
    }

    /** In-memory REST transport: answers /rest/<menu> from the fixture. */
    protected function fakeRest(?array $menus = null, int $status = 200, bool $down = false): FakeRestTransport
    {
        $t = new FakeRestTransport($menus ?? $this->menus(), $status, $down);
        $this->app->instance(RestTransport::class, $t);

        return $t;
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            proc_terminate($p);
        }
        parent::tearDown();
    }
}
