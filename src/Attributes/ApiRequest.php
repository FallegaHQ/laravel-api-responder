<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiRequest{
    public function __construct(public ?string $dto = null,
                                public ?string $description = null,
                                public array   $fields = []
    ){}
}
