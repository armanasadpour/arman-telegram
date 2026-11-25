<?php

namespace App\Payments;

use App\Models\Payment;
use App\Services\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a USDT transfer on-chain:
 *  - TRC20: TronGrid /wallet/gettransactioninfobyid (+ /wallet/getnowblock for confirmations)
 *  - ERC20 / BEP20: Etherscan API v2 (chainid 1 / 56) eth_getTransactionReceipt, eth_blockNumber, eth_getBlockByNumber
 * A valid payment = successful tx, a Transfer log of the official USDT contract, to our address, value >= expected,
 * enough confirmations, mined after the payment request was created. The caller enforces "hash used once" (unique column).
 */
class UsdtVerifier
{
    public const TRANSFER_TOPIC = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public const CONTRACTS = [
        'trc20' => ['contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'decimals' => 6, 'confirmations' => 20],
        'erc20' => ['contract' => '0xdac17f958d2ee523a2206206994597c13d831ec7', 'decimals' => 6, 'confirmations' => 12, 'chain' => 1],
        'bep20' => ['contract' => '0x55d398326f99059ff775485246999027b3197955', 'decimals' => 18, 'confirmations' => 15, 'chain' => 56],
    ];

    public function __construct(private Settings $settings) {}

    public function address(string $network): string
    {
        return trim((string) $this->settings->get('pay.usdt.address.'.$network));
    }

    /** USDT owed for an amount in rial at the admin's rate (toman per USDT), rounded up to 2 decimals. */
    public function usdtFor(int $amountRial): ?string
    {
        $rate = (int) $this->settings->get('pay.usdt.rate_toman');
        if ($rate <= 0) {
            return null;
        }

        return number_format(ceil(($amountRial / 10) / $rate * 100) / 100, 2, '.', '');
    }

    public static function validHash(string $network, string $hash): bool
    {
        return $network === 'trc20' ? (bool) preg_match('/^[0-9a-fA-F]{64}$/', $hash) : (bool) preg_match('/^0x[0-9a-fA-F]{64}$/', $hash);
    }

    public function verify(Payment $p): VerifyResult
    {
        $net = (string) $p->usdt_network;
        $hash = (string) $p->tx_hash;
        if (! isset(self::CONTRACTS[$net]) || ! self::validHash($net, $hash) || $this->address($net) === '') {
            return VerifyResult::failed('شبکه یا هش تراکنش نامعتبر است.');
        }
        try {
            [$status, $logs, $block, $time, $latest] = $net === 'trc20' ? $this->tron($hash) : $this->evm($net, $hash);
        } catch (Throwable $e) {
            return VerifyResult::uncertain('دسترسی به اکسپلورر شبکه برقرار نشد؛ مدیر به‌صورت دستی بررسی می‌کند.');
        }
        if ($status === null) {
            return VerifyResult::uncertain('تراکنش هنوز در شبکه پیدا نشد یا تأیید نشده است.');
        }
        if ($status !== true) {
            return VerifyResult::failed('تراکنش در شبکه ناموفق است.');
        }
        $c = self::CONTRACTS[$net];
        $want = $this->hex20($net === 'trc20' ? $this->tronToHex($this->address($net)) : $this->address($net));
        $contract = $this->hex20($net === 'trc20' ? $this->tronToHex($c['contract']) : $c['contract']);
        $received = '0';
        foreach ($logs as $log) {
            $topics = array_map(fn ($t) => strtolower((string) preg_replace('/^0x/', '', (string) $t)), (array) ($log['topics'] ?? []));
            if (count($topics) < 3 || $topics[0] !== self::TRANSFER_TOPIC || $this->hex20((string) ($log['address'] ?? '')) !== $contract) {
                continue;
            }
            if (substr($topics[2], -40) !== $want) {
                continue;
            }
            $received = $this->addDec($received, $this->hexToDec(preg_replace('/^0x/', '', (string) ($log['data'] ?? '0'))));
        }
        if ($received === '0') {
            return VerifyResult::failed('در این تراکنش انتقال USDT به آدرس ما پیدا نشد (شبکه، آدرس یا توکن اشتباه است).');
        }
        $receivedUsdt = $this->scale($received, $c['decimals']);
        if (bccomp_safe($receivedUsdt, (string) $p->usdt_amount) < 0) {
            return VerifyResult::failed('مبلغ واریز ('.$receivedUsdt.' USDT) کمتر از مبلغ لازم ('.rtrim(rtrim((string) $p->usdt_amount, '0'), '.').' USDT) است.');
        }
        if ($time !== null && $time < $p->created_at->getTimestamp() - 600) {
            return VerifyResult::failed('این تراکنش قبل از ثبت درخواست پرداخت انجام شده است.');
        }
        $min = (int) ($this->settings->get('pay.usdt.confirmations.'.$net) ?: $c['confirmations']);
        if ($latest - $block < $min) {
            return VerifyResult::uncertain('تراکنش هنوز تأیید کافی ندارد ('.max(0, $latest - $block).' از '.$min.')؛ کمی بعد دوباره بررسی می‌شود.');
        }

        return VerifyResult::paid($hash, null, $p->amount_rial);
    }

    /** @return array{0:?bool,1:array,2:int,3:?int,4:int} */
    private function tron(string $hash): array
    {
        $base = $this->host('https://api.trongrid.io');
        $h = Http::timeout(15)->connectTimeout(8)->acceptJson();
        if ($key = $this->settings->get('pay.usdt.trongrid_key')) {
            $h = $h->withHeaders(['TRON-PRO-API-KEY' => $key]);
        }
        $info = $h->asJson()->post($base.'/wallet/gettransactioninfobyid', ['value' => $hash])->throw()->json();
        if (! is_array($info) || ! isset($info['blockNumber'])) {
            return [null, [], 0, null, 0];
        }
        $now = $h->asJson()->post($base.'/wallet/getnowblock', [])->throw()->json('block_header.raw_data.number');
        $ok = ($info['receipt']['result'] ?? null) === 'SUCCESS';
        $logs = array_map(fn ($l) => ['address' => $l['address'] ?? '', 'topics' => $l['topics'] ?? [], 'data' => $l['data'] ?? '0'], (array) ($info['log'] ?? []));

        return [$ok, $logs, (int) $info['blockNumber'], isset($info['blockTimeStamp']) ? intdiv((int) $info['blockTimeStamp'], 1000) : null, (int) $now];
    }

    /** @return array{0:?bool,1:array,2:int,3:?int,4:int} */
    private function evm(string $net, string $hash): array
    {
        $chain = self::CONTRACTS[$net]['chain'];
        $key = (string) $this->settings->get('pay.usdt.etherscan_key');
        $q = fn (array $p) => Http::timeout(15)->connectTimeout(8)->acceptJson()
            ->get($this->host('https://api.etherscan.io').'/v2/api', ['chainid' => $chain, 'module' => 'proxy', 'apikey' => $key] + $p)->throw()->json('result');
        $rc = $q(['action' => 'eth_getTransactionReceipt', 'txhash' => $hash]);
        if (! is_array($rc) || empty($rc['blockNumber'])) {
            return [null, [], 0, null, 0];
        }
        $block = (int) hexdec($rc['blockNumber']);
        $latest = (int) hexdec((string) $q(['action' => 'eth_blockNumber']));
        $b = $q(['action' => 'eth_getBlockByNumber', 'tag' => $rc['blockNumber'], 'boolean' => 'false']);

        return [($rc['status'] ?? '') === '0x1', (array) ($rc['logs'] ?? []), $block, is_array($b) && isset($b['timestamp']) ? (int) hexdec($b['timestamp']) : null, $latest];
    }

    private function host(string $real): string
    {
        $o = config('panel.pay_api_override');

        return $o ? rtrim($o, '/').'/'.parse_url($real, PHP_URL_HOST) : $real;
    }

    /** last 20 bytes, lowercase hex, no 0x */
    private function hex20(string $addr): string
    {
        return substr(strtolower(preg_replace('/^0x/', '', $addr)), -40);
    }

    /** Tron base58check address -> hex (41 + 20 bytes). */
    public function tronToHex(string $b58): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $bytes = [0];
        foreach (str_split($b58) as $ch) {
            $carry = strpos($alphabet, $ch);
            if ($carry === false) {
                return '';
            }
            for ($i = 0; $i < count($bytes); $i++) {
                $carry += $bytes[$i] * 58;
                $bytes[$i] = $carry & 0xFF;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }
        $hex = '';
        foreach (array_reverse($bytes) as $b) {
            $hex .= sprintf('%02x', $b);
        }

        return substr($hex, 0, -8);   // drop the 4-byte checksum
    }

    private function hexToDec(string $hex): string
    {
        $dec = '0';
        foreach (str_split(strtolower(ltrim($hex, '0') ?: '0')) as $c) {
            $dec = $this->addDec($this->mulDec($dec, 16), (string) hexdec($c));
        }

        return $dec;
    }

    private function mulDec(string $a, int $m): string
    {
        $carry = 0;
        $out = '';
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $v = ((int) $a[$i]) * $m + $carry;
            $out = ($v % 10).$out;
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) {
            $out = ($carry % 10).$out;
            $carry = intdiv($carry, 10);
        }

        return ltrim($out, '0') ?: '0';
    }

    private function addDec(string $a, string $b): string
    {
        $out = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $v = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $out = ($v % 10).$out;
            $carry = intdiv($v, 10);
        }

        return ltrim($out, '0') ?: '0';
    }

    /** integer string with $decimals -> decimal string */
    private function scale(string $int, int $decimals): string
    {
        $int = str_pad($int, $decimals + 1, '0', STR_PAD_LEFT);

        return rtrim(rtrim(substr($int, 0, -$decimals).'.'.substr($int, -$decimals), '0'), '.');
    }
}

/** Compare two non-negative decimal strings without bcmath (not always installed on shared hosting). */
function bccomp_safe(string $a, string $b): int
{
    [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
    [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');
    $ai = ltrim($ai, '0');
    $bi = ltrim($bi, '0');
    if (strlen($ai) !== strlen($bi)) {
        return strlen($ai) <=> strlen($bi);
    }
    if ($ai !== $bi) {
        return strcmp($ai, $bi) <=> 0;
    }
    $len = max(strlen($af), strlen($bf));

    return strcmp(str_pad($af, $len, '0'), str_pad($bf, $len, '0')) <=> 0;
}
