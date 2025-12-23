<?php
namespace FallegaHQ\ApiResponder\DTO\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ComputedField{
    public function __construct(public ?string $name = null){}
}
