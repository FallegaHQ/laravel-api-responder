<?php
namespace FallegaHQ\ApiResponder\DTO\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
class Translatable{
    public function __construct(public ?string $locale = null){}
}
