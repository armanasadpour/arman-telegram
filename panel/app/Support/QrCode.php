<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal QR Code generator (byte mode, versions 1–40, error correction L/M/Q/H) rendered as SVG.
 * Ported from the algorithm of Project Nayuki's "QR Code generator library" (MIT License).
 * Used for WireGuard configs so customers can scan them in the WireGuard app; no external service is called.
 */
final class QrCode
{
    private const ECC_PER_BLOCK = [
        'L' => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    private const BLOCKS = [
        'L' => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    private const FORMAT_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /** @var list<list<bool>> */
    private array $modules = [];

    /** @var list<list<bool>> */
    private array $isFunction = [];

    private int $size;

    private function __construct(private int $version, private string $ecl)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    /** @return list<list<bool>> matrix [y][x], true = dark */
    public static function matrix(string $data, string $ecl = 'M'): array
    {
        $len = strlen($data);
        $version = null;
        for ($v = 1; $v <= 40; $v++) {
            $capacityBits = self::numDataCodewords($v, $ecl) * 8;
            $usedBits = 4 + ($v <= 9 ? 8 : 16) + $len * 8;
            if ($usedBits <= $capacityBits) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new RuntimeException('Data too long for a QR code');
        }

        // bit stream: byte mode
        $bits = [];
        $append = function (int $val, int $n) use (&$bits) {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $append(0b0100, 4);
        $append($len, $version <= 9 ? 8 : 16);
        for ($i = 0; $i < $len; $i++) {
            $append(ord($data[$i]), 8);
        }
        $capacityBits = self::numDataCodewords($version, $ecl) * 8;
        $append(0, min(4, $capacityBits - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }
        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $codewords[] = (int) bindec(implode('', $byte));
        }

        $qr = new self($version, $ecl);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($codewords));

        $best = 0;
        $minPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $qr->applyMask($m);
            $qr->drawFormatBits($m);
            $p = $qr->penalty();
            if ($p < $minPenalty) {
                [$best, $minPenalty] = [$m, $p];
            }
            $qr->applyMask($m);   // XOR again = undo
        }
        $qr->applyMask($best);
        $qr->drawFormatBits($best);

        return $qr->modules;
    }

    public static function svg(string $data, int $scale = 6, string $ecl = 'M'): string
    {
        $m = self::matrix($data, $ecl);
        $n = count($m);
        $border = 4;
        $dim = ($n + $border * 2);
        $path = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M'.($x + $border).','.($y + $border).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$dim.' '.$dim.'" width="'.($dim * $scale).'" height="'.($dim * $scale).'" shape-rendering="crispEdges" role="img" aria-label="QR">'
            .'<rect width="100%" height="100%" fill="#fff"/><path d="'.$path.'" fill="#000"/></svg>';
    }

    // ------------------------------------------------------------------ structure

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function numDataCodewords(int $ver, string $ecl): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ecl][$ver] * self::BLOCKS[$ecl][$ver];
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (! ($i === 0 && $j === 0 || $i === 0 && $j === $n - 1 || $i === $n - 1 && $j === 0)) {
                    $this->drawAlignment($pos[$i], $pos[$j]);
                }
            }
        }
        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : (int) ceil(($this->version * 4 + 4) / ($numAlign * 2 - 2)) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }

        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = self::FORMAT_BITS[$this->ecl] << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit = fn (int $i) => (($bits >> $i) & 1) !== 0;
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = $this->version << 12 | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $bit);
            $this->setFunction($b, $a, $bit);
        }
    }

    // ------------------------------------------------------------------ error correction

    /** @param list<int> $data @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::BLOCKS[$this->ecl][$this->version];
        $eccLen = self::ECC_PER_BLOCK[$this->ecl][$this->version];
        $raw = intdiv(self::numRawDataModules($this->version), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $datLen = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = 0;
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $out = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $b) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $out[] = $b[$i];
                }
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }

        return $result;
    }

    /** @param list<int> $data @param list<int> $divisor @return list<int> */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $d) {
                $result[$i] ^= self::gfMul($d, $factor);
            }
        }

        return $result;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    // ------------------------------------------------------------------ placement and masking

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (! $this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && ! $this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** Standard penalty rules N1–N4 (finder-like patterns scanned in both directions). */
    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $p = 0;
        $dark = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $run = 1;
                $line = [];
                for ($b = 0; $b < $n; $b++) {
                    $line[] = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                }
                for ($b = 1; $b < $n; $b++) {
                    if ($line[$b] === $line[$b - 1]) {
                        $run++;
                        if ($run === 5) {
                            $p += 3;
                        } elseif ($run > 5) {
                            $p++;
                        }
                    } else {
                        $run = 1;
                    }
                }
                $s = implode('', array_map(fn ($v) => $v ? '1' : '0', $line));
                $p += 40 * (substr_count('0000'.$s.'0000', '10111010000') + substr_count('0000'.$s.'0000', '00001011101'));
            }
        }
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dark += (int) $m[$y][$x];
                if ($x < $n - 1 && $y < $n - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }
        $total = $n * $n;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;

        return $p + max(0, $k) * 10;
    }
}
