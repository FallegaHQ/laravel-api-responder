<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ApiDeprecated{
    public function __construct(public ?string $reason = null,
                                public ?string $since = null,
                                public ?string $replacedBy = null
    ){}
}
