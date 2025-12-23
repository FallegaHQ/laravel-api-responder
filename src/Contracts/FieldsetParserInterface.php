<?php
namespace FallegaHQ\ApiResponder\Contracts;

use Illuminate\Http\Request;

interface FieldsetParserInterface{
    public function parse(Request $request): array;

    public function shouldIncludeField(string $field): bool;

    public function getIncludedRelations(): array;
}
