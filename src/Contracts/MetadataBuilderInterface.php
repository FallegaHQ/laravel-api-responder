<?php
namespace FallegaHQ\ApiResponder\Contracts;

interface MetadataBuilderInterface{
    public function build(): array;

    public function addTimestamps(): self;

    public function addRequestId(): self;

    public function addVersion(): self;

    public function addExecutionTime(float $startTime): self;

    public function addRateLimiting(array $limits): self;
}
