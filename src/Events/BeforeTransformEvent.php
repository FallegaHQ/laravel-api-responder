<?php
namespace FallegaHQ\ApiResponder\Events;

class BeforeTransformEvent{
    public function __construct(public mixed  $source,
                                public string $dtoClass
    ){}
}
