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

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Works with both the legacy Model_Client identity and the Doctrine Client entity (FOSSBilling >= 0.8.x).
     */
    private function clientId(): int
    {
        $identity = $this->getIdentity();

        return (int) (method_exists($identity, 'getId') ? $identity->getId() : $identity->id);
    }

    private function clientEmail(): string
    {
        $identity = $this->getIdentity();

        return (string) (method_exists($identity, 'getEmail') ? $identity->getEmail() : $identity->email);
    }

    public function status(): array
    {
        return $this->getService()->getStatus(Service::TYPE_CLIENT, $this->clientId());
    }

    public function setup_start(): array
    {
        return $this->getService()->startSetup(Service::TYPE_CLIENT, $this->clientId(), $this->clientEmail());
    }

    #[RequiredParams(['code' => 'Code required'])]
    public function setup_confirm($data): array
    {
        return [
            'backup_codes' => $this->getService()->confirmSetup(Service::TYPE_CLIENT, $this->clientId(), (string) $data['code']),
        ];
    }

    #[RequiredParams(['code' => 'Code required'])]
    public function disable($data): bool
    {
        return $this->getService()->disableWithCode(Service::TYPE_CLIENT, $this->clientId(), (string) $data['code']);
    }

    #[RequiredParams(['code' => 'Code required'])]
    public function backup_codes_regenerate($data): array
    {
        return [
            'backup_codes' => $this->getService()->regenerateBackupCodes(Service::TYPE_CLIENT, $this->clientId(), (string) $data['code']),
        ];
    }
}
