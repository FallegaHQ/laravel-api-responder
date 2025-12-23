<?php
namespace FallegaHQ\ApiResponder\Contracts;

interface VisibilityResolverInterface{
    public function canSeeField(string $field, array $allowedRoles, $user = null): bool;

    public function getUserRoles($user): array;
}
