<?php

declare(strict_types=1);

use Box\Mod\Tajhost2fa\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testRfc6238Sha1SixDigits(): void
    {
        $expected = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];
        foreach ($expected as $time => $code) {
            $step = intdiv($time, 30);
            $this->assertSame($code, Totp::codeAt(self::SECRET, $step));
            $this->assertSame($step, Totp::verify(self::SECRET, $code, 0, 1, 30, 6, $time));
        }
    }

    public function testSameStepIsRejectedOnlyWithinNinetySeconds(): void
    {
        $time = 1234567890;
        $step = intdiv($time, 30);
        $code = Totp::codeAt(self::SECRET, $step);

        $this->assertNull(Totp::verify(self::SECRET, $code, $step, 1, 30, 6, $time, $time - 10));
        $this->assertSame($step, Totp::verify(self::SECRET, $code, $step, 1, 30, 6, $time, $time - 120));
        $this->assertNull(Totp::verify(self::SECRET, $code, $step + 1, 1, 30, 6, $time, $time - 120));
    }
}
