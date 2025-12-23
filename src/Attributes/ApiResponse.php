<?php
namespace FallegaHQ\ApiResponder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiResponse{
    public function __construct(
        public ?string $model = null,
        public string $type = 'single',
        public ?string $description = null,
        public array $statusCodes = [200]
    ){}
}
