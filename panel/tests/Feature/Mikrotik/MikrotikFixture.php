<?php

namespace Tests\Feature\Mikrotik;

use App\Models\MtGroup;
use App\Models\Router;
use App\RouterOs\RestTransport;
use App\Services\Mikrotik\WgKeys;
use Tests\Fakes\FakeRouter;

trait MikrotikFixture
{
    protected FakeRouter $ros;

    protected Router $router;

    protected MtGroup $l2tp;

    protected MtGroup $wg;

    protected string $serverPub;

    protected function setUpMikrotik(): void
    {
        $this->serverPub = WgKeys::pair()['public'];
        $this->ros = new FakeRouter([
            'system/resource' => [['version' => '7.7 (stable)', 'uptime' => '3d4h']],
            'system/identity' => [['name' => 'ir11']],
            'ppp/secret' => [['.id' => '*1', 'name' => 'manual1', 'password' => 'x', 'profile' => 'l2tp-profile', 'disabled' => 'false']],
            'ppp/active' => [], 'interface' => [], 'interface/l2tp-server' => [],
            'ppp/profile' => [['.id' => '*2', 'name' => 'l2tp-profile', 'local-address' => '10.10.0.1', 'remote-address' => 'l2tp-pool']],
            'interface/wireguard' => [['.id' => '*3', 'name' => 'wg1', 'listen-port' => '13231', 'public-key' => $this->serverPub, 'running' => 'true']],
            'interface/wireguard/peers' => [['.id' => '*4', 'interface' => 'wg1', 'public-key' => WgKeys::pair()['public'], 'allowed-address' => '10.20.0.2/32', 'rx' => '0', 'tx' => '0', 'disabled' => 'false']],
            'ip/address' => [['address' => '10.20.0.1/24', 'interface' => 'wg1']],
            'queue/simple' => [],
        ]);
        $this->app->instance(RestTransport::class, $this->ros);
        $this->router = Router::query()->create(['name' => 'هرنگ', 'host' => 'ir11.example.com', 'transport' => 'rest', 'port' => 8443, 'username' => 'panel',
            'password' => 'pw', 'cert_pin' => 'sha256//'.base64_encode(random_bytes(32)), 'is_active' => true, 'l2tp_enabled' => true, 'wireguard_enabled' => true]);
        $this->l2tp = MtGroup::query()->create(['router_id' => $this->router->id, 'protocol' => 'l2tp', 'name' => 'L2TP ایران', 'ppp_profile' => 'l2tp-profile',
            'multi_login' => 1, 'ipsec_psk' => 'PSK-123']);
        $this->wg = MtGroup::query()->create(['router_id' => $this->router->id, 'protocol' => 'wireguard', 'name' => 'WG', 'wg_interface' => 'wg1',
            'wg_network' => '10.20.0.0/24', 'wg_server_ip' => '10.20.0.1', 'wg_server_public_key' => $this->serverPub, 'wg_listen_port' => 13231, 'dns' => '1.1.1.1']);
    }

    protected int $GB = 1073741824;
}
