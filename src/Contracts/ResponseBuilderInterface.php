<?php
namespace FallegaHQ\ApiResponder\Contracts;

interface ResponseBuilderInterface{
    public function success($data = null, ?string $message = null, int $status = 200): mixed;

    public function error(?string $message = null, $errors = null, int $status = 400): mixed;

    public function created($data = null, ?string $message = null): mixed;

    public function noContent(): mixed;

    public function withMeta(array $meta): static;

    public function withHeaders(array $headers): static;

    public function withDto(string $dtoClass): static;
}
