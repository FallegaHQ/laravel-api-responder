<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiDescription{
    public function __construct(public string  $summary,
                                public ?string $description = null,
                                public bool    $requiresAuth = false
    ){}
}
