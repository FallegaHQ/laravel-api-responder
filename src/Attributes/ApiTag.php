<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ApiTag{
    public array $tags;

    public function __construct(string|array $tags){
        $this->tags = is_array($tags) ? $tags : [$tags];
    }
}
