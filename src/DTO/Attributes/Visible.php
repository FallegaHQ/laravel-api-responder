<?php
namespace FallegaHQ\ApiResponder\DTO\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
class Visible{
    public array $roles;

    public function __construct(string|array $roles){
        $this->roles = is_array($roles) ? $roles : [$roles];
    }
}
