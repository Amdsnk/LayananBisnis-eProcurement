<?php

namespace App\Domain;

final class Money
{
    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 4);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, 4);
    }

    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul($a, $b, 8));
    }

    public static function round(string $n, int $scale = 4): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($n, bccomp($n, '0', 8) >= 0 ? $half : '-'.$half, $scale);
    }

    public static function line(array $l): array
    {
        $base = bcmul((string) $l['quantity'], (string) $l['unit_price'], 8);
        $net = self::round(bcmul($base, bcsub('1', bcdiv((string) ($l['discount_percent'] ?? 0), '100', 8), 8), 8));
        $tax = self::round(bcmul($net, bcdiv((string) ($l['tax_percent'] ?? 0), '100', 8), 8));

        return ['net' => $net, 'tax' => $tax, 'total' => self::add($net, $tax)];
    }
}
