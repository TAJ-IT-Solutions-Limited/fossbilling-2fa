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
    interface InjectionAwareInterface
    {
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
    interface WidgetProviderInterface
    {
    }
}

namespace FOSSBilling\Api {
    abstract class AbstractApi
    {
        public object $service;
        public object $identity;
        public array $checks = [];

        public function checkPermissions(string $module, string $permission): void
        {
            $this->checks[] = [$module, $permission];
        }

        public function getService(): object
        {
            return $this->service;
        }

        public function getIdentity(): object
        {
            return $this->identity;
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
