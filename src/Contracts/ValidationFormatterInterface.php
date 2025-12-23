<?php
namespace FallegaHQ\ApiResponder\Contracts;

use Illuminate\Contracts\Validation\Validator;

interface ValidationFormatterInterface{
    public function format(Validator $validator): array;
}
