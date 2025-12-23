<?php
namespace FallegaHQ\ApiResponder\DTO\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
class Cached{
    public function __construct(public int     $ttl = 3600,
                                public ?string $key = null
    ){}
}
