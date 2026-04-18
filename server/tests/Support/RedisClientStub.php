<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests\Support;

use Predis\Client;

/**
 * Predis\Client dispatches all Redis commands through __call(), so reflection
 * sees none of them. PHPUnit 12 removed MockBuilder::addMethods(), leaving
 * onlyMethods() as the only option — but onlyMethods() requires the methods to
 * exist on the target class. This subclass exposes the commands the suite
 * mocks as concrete no-ops so onlyMethods() can override them.
 */
class RedisClientStub extends Client
{
    public function setex($key, $ttl, $value): mixed { return null; }
    public function get($key): mixed { return null; }
    public function incr($key): mixed { return null; }
    public function incrby($key, $amount): mixed { return null; }
    public function expire($key, $seconds): mixed { return null; }
    public function ping(...$args): mixed { return null; }
    public function keys($pattern): mixed { return null; }
    public function mget(...$keys): mixed { return null; }
    public function eval(...$args): mixed { return null; }
}
