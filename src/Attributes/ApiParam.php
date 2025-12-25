<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiParam{
    public function __construct(public string  $name,
                                public string  $type = 'string',
                                public ?string $description = null,
                                public bool    $required = true,
                                public mixed   $example = null,
                                public ?string $format = null,
                                public ?int    $minimum = null,
                                public ?int    $maximum = null,
                                public ?array  $enum = null
    ){}
}
