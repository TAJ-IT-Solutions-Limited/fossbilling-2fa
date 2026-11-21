<?php

declare(strict_types=1);

use Box\Mod\Tajhost2fa\Api\Admin;
use Box\Mod\Tajhost2fa\Service;
use Box\Mod\Tajhost2fa\Totp;
use FOSSBilling\InformationException;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testManageSettingsIsRegistered(): void
    {
        $permissions = (new Service())->getModulePermissions();

        $this->assertArrayHasKey('manage_settings', $permissions);
        $this->assertSame([], $permissions['manage_settings']);
    }

    public function testSettingsRequiresManagePermission(): void
    {
        $service = $this->service();
        $api = new Admin();
        $api->service = $service;

        $api->settings();

        $this->assertSame([['tajhost2fa', 'manage']], $api->checks);
    }

    public function testCheckCodeAcceptsANewerStep(): void
    {
        $now = time();
        $step = intdiv($now, 30);
        $code = Totp::codeAt(self::SECRET, $step);
        [$service, $db] = $this->pair();
        $record = $this->record($step - 2, date('Y-m-d H:i:s', $now - 120));

        $this->assertTrue($service->checkCode($record, $code));
        $this->assertSame($step, $db->execs[0][1]['step']);
    }

    public function testReplayedCodeDoesNotAdvanceTheLock(): void
    {
        $now = time();
        $step = intdiv($now, 30);
        $code = Totp::codeAt(self::SECRET, $step);
        [$service, $db] = $this->pair();
        $db->row = $this->record($step, date('Y-m-d H:i:s', $now - 10));
        $service->getDi()['request'] = new FakeRequest($code);

        try {
            $service->verifyLogin(Service::TYPE_ADMIN, 4);
            $this->fail('A reused code must be refused.');
        } catch (InformationException $e) {
            $this->assertSame('This code was already used. Wait for the next code.', $e->getMessage());
        }
        $this->assertCount(1, $db->execs);
        $this->assertSame(1, $db->execs[0][1]['f']);
    }

    public function testBackupCodeIsConsumed(): void
    {
        [$service, $db] = $this->pair();
        $record = $this->record(1, null);
        $record['backup_codes'] = json_encode([password_hash('ABCDEFGHJK', PASSWORD_DEFAULT)]);

        $this->assertTrue($service->checkCode($record, 'ABCDEFGHJK'));
        $this->assertSame([], json_decode($db->execs[0][1]['codes'], true));
        $this->assertFalse($service->checkCode($record, 'ZZZZZZZZZZ'));
    }

    public function testAdminCannotResetOwnAccount(): void
    {
        [$service, $db] = $this->pair();

        try {
            $service->resetFor(Service::TYPE_ADMIN, 4, 4);
            $this->fail('A self reset must be refused.');
        } catch (InformationException $e) {
            $this->assertStringContainsString('cannot reset your own', $e->getMessage());
        }
        $this->assertSame([], $db->execs);
    }

    public function testResetLogsTheActingAdminAndEmailsTheUser(): void
    {
        [$service, $db, $log, $mail] = $this->pair(true);
        $db->row = ['email' => 'staff@example.com'];

        $this->assertTrue($service->resetFor(Service::TYPE_ADMIN, 4, 9));

        $this->assertStringContainsString('admin #9', $log->lines[0]);
        $this->assertStringContainsString('admin #4', $log->lines[0]);
        $this->assertSame('staff@example.com', $mail->sent[0][0]);
        $this->assertSame('billing@example.com', $mail->sent[0][1]);
        $this->assertNotSame('', $mail->sent[0][1]);
    }

    public function testResetSucceedsWhenMailThrows(): void
    {
        [$service, $db, $log, $mail] = $this->pair(true);
        $db->row = ['email' => 'staff@example.com'];
        $mail->throw = true;

        $this->assertTrue($service->resetFor(Service::TYPE_ADMIN, 4, 9));
        $this->assertStringContainsString('DELETE FROM', $db->execs[0][0]);
        $this->assertContains('2FA reset email could not be sent', $log->lines);
    }

    public function testLockEscalatesAndKeepsTheCounter(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        [$service, $db, , , $limit] = $this->pair(false, true);

        $this->failTimes($service, 4);
        $this->assertSame(5, $db->execs[0][1]['f']);
        $this->assertEqualsWithDelta(time() + 15 * 60, strtotime((string) $db->execs[0][1]['l']), 5);

        $this->failTimes($service, 9);
        $this->assertSame(10, $db->execs[1][1]['f']);
        $this->assertEqualsWithDelta(time() + 30 * 60, strtotime((string) $db->execs[1][1]['l']), 5);

        $this->failTimes($service, 14);
        $this->assertSame(15, $db->execs[2][1]['f']);
        $this->assertEqualsWithDelta(time() + 60 * 60, strtotime((string) $db->execs[2][1]['l']), 5);
        $this->assertSame(['api_login', '203.0.113.9'], $limit->calls[0]);
        $this->assertCount(3, $limit->calls);
    }

    public function testIpLimitUsesTheRequestClientAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.8';
        [$service, , , , $limit] = $this->pair(false, true);
        $service->getDi()['request'] = new FakeRequest('000000', '198.51.100.20');

        $this->failTimes($service, 1);

        $this->assertSame(['api_login', '198.51.100.20'], $limit->calls[0]);
    }

    public function testUnenrolledAdminIsRefusedExceptSetupProfileAndLogout(): void
    {
        [$service] = $this->pair();
        $service->getDi()['loggedin_admin'] = (object) ['id' => 8];

        $service->assertAdminApiAllowed('profile', 'logout');
        $service->assertAdminApiAllowed('tajhost2fa', 'setup_start');

        try {
            $service->assertAdminApiAllowed('staff', 'get');
            $this->fail('An admin without 2FA must be refused.');
        } catch (InformationException $e) {
            $this->assertStringContainsString('Set up two-factor', $e->getMessage());
        }

        $service->getDi()['db']->row = ['enabled' => 1, 'id' => 1];
        $service->assertAdminApiAllowed('staff', 'get');
    }

    public function testLoginAndAnonymousCallsAreNotRefused(): void
    {
        [$service] = $this->pair();
        $service->assertAdminApiAllowed('staff', 'get');

        $service->getDi()['loggedin_admin'] = (object) ['id' => 8];
        $service->assertAdminApiAllowed('guest', 'login');
        $service->assertAdminApiAllowed('staff', 'login');
    }

    public function testBeforeAdminApiHookUsesTheGuard(): void
    {
        [$service, , , , , $di] = $this->pair();
        $di['loggedin_admin'] = (object) ['id' => 8];
        $di['mod_service'] = static fn (string $name): Service => $service;

        $this->expectException(InformationException::class);
        Service::onBeforeAdminApiCall(new Box_Event(['module' => 'staff', 'method' => 'get'], $di));
    }

    private function failTimes(Service $service, int $alreadyFailed): void
    {
        $method = new ReflectionMethod(Service::class, 'registerFailure');
        $method->invoke($service, ['id' => 3, 'user_type' => 'admin', 'user_id' => 8, 'failed_attempts' => $alreadyFailed]);
    }

    private function record(int $step, ?string $lastUsed): array
    {
        return [
            'id' => 7,
            'user_type' => 'admin',
            'user_id' => 4,
            'secret' => self::SECRET,
            'last_step' => $step,
            'last_used_at' => $lastUsed,
            'backup_codes' => '[]',
            'enabled' => 1,
            'failed_attempts' => 0,
        ];
    }

    private function service(): Service
    {
        return $this->pair()[0];
    }

    /** @return array{0: Service, 1: FakeDb, 2: FakeLog, 3: FakeMail, 4: FakeLimit, 5: Pimple\Container} */
    private function pair(bool $withMail = false, bool $withLimit = false): array
    {
        $db = new FakeDb();
        $log = new FakeLog();
        $mail = new FakeMail();
        $limit = new FakeLimit();
        $di = new Pimple\Container();
        $di['db'] = $db;
        $di['logger'] = $log;
        $di['crypt'] = new IdentityCrypt();
        $di['mod_config'] = static fn (string $name): array => ['issuer' => 'Test'];
        if ($withMail) {
            $system = new FakeSystem();
            $di['mod_service'] = static function (string $name) use ($mail, $system): object {
                if ($name === 'email') {
                    return $mail;
                }
                if ($name === 'system') {
                    return $system;
                }
                throw new \RuntimeException($name);
            };
        }
        if ($withLimit) {
            $di['rate_limiter'] = $limit;
        }
        $service = new Service();
        $service->setDi($di);

        return [$service, $db, $log, $mail, $limit, $di];
    }
}

final class FakeDb
{
    public array $execs = [];
    public mixed $row = null;

    public function exec(string $sql, array $params = []): void
    {
        $this->execs[] = [$sql, $params];
    }

    public function getRow(string $sql, array $params = []): mixed
    {
        return $this->row;
    }
}

final class FakeLog
{
    public array $lines = [];

    public function warning(string $message): void
    {
        $this->lines[] = $message;
    }

    public function info(string $message): void
    {
        $this->lines[] = $message;
    }
}

final class FakeMail
{
    public array $sent = [];

    public bool $throw = false;

    public function sendMail(mixed ...$args): bool
    {
        if ($this->throw) {
            throw new \RuntimeException('smtp down');
        }
        $this->sent[] = $args;

        return true;
    }
}

final class FakeRequest
{
    public object $request;

    public function __construct(private string $code, private string $ip = '203.0.113.9')
    {
        $code = $this->code;
        $this->request = new class($code) {
            public function __construct(private string $code)
            {
            }

            public function get(string $key): string
            {
                return $this->code;
            }
        };
    }

    public function getContent(): string
    {
        return '';
    }

    public function getClientIp(): string
    {
        return $this->ip;
    }
}

final class FakeLimit
{
    public array $calls = [];

    public function consumeOrThrow(string $policy, string $subject): void
    {
        $this->calls[] = [$policy, $subject];
    }
}

final class FakeSystem
{
    public function getParamValue(string $key): ?string
    {
        return $key === 'company_email' ? 'billing@example.com' : null;
    }
}

final class IdentityCrypt
{
    public function decrypt(mixed $value): mixed
    {
        return $value;
    }
}
