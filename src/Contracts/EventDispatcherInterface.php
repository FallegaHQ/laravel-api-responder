<?php
namespace FallegaHQ\ApiResponder\Contracts;

interface EventDispatcherInterface{
    public function beforeTransform($source): void;

    public function afterTransform($source, array $result): void;

    public function onCacheHit(string $key): void;

    public function onCacheMiss(string $key): void;
}
