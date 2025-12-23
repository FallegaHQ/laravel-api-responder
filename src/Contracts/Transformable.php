<?php
namespace FallegaHQ\ApiResponder\Contracts;

interface Transformable{
    public function toArray($user = null): array;
}
