<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class UseDto{
    public function __construct(public string $dtoClass){}
}
