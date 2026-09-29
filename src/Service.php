<?php

declare(strict_types=1);
/**
 * TAJHOST Two-Factor Authentication for FOSSBilling.
 *
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Tajhost2fa;

use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;
use FOSSBilling\Interfaces\WidgetProviderInterface;

class Service implements InjectionAwareInterface, WidgetProviderInterface
{
    public const TABLE = 'tajhost2fa';

    public const TYPE_ADMIN = 'admin';
    public const TYPE_CLIENT = 'client';

    /** Error codes returned to the login form. The login widget JS reacts to these. */
    public const ERR_CODE_REQUIRED = 2001;
    public const ERR_CODE_INVALID = 2002;
    public const ERR_LOCKED = 2003;

    /** Name of the form field carrying the 2FA code on the login forms. */
    public const FIELD = 'tajhost2fa_code';

    public const DIGITS = 6;
    public const PERIOD = 30;
    public const WINDOW = 1; // accept +/- 1 time step (30s) of clock drift

    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    public const BACKUP_CODE_COUNT = 10;

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            // Every staff member must be able to enrol their own account.
            'can_always_access' => true,
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage two-factor authentication'),
                'description' => __trans('Allows the staff member to view 2FA status and reset 2FA for other staff members and clients.'),
            ],
        ];
    }

    /* ------------------------------------------------------------------
     * Lifecycle
     * ------------------------------------------------------------------ */

    public function install(): bool
    {
        $this->di['db']->exec('
            CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `user_type` varchar(10) NOT NULL,
                `user_id` bigint(20) NOT NULL,
                `secret` text NULL,
                `pending_secret` text NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 0,
                `backup_codes` text NULL,
                `last_step` bigint(20) NOT NULL DEFAULT 0,
                `failed_attempts` int(11) NOT NULL DEFAULT 0,
                `locked_until` datetime NULL,
                `enabled_at` datetime NULL,
                `last_used_at` datetime NULL,
                `created_at` datetime NULL,
                `updated_at` datetime NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `user` (`user_type`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

        return true;
    }

    public function uninstall(): bool
    {
        $this->di['db']->exec('DROP TABLE IF EXISTS `' . self::TABLE . '`');

        return true;
    }

    /* ------------------------------------------------------------------
     * Widgets (login form field, enforcement banner, dashboard card)
     * ------------------------------------------------------------------ */

    public function getWidgets(): array
    {
        return [
            ['slot' => 'admin.staff.login.form.after', 'template' => 'mod_tajhost2fa_login'],
            ['slot' => 'client.page.login.form.after', 'template' => 'mod_tajhost2fa_login'],
            ['slot' => 'admin.theme.content.before', 'template' => 'mod_tajhost2fa_notice', 'priority' => 1],
            ['slot' => 'client.index.dashboard.client.before', 'template' => 'mod_tajhost2fa_notice'],
        ];
    }

    /* ------------------------------------------------------------------
     * Configuration
     * ------------------------------------------------------------------ */

    public function getSettings(): array
    {
        $config = $this->di['mod_config']('tajhost2fa') ?: [];

        return [
            'enforce_admin' => $this->bool($config['enforce_admin'] ?? true),
            'allow_clients' => $this->bool($config['allow_clients'] ?? true),
            'issuer' => trim((string) ($config['issuer'] ?? '')) ?: $this->getCompanyName(),
        ];
    }

    private function bool(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    private function getCompanyName(): string
    {
        try {
            $name = $this->di['mod_service']('system')->getParamValue('company_name');
        } catch (\Throwable) {
            $name = null;
        }

        return $name ? (string) $name : 'FOSSBilling';
    }

    /* ------------------------------------------------------------------
     * Event hooks
     * ------------------------------------------------------------------ */

    /**
     * Fired after the staff password was verified, before the session is created.
     * Throwing here aborts the login.
     */
    public static function onAfterAdminLogin(\Box_Event $event): void
    {
        $params = $event->getParameters();
        $service = $event->getDi()['mod_service']('tajhost2fa');
        $service->verifyLogin(self::TYPE_ADMIN, (int) $params['id']);
    }

    /**
     * Fired after the client password was verified, before the session is created.
     */
    public static function onAfterClientLogin(\Box_Event $event): void
    {
        $params = $event->getParameters();
        $service = $event->getDi()['mod_service']('tajhost2fa');
        $service->verifyLogin(self::TYPE_CLIENT, (int) $params['id']);
    }

    public static function onAfterAdminStaffDelete(\Box_Event $event): void
    {
        $params = $event->getParameters();
        if (isset($params['id'])) {
            $event->getDi()['mod_service']('tajhost2fa')->deleteRecord(self::TYPE_ADMIN, (int) $params['id']);
        }
    }

    public static function onAfterAdminClientDelete(\Box_Event $event): void
    {
        $params = $event->getParameters();
        if (isset($params['id'])) {
            $event->getDi()['mod_service']('tajhost2fa')->deleteRecord(self::TYPE_CLIENT, (int) $params['id']);
        }
    }

    /* ------------------------------------------------------------------
     * Login verification
     * ------------------------------------------------------------------ */

    public function verifyLogin(string $type, int $userId): void
    {
        $record = $this->getRecord($type, $userId);
        if (!$record || !(int) $record['enabled']) {
            return; // 2FA not enabled for this account
        }

        if ($type === self::TYPE_CLIENT && !$this->getSettings()['allow_clients']) {
            // Client 2FA switched off globally: do not lock existing users out.
            return;
        }

        $this->assertNotLocked($record);

        $code = $this->getSubmittedCode();
        if ($code === '') {
            throw new InformationException('Enter the 6-digit code from your authenticator app, or one of your backup codes.', null, self::ERR_CODE_REQUIRED);
        }

        if ($this->checkCode($record, $code)) {
            return;
        }

        $this->registerFailure($record);

        throw new InformationException('Invalid two-factor authentication code.', null, self::ERR_CODE_INVALID);
    }

    /**
     * Read the submitted 2FA code from the current request (form or JSON body).
     */
    public function getSubmittedCode(): string
    {
        $request = $this->di['request'];
        $code = $request->request->get(self::FIELD);

        if ($code === null) {
            $content = $request->getContent();
            if (is_string($content) && $content !== '') {
                $json = json_decode($content, true);
                if (is_array($json) && isset($json[self::FIELD]) && is_scalar($json[self::FIELD])) {
                    $code = $json[self::FIELD];
                }
            }
        }

        return is_scalar($code) ? trim((string) $code) : '';
    }

    /**
     * Validates a TOTP or a backup code against a record and consumes it.
     */
    public function checkCode(array $record, string $code): bool
    {
        $secret = $this->decryptSecret($record['secret']);
        if ($secret === null) {
            return false;
        }

        $normalized = preg_replace('/\s+/', '', $code);

        if (preg_match('/^\d{' . self::DIGITS . '}$/', (string) $normalized)) {
            $step = Totp::verify($secret, (string) $normalized, (int) $record['last_step'], self::WINDOW, self::PERIOD, self::DIGITS);
            if ($step !== null) {
                $this->di['db']->exec(
                    'UPDATE `' . self::TABLE . '` SET last_step = :step, failed_attempts = 0, locked_until = NULL, last_used_at = :now, updated_at = :now WHERE id = :id',
                    ['step' => $step, 'now' => $this->now(), 'id' => $record['id']]
                );

                return true;
            }

            return false;
        }

        // Backup code
        $backup = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($backup) !== 10) {
            return false;
        }

        $hashes = json_decode((string) $record['backup_codes'], true) ?: [];
        foreach ($hashes as $i => $hash) {
            if (password_verify($backup, (string) $hash)) {
                unset($hashes[$i]);
                $this->di['db']->exec(
                    'UPDATE `' . self::TABLE . '` SET backup_codes = :codes, failed_attempts = 0, locked_until = NULL, last_used_at = :now, updated_at = :now WHERE id = :id',
                    ['codes' => json_encode(array_values($hashes)), 'now' => $this->now(), 'id' => $record['id']]
                );
                $this->di['logger']->info(sprintf('2FA backup code used by %s #%d (%d remaining)', $record['user_type'], $record['user_id'], count($hashes)));

                return true;
            }
        }

        return false;
    }

    private function assertNotLocked(array $record): void
    {
        if (!empty($record['locked_until']) && strtotime((string) $record['locked_until']) > time()) {
            $minutes = (int) ceil((strtotime((string) $record['locked_until']) - time()) / 60);

            throw new InformationException('Too many invalid two-factor codes. Try again in :min minutes.', [':min' => $minutes], self::ERR_LOCKED);
        }
    }

    private function registerFailure(array $record): void
    {
        $failed = (int) $record['failed_attempts'] + 1;
        $lockedUntil = null;
        if ($failed >= self::MAX_FAILED_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
            $failed = 0;
            $this->di['logger']->warning(sprintf('2FA locked for %s #%d after too many invalid codes', $record['user_type'], $record['user_id']));
        }

        $this->di['db']->exec(
            'UPDATE `' . self::TABLE . '` SET failed_attempts = :f, locked_until = :l, updated_at = :now WHERE id = :id',
            ['f' => $failed, 'l' => $lockedUntil, 'now' => $this->now(), 'id' => $record['id']]
        );
    }

    /* ------------------------------------------------------------------
     * Enrolment
     * ------------------------------------------------------------------ */

    public function getStatus(string $type, int $userId): array
    {
        $record = $this->getRecord($type, $userId);
        $settings = $this->getSettings();

        return [
            'enabled' => $record && (int) $record['enabled'] === 1,
            'enabled_at' => $record['enabled_at'] ?? null,
            'last_used_at' => $record['last_used_at'] ?? null,
            'backup_codes_left' => $record ? count(json_decode((string) $record['backup_codes'], true) ?: []) : 0,
            'required' => $type === self::TYPE_ADMIN && $settings['enforce_admin'],
            'available' => $type === self::TYPE_ADMIN || $settings['allow_clients'],
        ];
    }

    /**
     * Starts enrolment: generates a new pending secret and returns the provisioning data.
     */
    public function startSetup(string $type, int $userId, string $accountLabel): array
    {
        $this->assertAvailable($type);

        $record = $this->getRecord($type, $userId);
        if ($record && (int) $record['enabled']) {
            throw new InformationException('Two-factor authentication is already enabled. Disable it first to set up a new device.');
        }

        $secret = Totp::generateSecret();
        $encrypted = $this->di['crypt']->encrypt($secret);

        if ($record) {
            $this->di['db']->exec(
                'UPDATE `' . self::TABLE . '` SET pending_secret = :s, updated_at = :now WHERE id = :id',
                ['s' => $encrypted, 'now' => $this->now(), 'id' => $record['id']]
            );
        } else {
            $this->di['db']->exec(
                'INSERT INTO `' . self::TABLE . '` (user_type, user_id, pending_secret, enabled, created_at, updated_at) VALUES (:t, :u, :s, 0, :now, :now)',
                ['t' => $type, 'u' => $userId, 's' => $encrypted, 'now' => $this->now()]
            );
        }

        $issuer = $this->getSettings()['issuer'];

        return [
            'secret' => $secret,
            'secret_formatted' => trim(chunk_split($secret, 4, ' ')),
            'otpauth_uri' => Totp::provisioningUri($secret, $accountLabel, $issuer, self::DIGITS, self::PERIOD),
            'issuer' => $issuer,
            'account' => $accountLabel,
        ];
    }

    /**
     * Confirms enrolment with a code from the app. Returns the plain backup codes (shown once).
     */
    public function confirmSetup(string $type, int $userId, string $code): array
    {
        $this->assertAvailable($type);

        $record = $this->getRecord($type, $userId);
        if (!$record || empty($record['pending_secret'])) {
            throw new InformationException('Start the setup first.');
        }
        if ((int) $record['enabled']) {
            throw new InformationException('Two-factor authentication is already enabled.');
        }

        $secret = $this->decryptSecret($record['pending_secret']);
        $code = (string) preg_replace('/\s+/', '', $code);
        $step = $secret ? Totp::verify($secret, $code, 0, self::WINDOW, self::PERIOD, self::DIGITS) : null;
        if ($step === null) {
            throw new InformationException('The code is not valid. Check that the time on your phone is correct and try again.');
        }

        [$plain, $hashes] = $this->generateBackupCodes();

        $this->di['db']->exec(
            'UPDATE `' . self::TABLE . '` SET secret = pending_secret, pending_secret = NULL, enabled = 1, backup_codes = :codes,
                last_step = :step, failed_attempts = 0, locked_until = NULL, enabled_at = :now, updated_at = :now WHERE id = :id',
            ['codes' => json_encode($hashes), 'step' => $step, 'now' => $this->now(), 'id' => $record['id']]
        );

        $this->di['logger']->info(sprintf('2FA enabled for %s #%d', $type, $userId));

        return $plain;
    }

    /**
     * Disables 2FA for the current user. Requires a valid code (TOTP or backup).
     */
    public function disableWithCode(string $type, int $userId, string $code): bool
    {
        $record = $this->requireEnabled($type, $userId);

        if ($type === self::TYPE_ADMIN && $this->getSettings()['enforce_admin']) {
            throw new InformationException('Two-factor authentication is mandatory for staff accounts and cannot be disabled.');
        }

        $this->assertNotLocked($record);
        if (!$this->checkCode($record, $code)) {
            $this->registerFailure($record);

            throw new InformationException('Invalid two-factor authentication code.');
        }

        $this->deleteRecord($type, $userId);
        $this->di['logger']->info(sprintf('2FA disabled by %s #%d', $type, $userId));

        return true;
    }

    /**
     * Replaces the backup codes. Requires a valid TOTP code.
     */
    public function regenerateBackupCodes(string $type, int $userId, string $code): array
    {
        $record = $this->requireEnabled($type, $userId);
        $this->assertNotLocked($record);

        if (!preg_match('/^\d{' . self::DIGITS . '}$/', (string) preg_replace('/\s+/', '', $code)) || !$this->checkCode($record, $code)) {
            $this->registerFailure($record);

            throw new InformationException('Enter a valid code from your authenticator app.');
        }

        [$plain, $hashes] = $this->generateBackupCodes();
        $this->di['db']->exec(
            'UPDATE `' . self::TABLE . '` SET backup_codes = :codes, updated_at = :now WHERE id = :id',
            ['codes' => json_encode($hashes), 'now' => $this->now(), 'id' => $record['id']]
        );

        return $plain;
    }

    /**
     * Admin action: removes 2FA from an account (e.g. the user lost their phone).
     */
    public function resetFor(string $type, int $userId): bool
    {
        $this->deleteRecord($type, $userId);
        $this->di['logger']->warning(sprintf('2FA reset by staff for %s #%d', $type, $userId));

        return true;
    }

    public function listEnabled(string $type): array
    {
        $table = $type === self::TYPE_ADMIN ? 'admin' : 'client';
        $nameSql = $type === self::TYPE_ADMIN ? 'u.name' : "CONCAT_WS(' ', u.first_name, u.last_name)";

        return $this->di['db']->getAll(
            'SELECT t.user_id AS id, u.email, ' . $nameSql . ' AS name, t.enabled_at, t.last_used_at, t.backup_codes
             FROM `' . self::TABLE . '` t JOIN `' . $table . '` u ON u.id = t.user_id
             WHERE t.user_type = :t AND t.enabled = 1 ORDER BY t.enabled_at DESC',
            ['t' => $type]
        );
    }

    public function getStaffOverview(): array
    {
        // The admin table changed across FOSSBilling versions (0.8.3 has `role`, 0.8.7+ uses `system_name`).
        $columns = array_column($this->di['db']->getAll('SHOW COLUMNS FROM `admin`'), 'Field');
        if (in_array('role', $columns, true)) {
            $extra = 'a.role';
            $where = "a.role != 'cron'";
        } elseif (in_array('system_name', $columns, true)) {
            $extra = 'NULL AS role';
            $where = "(a.system_name IS NULL OR a.system_name != 'cron')";
        } else {
            $extra = 'NULL AS role';
            $where = '1=1';
        }

        $rows = $this->di['db']->getAll(
            'SELECT a.id, a.name, a.email, a.status, ' . $extra . ', t.enabled, t.enabled_at, t.last_used_at
             FROM `admin` a LEFT JOIN `' . self::TABLE . "` t ON t.user_id = a.id AND t.user_type = 'admin'
             WHERE " . $where . ' ORDER BY a.id'
        );

        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'email' => $r['email'],
            'role' => $r['role'],
            'status' => $r['status'],
            'enabled' => (int) ($r['enabled'] ?? 0) === 1,
            'enabled_at' => $r['enabled_at'],
            'last_used_at' => $r['last_used_at'],
        ], $rows);
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public function getRecord(string $type, int $userId): ?array
    {
        $row = $this->di['db']->getRow(
            'SELECT * FROM `' . self::TABLE . '` WHERE user_type = :t AND user_id = :u',
            ['t' => $type, 'u' => $userId]
        );

        return $row ?: null;
    }

    public function deleteRecord(string $type, int $userId): void
    {
        $this->di['db']->exec(
            'DELETE FROM `' . self::TABLE . '` WHERE user_type = :t AND user_id = :u',
            ['t' => $type, 'u' => $userId]
        );
    }

    private function requireEnabled(string $type, int $userId): array
    {
        $record = $this->getRecord($type, $userId);
        if (!$record || !(int) $record['enabled']) {
            throw new InformationException('Two-factor authentication is not enabled.');
        }

        return $record;
    }

    private function assertAvailable(string $type): void
    {
        if ($type === self::TYPE_CLIENT && !$this->getSettings()['allow_clients']) {
            throw new InformationException('Two-factor authentication is not available for client accounts.');
        }
    }

    private function decryptSecret(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }
        $secret = $this->di['crypt']->decrypt($encrypted);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * @return array{0: string[], 1: string[]} [plain codes for display, password hashes for storage]
     */
    private function generateBackupCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; ++$i) {
            $code = '';
            for ($j = 0; $j < 10; ++$j) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = substr($code, 0, 5) . '-' . substr($code, 5);
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }

        return [$plain, $hashes];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
