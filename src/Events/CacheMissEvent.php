<?php
namespace FallegaHQ\ApiResponder\Events;

class CacheMissEvent{
    public function __construct(public string $key){}
}
