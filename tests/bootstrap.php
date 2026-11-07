<?php

declare(strict_types=1);

namespace Pimple {
    class Container implements \ArrayAccess
    {
        private array $items = [];

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists($offset, $this->items);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->items[$offset];
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->items[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->items[$offset]);
        }
    }
}

namespace FOSSBilling {
    /**
     * Matches FOSSBilling 0.8.9 src/library/FOSSBilling/InjectionAwareInterface.php.
     * Commit e594f8a5f91ea4cc905708f8dc1e42ccb752ed21. The interface declares setDi only.
     */
    interface InjectionAwareInterface
    {
        public function setDi(\Pimple\Container $di): void;
    }

    class InformationException extends \RuntimeException
    {
        public function __construct(string $message = '', mixed $data = null, int $code = 0)
        {
            parent::__construct($message, $code);
        }
    }
}

namespace FOSSBilling\Interfaces {
    /**
     * Matches FOSSBilling 0.8.9 src/library/FOSSBilling/Interfaces/WidgetProviderInterface.php.
     * Commit e594f8a5f91ea4cc905708f8dc1e42ccb752ed21.
     */
    interface WidgetProviderInterface
    {
        public function getWidgets(): array;
    }
}

namespace FOSSBilling\Api {
    /**
     * Signature double for FOSSBilling 0.8.9 src/library/FOSSBilling/Api/AbstractApi.php.
     * Commit e594f8a5f91ea4cc905708f8dc1e42ccb752ed21.
     * The real class body is not loaded here. Method signatures match that file.
     */
    abstract class AbstractApi implements \FOSSBilling\InjectionAwareInterface
    {
        public object $service;
        public object $identity;
        public array $checks = [];

        public function setDi(\Pimple\Container $di): void
        {
        }

        public function getDi(): ?\Pimple\Container
        {
            return null;
        }

        public function setMod($mod): void
        {
        }

        public function getMod()
        {
        }

        public function setIdentity($identity): void
        {
            $this->identity = $identity;
        }

        public function getIdentity()
        {
            return $this->identity;
        }

        public function setService($service): void
        {
            $this->service = $service;
        }

        public function getService()
        {
            return $this->service;
        }

        public function setIp($ip): void
        {
        }

        public function getIp()
        {
        }

        protected function checkPermissions(string $module, ?string $key = null, mixed $constraint = null): void
        {
            $this->checks[] = [$module, $key];
        }
    }
}

namespace FOSSBilling\Validation\Api {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class RequiredParams
    {
        public function __construct(public array $params = [])
        {
        }
    }
}

namespace {
    function __trans(string $message): string
    {
        return $message;
    }

    class Box_Event
    {
        public function __construct(private array $parameters, private mixed $di)
        {
        }

        public function getParameters(): array
        {
            return $this->parameters;
        }

        public function getDi(): mixed
        {
            return $this->di;
        }
    }

    require dirname(__DIR__) . '/src/Totp.php';
    require dirname(__DIR__) . '/src/Service.php';
    require dirname(__DIR__) . '/src/Api/Admin.php';
}
