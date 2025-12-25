<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiFileUpload{
    public function __construct(public string  $name,
                                public ?string $description = null,
                                public bool    $required = true,
                                public ?array  $allowedMimeTypes = null,
                                public ?int    $maxSizeKb = null,
                                public bool    $multiple = false
    ){}
}
