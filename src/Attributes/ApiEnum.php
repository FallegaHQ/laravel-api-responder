<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ApiEnum{
    public function __construct(public array   $values,
                                public ?string $description = null
    ){}
}
