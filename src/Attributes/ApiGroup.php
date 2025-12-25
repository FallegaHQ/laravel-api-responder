<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ApiGroup{
    public function __construct(public string  $name,
                                public ?string $description = null,
                                public int     $priority = 0
    ){}
}
