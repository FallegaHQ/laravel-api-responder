<?php
namespace FallegaHQ\ApiResponder\DTO\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
class Versioned{
    public array $versions;

    public function __construct(string|array $versions){
        $this->versions = is_array($versions) ? $versions : [$versions];
    }
}
