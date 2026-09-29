<?php

declare(strict_types=1);
/**
 * TAJHOST Two-Factor Authentication for FOSSBilling.
 *
 * Minimal, dependency-free RFC 6238 (TOTP) / RFC 4226 (HOTP) implementation.
 *
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Tajhost2fa;

final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generates a random 160-bit secret, base32 encoded (32 characters).
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Calculates the code for a given time step.
     */
    public static function codeAt(string $secret, int $step, int $digits = 6): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('N*', 0, $step); // 64-bit big-endian counter
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function currentStep(int $period = 30, ?int $time = null): int
    {
        return intdiv($time ?? time(), $period);
    }

    /**
     * Verifies a code within +/- $window steps.
     *
     * Returns the matched time step, or null when the code is invalid or was
     * already used (step <= $lastStep, replay protection).
     */
    public static function verify(string $secret, string $code, int $lastStep = 0, int $window = 1, int $period = 30, int $digits = 6, ?int $time = null): ?int
    {
        if (!preg_match('/^\d{' . $digits . '}$/', $code)) {
            return null;
        }

        $current = self::currentStep($period, $time);
        $matched = null;
        for ($i = -$window; $i <= $window; ++$i) {
            $step = $current + $i;
            // Always evaluate every candidate so timing does not reveal which step matched.
            if (hash_equals(self::codeAt($secret, $step, $digits), $code) && $step > $lastStep) {
                $matched = $step;
            }
        }

        return $matched;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer, int $digits = 6, int $period = 30): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($binary, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper((string) preg_replace('/[\s=]/', '', $data));
        $binary = '';
        foreach (str_split($data) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 secret');
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
