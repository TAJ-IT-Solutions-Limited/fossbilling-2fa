<?php

declare(strict_types=1);
/**
 * TAJHOST Two-Factor Authentication for FOSSBilling.
 *
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Tajhost2fa\Api;

use Box\Mod\Tajhost2fa\Service;
use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /* ---------------- Own account ---------------- */

    /**
     * 2FA status of the logged-in staff member.
     */
    public function status(): array
    {
        return $this->getService()->getStatus(Service::TYPE_ADMIN, (int) $this->getIdentity()->id);
    }

    /**
     * Start enrolment. Returns secret + otpauth:// URI for the QR code.
     */
    public function setup_start(): array
    {
        $admin = $this->getIdentity();

        return $this->getService()->startSetup(Service::TYPE_ADMIN, (int) $admin->id, (string) $admin->email);
    }

    /**
     * Confirm enrolment with a 6-digit code. Returns the backup codes (shown once).
     */
    #[RequiredParams(['code' => 'Code required'])]
    public function setup_confirm($data): array
    {
        return [
            'backup_codes' => $this->getService()->confirmSetup(Service::TYPE_ADMIN, (int) $this->getIdentity()->id, (string) $data['code']),
        ];
    }

    #[RequiredParams(['code' => 'Code required'])]
    public function disable($data): bool
    {
        return $this->getService()->disableWithCode(Service::TYPE_ADMIN, (int) $this->getIdentity()->id, (string) $data['code']);
    }

    #[RequiredParams(['code' => 'Code required'])]
    public function backup_codes_regenerate($data): array
    {
        return [
            'backup_codes' => $this->getService()->regenerateBackupCodes(Service::TYPE_ADMIN, (int) $this->getIdentity()->id, (string) $data['code']),
        ];
    }

    /* ---------------- Management (requires "manage" permission) ---------------- */

    public function settings(): array
    {
        return $this->getService()->getSettings();
    }

    public function staff_overview(): array
    {
        $this->checkPermissions('tajhost2fa', 'manage');

        return $this->getService()->getStaffOverview();
    }

    public function client_list(): array
    {
        $this->checkPermissions('tajhost2fa', 'manage');

        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'name' => $r['name'],
            'enabled_at' => $r['enabled_at'],
            'last_used_at' => $r['last_used_at'],
        ], $this->getService()->listEnabled(Service::TYPE_CLIENT));
    }

    #[RequiredParams(['id' => 'Staff ID required'])]
    public function staff_reset($data): bool
    {
        $this->checkPermissions('tajhost2fa', 'manage');

        return $this->getService()->resetFor(Service::TYPE_ADMIN, (int) $data['id']);
    }

    #[RequiredParams(['id' => 'Client ID required'])]
    public function client_reset($data): bool
    {
        $this->checkPermissions('tajhost2fa', 'manage');

        return $this->getService()->resetFor(Service::TYPE_CLIENT, (int) $data['id']);
    }

    /**
     * Status of any client (used on the client management page).
     */
    #[RequiredParams(['id' => 'Client ID required'])]
    public function client_status($data): array
    {
        $this->checkPermissions('tajhost2fa', 'manage');

        return $this->getService()->getStatus(Service::TYPE_CLIENT, (int) $data['id']);
    }
}
