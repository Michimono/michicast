<?php

namespace App\Support;

/**
 * Minimal byte-mode QR Code SVG encoder (ECC level M) for short otpauth URIs.
 * No Composer QR package — modules render as SVG rects with fill="currentColor".
 */
class QrSvg
{
    /** @var array<int, int> */
    private array $gfExp = [];

    /** @var array<int, int> */
    private array $gfLog = [];

    public function __construct()
    {
        $x = 1;

        for ($i = 0; $i < 255; $i++) {
            $this->gfExp[$i] = $x;
            $this->gfLog[$x] = $i;
            $x <<= 1;

            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
    }

    /**
     * Encode $payload as an inline SVG QR code.
     */
    public function render(string $payload, int $moduleSize = 4): string
    {
        $version = $this->chooseVersion(strlen($payload));
        $size = 21 + ($version - 1) * 4;
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $this->addFinderPatterns($matrix, $reserved, $size);
        $this->addTimingPatterns($matrix, $reserved, $size);
        $this->addAlignmentPatterns($matrix, $reserved, $version);
        $this->addDarkModule($matrix, $reserved, $version);
        $this->reserveFormatAreas($reserved, $size);

        $codewords = $this->buildCodewords($payload, $version);
        $this->placeCodewords($matrix, $reserved, $codewords, $size);

        $mask = $this->selectMask($matrix, $reserved, $size);
        $this->applyMask($matrix, $reserved, $size, $mask);
        $this->drawFormatBits($matrix, $size, $mask);

        return $this->toSvg($matrix, $size, $moduleSize);
    }

    private function chooseVersion(int $length): int
    {
        $capacities = [
            1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
            6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
        ];

        foreach ($capacities as $version => $capacity) {
            if ($length <= $capacity) {
                return $version;
            }
        }

        throw new \InvalidArgumentException('Payload too long for supported QR versions.');
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function versionParams(int $version): array
    {
        return match ($version) {
            1 => [26, 10, 1],
            2 => [44, 16, 1],
            3 => [70, 26, 1],
            4 => [100, 18, 2],
            5 => [134, 24, 2],
            6 => [172, 18, 4],
            7 => [196, 20, 4],
            8 => [242, 20, 4],
            9 => [292, 30, 5],
            10 => [346, 18, 5],
            default => throw new \InvalidArgumentException('Unsupported QR version.'),
        };
    }

    /**
     * @return list<int>
     */
    private function buildCodewords(string $payload, int $version): array
    {
        [$total, $eccPerBlock, $blocks] = $this->versionParams($version);
        $dataCapacity = $total - ($eccPerBlock * $blocks);

        $lengthBits = $version >= 10 ? 16 : 8;
        $bits = '0100'.str_pad(decbin(strlen($payload)), $lengthBits, '0', STR_PAD_LEFT);

        foreach (str_split($payload) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $maxBits = $dataCapacity * 8;
        $bits .= str_repeat('0', min(4, $maxBits - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $data = [];
        foreach (str_split($bits, 8) as $byte) {
            $data[] = bindec($byte);
        }

        $pad = 0;
        while (count($data) < $dataCapacity) {
            $data[] = $pad % 2 === 0 ? 0xEC : 0x11;
            $pad++;
        }

        return $this->interleave($data, $eccPerBlock, $blocks);
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private function interleave(array $data, int $eccPerBlock, int $blocks): array
    {
        $base = intdiv(count($data), $blocks);
        $extra = count($data) % $blocks;
        $short = $blocks - $extra;
        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        for ($i = 0; $i < $blocks; $i++) {
            $size = $base + ($i >= $short ? 1 : 0);
            $block = array_slice($data, $offset, $size);
            $offset += $size;
            $dataBlocks[] = $block;
            $eccBlocks[] = $this->reedSolomon($block, $eccPerBlock);
        }

        $result = [];
        $max = max(array_map('count', $dataBlocks));

        for ($i = 0; $i < $max; $i++) {
            foreach ($dataBlocks as $block) {
                if (array_key_exists($i, $block)) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private function reedSolomon(array $data, int $eccLen): array
    {
        $gen = [1];

        for ($i = 0; $i < $eccLen; $i++) {
            $gen = $this->polyMul($gen, [1, $this->gfExp[$i]]);
        }

        $msg = array_merge($data, array_fill(0, $eccLen, 0));

        for ($i = 0; $i < count($data); $i++) {
            $coef = $msg[$i];

            if ($coef === 0) {
                continue;
            }

            $logCoef = $this->gfLog[$coef];

            for ($j = 0; $j < count($gen); $j++) {
                $msg[$i + $j] ^= $this->gfExp[($logCoef + $this->gfLog[$gen[$j]]) % 255];
            }
        }

        return array_slice($msg, count($data));
    }

    /**
     * @param  list<int>  $p
     * @param  list<int>  $q
     * @return list<int>
     */
    private function polyMul(array $p, array $q): array
    {
        $result = array_fill(0, count($p) + count($q) - 1, 0);

        for ($i = 0; $i < count($p); $i++) {
            for ($j = 0; $j < count($q); $j++) {
                if ($p[$i] === 0 || $q[$j] === 0) {
                    continue;
                }

                $result[$i + $j] ^= $this->gfExp[($this->gfLog[$p[$i]] + $this->gfLog[$q[$j]]) % 255];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function addFinderPatterns(array &$matrix, array &$reserved, int $size): void
    {
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $col]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $row + $r;
                    $cc = $col + $c;

                    if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                        continue;
                    }

                    $inFinder = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                    $dark = $inFinder && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));

                    if ($inFinder) {
                        $matrix[$rr][$cc] = $dark ? 1 : 0;
                        $reserved[$rr][$cc] = true;
                    } elseif ($matrix[$rr][$cc] === null) {
                        $matrix[$rr][$cc] = 0;
                        $reserved[$rr][$cc] = true;
                    }
                }
            }
        }
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function addTimingPatterns(array &$matrix, array &$reserved, int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            if (! $reserved[6][$i]) {
                $matrix[6][$i] = $i % 2 === 0 ? 1 : 0;
                $reserved[6][$i] = true;
            }

            if (! $reserved[$i][6]) {
                $matrix[$i][6] = $i % 2 === 0 ? 1 : 0;
                $reserved[$i][6] = true;
            }
        }
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function addAlignmentPatterns(array &$matrix, array &$reserved, int $version): void
    {
        $positions = match ($version) {
            1 => [],
            2 => [6, 18],
            3 => [6, 22],
            4 => [6, 26],
            5 => [6, 30],
            6 => [6, 34],
            7 => [6, 22, 38],
            8 => [6, 24, 42],
            9 => [6, 26, 46],
            10 => [6, 28, 50],
            default => [],
        };

        foreach ($positions as $row) {
            foreach ($positions as $col) {
                if ($reserved[$row][$col]) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $dark = abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0);
                        $matrix[$row + $r][$col + $c] = $dark ? 1 : 0;
                        $reserved[$row + $r][$col + $c] = true;
                    }
                }
            }
        }
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function addDarkModule(array &$matrix, array &$reserved, int $version): void
    {
        $row = (4 * $version) + 9;
        $matrix[$row][8] = 1;
        $reserved[$row][8] = true;
    }

    /**
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function reserveFormatAreas(array &$reserved, int $size): void
    {
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }

        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     * @param  list<int>  $codewords
     */
    private function placeCodewords(array &$matrix, array $reserved, array $codewords, int $size): void
    {
        $bits = '';

        foreach ($codewords as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $bitIndex = 0;
        $direction = -1;

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            for ($i = 0; $i < $size; $i++) {
                $row = $direction < 0 ? $size - 1 - $i : $i;

                for ($c = 0; $c < 2; $c++) {
                    $cc = $col - $c;

                    if ($reserved[$row][$cc] || $matrix[$row][$cc] !== null) {
                        continue;
                    }

                    $matrix[$row][$cc] = (isset($bits[$bitIndex]) && $bits[$bitIndex] === '1') ? 1 : 0;
                    $bitIndex++;
                }
            }

            $direction = -$direction;
        }

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($matrix[$row][$col] === null) {
                    $matrix[$row][$col] = 0;
                }
            }
        }
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function selectMask(array $matrix, array $reserved, int $size): int
    {
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $copy = $matrix;
            $this->applyMask($copy, $reserved, $size, $mask);
            $this->drawFormatBits($copy, $size, $mask);
            $score = $this->penaltyScore($copy, $size);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function applyMask(array &$matrix, array $reserved, int $size, int $mask): void
    {
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($reserved[$row][$col]) {
                    continue;
                }

                if ($this->maskFlips($mask, $row, $col)) {
                    $matrix[$row][$col] = $matrix[$row][$col] ? 0 : 1;
                }
            }
        }
    }

    private function maskFlips(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            default => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
        };
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     */
    private function drawFormatBits(array &$matrix, int $size, int $mask): void
    {
        $formats = [
            0b101010000010010,
            0b101000100100101,
            0b101111001111100,
            0b101101101001011,
            0b100010111111001,
            0b100000011001110,
            0b100111110010111,
            0b100101010100000,
        ];

        $bits = $formats[$mask];

        for ($i = 0; $i < 15; $i++) {
            $bit = ($bits >> (14 - $i)) & 1;

            if ($i < 6) {
                $matrix[$i][8] = $bit;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = $bit;
            } else {
                $matrix[$size - 15 + $i][8] = $bit;
            }

            if ($i < 8) {
                $matrix[8][$size - 1 - $i] = $bit;
            } elseif ($i < 9) {
                $matrix[8][15 - $i] = $bit;
            } else {
                $matrix[8][14 - $i] = $bit;
            }
        }
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     */
    private function penaltyScore(array $matrix, int $size): int
    {
        $score = 0;

        for ($row = 0; $row < $size; $row++) {
            $run = 1;

            for ($col = 1; $col < $size; $col++) {
                if ($matrix[$row][$col] === $matrix[$row][$col - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }

            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }

        for ($col = 0; $col < $size; $col++) {
            $run = 1;

            for ($row = 1; $row < $size; $row++) {
                if ($matrix[$row][$col] === $matrix[$row - 1][$col]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }

            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }

        return $score;
    }

    /**
     * @param  array<int, array<int, int|null>>  $matrix
     */
    private function toSvg(array $matrix, int $size, int $moduleSize): string
    {
        $quiet = 4;
        $dim = ($size + $quiet * 2) * $moduleSize;
        $rects = [];

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if (! $matrix[$row][$col]) {
                    continue;
                }

                $x = ($col + $quiet) * $moduleSize;
                $y = ($row + $quiet) * $moduleSize;
                $rects[] = '<rect x="'.$x.'" y="'.$y.'" width="'.$moduleSize.'" height="'.$moduleSize.'" fill="currentColor" />';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$dim.' '.$dim.'" width="'.$dim.'" height="'.$dim.'" role="img" aria-label="Two-factor authentication QR code">'
            .'<rect width="'.$dim.'" height="'.$dim.'" fill="white" />'
            .implode('', $rects)
            .'</svg>';
    }
}
