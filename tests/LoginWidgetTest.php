<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoginWidgetTest extends TestCase
{
    public function testTheCodeFieldStaysHiddenUntilTheModuleAsks(): void
    {
        $root = dirname(__DIR__);
        $widgets = [
            'staff/login' => $root . '/src/templates/admin/widgets/mod_tajhost2fa_login.html.twig',
            'client/login' => $root . '/src/templates/client/widgets/mod_tajhost2fa_login.html.twig',
        ];
        foreach ($widgets as $action => $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString('tajhost2fa-show', $source);
            $this->assertStringNotContainsString('I have a 2FA code', $source);
            $this->assertStringNotContainsString('لدي رمز تحقق', $source);
            $this->assertStringContainsString('style="display:none"', $source);
            $this->assertStringContainsString('dir="ltr"', $source);
            $this->assertStringContainsString("var ACTION = '" . $action . "';", $source);
            $this->assertMatchesRegularExpression('/if \(m\) \{ reveal\(\); \}/', $source);
            $this->assertDoesNotMatchRegularExpression('/if \(isError\)/', $source);
            $this->assertStringContainsString('input.focus()', $source);

            $this->assertSame(1, preg_match('#text\.match\((/[^/]+/)\)#', $source, $found));
            $pattern = $found[1];
            $this->assertSame(0, preg_match($pattern, 'Invalid email or password'));
            $this->assertSame(0, preg_match($pattern, 'Something failed (1999)'));
            $this->assertSame(0, preg_match($pattern, 'Code required (2001) extra'));
            $this->assertSame(1, preg_match($pattern, 'A verification code is required (2001)', $required));
            $this->assertSame('2001', $required[1]);
            $this->assertSame(1, preg_match($pattern, 'That code is wrong (2002)', $wrong));
            $this->assertSame('2002', $wrong[1]);
            $this->assertSame(1, preg_match($pattern, 'This login is locked (2003)', $locked));
            $this->assertSame('2003', $locked[1]);
        }
    }
}
