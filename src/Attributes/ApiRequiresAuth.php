<?php

namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ApiRequiresAuth
{
    public function __construct(
        public bool $requiresAuth = true
    ) {}
}
