<?php
namespace FallegaHQ\ApiResponder\DTO\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Relationship{
    public function __construct(public string $name,
                                public bool   $eager = false
    ){}
}
