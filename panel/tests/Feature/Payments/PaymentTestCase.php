<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Payments\Payments;
use App\Payments\UsdtVerifier;
use App\Services\Finance\WalletService;
use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Bots\BotTestCase;

abstract class PaymentTestCase extends BotTestCase
{
    /** Mutable fake bank / explorer state, read by the Http::fake callbacks. */
    protected array $bank = [];

    protected function extraFakes(): array
    {
        return [
            'payment.zarinpal.com/*' => fn (Request $r) => $this->zarinpal($r),
            'sep.shaparak.ir/*' => fn (Request $r) => $this->sep($r),
            'bpm.shaparak.ir/*' => fn (Request $r) => $this->mellat($r),
            'sadad.shaparak.ir/*' => fn (Request $r) => $this->sadad($r),
            'api.trongrid.io/*' => fn (Request $r) => $this->tron($r),
            'api.etherscan.io/*' => fn (Request $r) => $this->etherscan($r),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank = ['verify_amount' => null, 'verify_code' => 100, 'down' => false, 'seq' => 0, 'tron' => [], 'evm' => [], 'latest' => 1000];
    }

    protected function enable(array $settings): void
    {
        $s = app(Settings::class);
        foreach ($settings as $k => $v) {
            $s->set('pay.'.$k, $v, encrypt: str_contains($k, 'merchant_id') || str_contains($k, 'password') || str_contains($k, 'key'));
        }
    }

    protected function resellerPayment(string $method, int $rial, array $attrs = []): Payment
    {
        $r = $this->reseller(username: 'payer'.uniqid());

        return app(Payments::class)->create($method, app(WalletService::class)->walletFor('reseller', $r->id), $rial, $attrs);
    }

    protected function walletBalance(Payment $p): int
    {
        return $p->wallet->fresh()->balance;
    }

    // ---------------------------------------------------------------- fake banks

    private function zarinpal(Request $r)
    {
        if ($this->bank['down']) {
            throw new ConnectionException('down');
        }
        if (str_ends_with($r->url(), '/request.json')) {
            $this->bank['zp_request'] = $r->data();

            return Http::response(['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000'.(++$this->bank['seq'])], 'errors' => []]);
        }
        $this->bank['zp_verify'] = $r->data();
        $code = $this->bank['verify_code'];

        return Http::response($code === 100 || $code === 101
            ? ['data' => ['code' => $code, 'ref_id' => 990000 + $this->bank['seq'], 'card_pan' => '502229******1234'], 'errors' => []]
            : ['data' => [], 'errors' => ['code' => $code, 'message' => 'x']]);
    }

    private function sep(Request $r)
    {
        if ($this->bank['down']) {
            throw new ConnectionException('down');
        }
        if (str_ends_with($r->url(), '/onlinepg/onlinepg')) {
            $this->bank['sep_request'] = $r->data();

            return Http::response(['status' => 1, 'token' => 'SEPTOKEN'.(++$this->bank['seq'])]);
        }
        $this->bank['sep_verify'] = $r->data();

        return Http::response(['ResultCode' => $this->bank['verify_code'] === 100 ? 0 : -2, 'Success' => true,
            'TransactionDetail' => ['RRN' => '123', 'RefNum' => $r->data()['RefNum'], 'MaskedPan' => '603799******1111', 'OrginalAmount' => $this->bank['verify_amount']]]);
    }

    private function mellat(Request $r)
    {
        $body = $r->body();
        $this->bank['mellat_calls'][] = preg_match('#<ns1:(\w+)>#', $body, $m) ? $m[1] : '?';
        $ret = match (true) {
            str_contains($body, 'bpPayRequest') => '0,MELLATREF'.(++$this->bank['seq']),
            str_contains($body, 'bpVerifyRequest') => $this->bank['mellat_verify'] ?? '0',
            str_contains($body, 'bpSettleRequest') => $this->bank['mellat_settle'] ?? '0',
            default => '-1',
        };

        return Http::response('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:resp xmlns:ns2="http://interfaces.core.sw.bps.com/"><return>'.$ret.'</return></ns2:resp></soap:Body></soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
    }

    private function sadad(Request $r)
    {
        if (str_ends_with($r->url(), '/PaymentRequest')) {
            $this->bank['sadad_request'] = $r->data();

            return Http::response(['ResCode' => 0, 'Token' => 'SADADTOKEN'.(++$this->bank['seq']), 'Description' => 'ok']);
        }
        $this->bank['sadad_verify'] = $r->data();

        return Http::response(['ResCode' => $this->bank['verify_code'] === 100 ? 0 : -1, 'Amount' => $this->bank['verify_amount'], 'RetrivalRefNo' => 'RRN'.$this->bank['seq'], 'SystemTraceNo' => '77', 'Description' => 'ok']);
    }

    private function tron(Request $r)
    {
        if ($this->bank['down']) {
            throw new ConnectionException('down');
        }
        if (str_ends_with($r->url(), '/getnowblock')) {
            return Http::response(['block_header' => ['raw_data' => ['number' => $this->bank['latest']]]]);
        }

        return Http::response($this->bank['tron'][$r->data()['value']] ?? []);
    }

    private function etherscan(Request $r)
    {
        $q = $r->data();
        if ($this->bank['down']) {
            throw new ConnectionException('down');
        }

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => match ($q['action']) {
            'eth_blockNumber' => '0x'.dechex($this->bank['latest']),
            'eth_getBlockByNumber' => ['timestamp' => '0x'.dechex(time())],
            'eth_getTransactionReceipt' => $this->bank['evm'][$q['txhash']] ?? null,
            default => null,
        }]);
    }

    // ---------------------------------------------------------------- chain fixtures

    protected function tronTx(string $hash, string $toB58, float $usdt, int $block = 970, string $result = 'SUCCESS', ?string $contractB58 = null): void
    {
        $v = app(UsdtVerifier::class);
        $to = substr($v->tronToHex($toB58), 2);
        $contract = substr($v->tronToHex($contractB58 ?? 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'), 2);
        $this->bank['tron'][$hash] = ['id' => $hash, 'blockNumber' => $block, 'blockTimeStamp' => time() * 1000, 'receipt' => ['result' => $result],
            'log' => [['address' => $contract, 'topics' => [UsdtVerifier::TRANSFER_TOPIC, str_repeat('0', 24).str_repeat('a', 40), str_repeat('0', 24).$to],
                'data' => str_pad(dechex((int) round($usdt * 1_000_000)), 64, '0', STR_PAD_LEFT)]]];
    }

    protected function evmTx(string $hash, string $to, float $usdt, string $contract, int $decimals, int $block = 970): void
    {
        $this->bank['evm'][$hash] = ['status' => '0x1', 'blockNumber' => '0x'.dechex($block), 'logs' => [[
            'address' => $contract, 'topics' => ['0x'.UsdtVerifier::TRANSFER_TOPIC, '0x'.str_repeat('0', 24).str_repeat('b', 40), '0x'.str_repeat('0', 24).strtolower(substr($to, 2))],
            'data' => '0x'.str_pad(self::decToHex((int) round($usdt * 100).str_repeat('0', $decimals - 2)), 64, '0', STR_PAD_LEFT),
        ]]];
    }

    private static function decToHex(string $dec): string
    {
        $hex = '';
        while ($dec !== '0' && $dec !== '') {
            $rem = 0;
            $q = '';
            foreach (str_split($dec) as $d) {
                $cur = $rem * 10 + (int) $d;
                $q .= intdiv($cur, 16);
                $rem = $cur % 16;
            }
            $hex = dechex($rem).$hex;
            $dec = ltrim($q, '0') ?: '0';
        }

        return $hex ?: '0';
    }
}
