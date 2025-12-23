<?php
namespace FallegaHQ\ApiResponder\Http;

use FallegaHQ\ApiResponder\Contracts\FieldsetParserInterface;
use Illuminate\Http\Request;

class FieldsetParser implements FieldsetParserInterface{
    protected array  $config;
    protected ?array $fields   = null;
    protected ?array $includes = null;
    protected ?array $excludes = null;

    public function __construct(){
        $this->config = config('api-responder.sparse_fieldsets');
    }

    public function parse(Request $request): array{
        if(!$this->config['enabled']){
            return [
                'fields'   => null,
                'includes' => [],
                'excludes' => [],
            ];
        }
        // Parse fields: ?fields=id,name,email
        if($request->has($this->config['query_param'])){
            $this->fields = explode(',', $request->get($this->config['query_param']));
            $this->fields = array_map('trim', $this->fields);
        }
        // Parse includes: ?include=posts,comments
        if($request->has($this->config['include_param'])){
            $this->includes = explode(',', $request->get($this->config['include_param']));
            $this->includes = array_map('trim', $this->includes);
        }
        // Parse excludes: ?exclude=password,secret
        if($request->has($this->config['exclude_param'])){
            $this->excludes = explode(',', $request->get($this->config['exclude_param']));
            $this->excludes = array_map('trim', $this->excludes);
        }

        return [
            'fields'   => $this->fields,
            'includes' => $this->includes ?? [],
            'excludes' => $this->excludes ?? [],
        ];
    }

    public function shouldIncludeField(string $field): bool{
        // If excludes are set and field is excluded
        if($this->excludes && in_array($field, $this->excludes, true)){
            return false;
        }
        // If fields are set, only include specified fields
        if($this->fields){
            return in_array($field, $this->fields, true);
        }

        return true;
    }

    public function getIncludedRelations(): array{
        return $this->includes ?? [];
    }
}
