<?php

namespace addon\community_group_buy\lib;

final class Money
{
    public static function normalize($value)
    {
        $value = is_numeric($value) ? (string) $value : '0';
        return bcadd($value, '0', 2);
    }

    public static function add($left, $right)
    {
        return bcadd(self::normalize($left), self::normalize($right), 2);
    }

    public static function sub($left, $right)
    {
        return bcsub(self::normalize($left), self::normalize($right), 2);
    }

    public static function mulBps($amount, $basisPoints)
    {
        return bcdiv(bcmul(self::normalize($amount), (string) ((int) $basisPoints), 4), '10000', 2);
    }

    public static function max($left, $right)
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        return bccomp($left, $right, 2) >= 0 ? $left : $right;
    }

    public static function min($left, $right)
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        return bccomp($left, $right, 2) <= 0 ? $left : $right;
    }

    public static function isPositive($amount)
    {
        return bccomp(self::normalize($amount), '0.00', 2) === 1;
    }
}
