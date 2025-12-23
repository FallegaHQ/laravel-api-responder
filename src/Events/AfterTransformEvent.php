<?php
namespace FallegaHQ\ApiResponder\Events;

class AfterTransformEvent{
    public function __construct(public mixed  $source,
                                public array  $result,
                                public string $dtoClass
    ){}
}
