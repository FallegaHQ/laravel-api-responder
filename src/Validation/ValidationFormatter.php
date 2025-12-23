<?php
namespace FallegaHQ\ApiResponder\Validation;

use FallegaHQ\ApiResponder\Contracts\ValidationFormatterInterface;
use Illuminate\Contracts\Validation\Validator;

class ValidationFormatter implements ValidationFormatterInterface{
    public function format(Validator $validator): array{
        return array_map(
            static function($messages){
                return $messages;
            },
            $validator->errors()
                      ->messages()
        );
    }
}
