<?php

declare(strict_types=1);

namespace Metalleg\TrackingPixel;

final class Identifiers
{
    public static function random(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        for ($position = 0; $position < $length; $position++) {
            $result .= $alphabet[random_int(0, 61)];
        }
        return $result;
    }

    public static function validExternal(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9]{128}\z/D', $value) === 1;
    }

    public static function validProject(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9]{10}\z/D', $value) === 1;
    }

    public static function validToken(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9]{64}\z/D', $value) === 1;
    }
}