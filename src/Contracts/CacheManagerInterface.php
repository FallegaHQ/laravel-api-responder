<?php
namespace FallegaHQ\ApiResponder\Contracts;

interface CacheManagerInterface{
    public function remember(string $key, int $ttl, callable $callback): mixed;

    public function forget(string $key): bool;

    public function generateKey(string $context, array $params = []): string;
}
