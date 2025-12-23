<?php
namespace FallegaHQ\ApiResponder\Events;

class CacheHitEvent{
    public function __construct(public string $key,
                                public mixed  $value
    ){}
}
