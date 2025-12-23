<?php
namespace FallegaHQ\ApiResponder\Console\Commands;

use FallegaHQ\ApiResponder\Attributes\ApiRequest;
use FallegaHQ\ApiResponder\Attributes\ApiResponse;
use FallegaHQ\ApiResponder\Attributes\UseDto;
use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class GenerateDocumentationCommand extends Command{
    protected $signature   = 'api:generate-docs {--output=api-docs.json}';
    protected $description = 'Generate API documentation from routes and DTOs';

    /**
     * @throws \JsonException
     */
    public function handle(): void{
        $this->info('Scanning routes and DTOs...');
        $routes        = $this->getApiRoutes();
        $dtos          = $this->scanDtos();
        $documentation = $this->buildDocumentation($routes, $dtos);
        $outputPath    = base_path($this->option('output'));
        file_put_contents($outputPath, json_encode($documentation, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->info("API documentation generated: $outputPath");
        $this->info("Found " . count($routes) . " routes and {$dtos->count()} DTOs");
    }

    protected function getApiRoutes(){
        $apiPrefix = config('api-responder.routing.prefix', 'api');
        $apiPrefix = trim($apiPrefix, '/');

        return collect(Route::getRoutes())
            ->filter(
                function($route) use ($apiPrefix){
                    if(empty($apiPrefix)){
                        return true; // Include all routes if no prefix
                    }

                    return str_starts_with($route->uri(), $apiPrefix . '/') || $route->uri() === $apiPrefix;
                }
            )
            ->map(
                fn($route) => [
                    'method' => implode('|', $route->methods()),
                    'uri'    => $route->uri(),
                    'name'   => $route->getName(),
                    'action' => $route->getActionName(),
                ]
            )
            ->values()
            ->toArray();
    }

    protected function scanDtos(): Collection{
        $dtos = collect();
        // Scan app/Models for UseDto attributes
        $modelsPath = app_path('Models');
        if(!is_dir($modelsPath)){
            return $dtos;
        }
        $files = glob($modelsPath . '/*.php');
        foreach($files as $file){
            $className = 'App\\Models\\' . basename($file, '.php');
            // Try to load the class if it's not already loaded
            if(!class_exists($className, false)){
                try{
                    require_once $file;
                }
                catch(Throwable $e){
                    // Skip files that can't be loaded
                    continue;
                }
            }
            if(class_exists($className, false)){
                try{
                    $reflection = new ReflectionClass($className);
                    $attributes = $reflection->getAttributes(UseDto::class);
                    if(!empty($attributes)){
                        $attribute = $attributes[0]->newInstance();
                        $dtos->push(
                            [
                                'model' => $className,
                                'dto'   => $attribute->dtoClass,
                            ]
                        );
                    }
                }
                catch(Throwable $e){
                    // Skip classes that can't be reflected
                    continue;
                }
            }
        }

        return $dtos;
    }

    protected function buildDocumentation($routes, $dtos): array{
        $schemas        = $this->buildSchemas($dtos);
        $requestSchemas = [];
        $paths          = $this->buildPaths($routes, $schemas, $requestSchemas);

        return [
            'openapi'    => '3.0.0',
            'info'       => [
                'title'       => config('app.name') . ' API',
                'version'     => config('api-responder.metadata.api_version'),
                'description' => $this->buildDescription(),
            ],
            'servers'    => [
                ['url' => config('app.url')],
            ],
            'paths'      => $paths,
            'components' => [
                'schemas'   => array_merge(
                    $schemas,
                    $requestSchemas,
                    $this->buildResponseShapes(),
                    $this->buildErrorSchemas()
                ),
                'responses' => $this->buildGlobalResponses(),
            ],
        ];
    }

    protected function buildSchemas($dtos): array{
        $schemas = [];
        foreach($dtos as $dtoInfo){
            $dtoClass = $dtoInfo['dto'];
            if(!class_exists($dtoClass)){
                continue;
            }
            $reflection = new ReflectionClass($dtoClass);
            $schemaName = class_basename($dtoClass);
            $properties = [];
            // Get computed fields from DTO
            foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
                if(str_starts_with($method->getName(), '__') || $method->getDeclaringClass()
                                                                       ->getName() !== $dtoClass){
                    continue;
                }
                $attributes = $method->getAttributes(ComputedField::class);
                if(!empty($attributes)){
                    $computedAttr           = $attributes[0]->newInstance();
                    $fieldName              = $computedAttr->name ?? $this->getFieldName($method->getName());
                    $properties[$fieldName] = [
                        'type'        => $this->inferType($method),
                        'description' => 'Computed field from ' . $method->getName(),
                    ];
                }
            }
            // Add note about model attributes
            $schemas[$schemaName] = [
                'type'        => 'object',
                'description' => 'DTO for ' . class_basename(
                        $dtoInfo['model']
                    ) . ' (includes all model attributes + computed fields)',
                'properties'  => $properties,
            ];
        }

        return $schemas;
    }

    protected function getFieldName(string $methodName): string{
        if(str_starts_with($methodName, 'get')){
            $methodName = substr($methodName, 3);
        }

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $methodName));
    }

    protected function inferType(ReflectionMethod $method): string{
        $returnType = $method->getReturnType();
        if($returnType === null){
            return 'string';
        }
        $typeName = $returnType->getName();

        return match ($typeName) {
            'int'   => 'integer',
            'bool'  => 'boolean',
            'float' => 'number',
            'array' => 'array',
            default => 'string',
        };
    }

    protected function buildPaths(array $routes, array $schemas, array &$requestSchemas): array{
        $paths = [];
        foreach($routes as $route){
            $path         = '/' . $route['uri'];
            $methods      = explode('|', strtolower($route['method']));
            $responseInfo = $this->extractResponseInfo($route);
            $requestInfo  = $this->extractRequestInfo($route);
            foreach($methods as $method){
                if($method === 'head'){
                    continue;
                }
                $operation = [
                    'summary'     => $this->generateSummary($route, $method),
                    'description' => $this->generateDescription($route, $method, $responseInfo),
                    'tags'        => $this->extractTags($path),
                    'parameters'  => $this->extractParameters($path),
                    'responses'   => $this->buildResponses($method, $schemas, $responseInfo),
                ];
                if(in_array(
                    $method,
                    [
                        'post',
                        'put',
                        'patch',
                    ]
                )){
                    $operation['requestBody'] = $this->buildRequestBody(
                        $route,
                        $schemas,
                        $requestInfo,
                        $requestSchemas
                    );
                }
                $paths[$path][$method] = $operation;
            }
        }

        return $paths;
    }

    protected function extractResponseInfo(array $route): ?array{
        $action = $route['action'];
        if(!str_contains($action, '@')){
            return null;
        }
        [
            $controller,
            $method,
        ] = explode('@', $action);
        if(!class_exists($controller)){
            return null;
        }
        try{
            $reflection = new ReflectionClass($controller);
            if(!$reflection->hasMethod($method)){
                return null;
            }
            $methodReflection = $reflection->getMethod($method);
            $attributes       = $methodReflection->getAttributes(ApiResponse::class);
            if(empty($attributes)){
                return null;
            }
            $apiResponse = $attributes[0]->newInstance();

            return [
                'model'       => $apiResponse->model,
                'type'        => $apiResponse->type,
                'description' => $apiResponse->description,
                'statusCodes' => $apiResponse->statusCodes,
            ];
        }
        catch(Throwable $e){
            return null;
        }
    }

    protected function extractRequestInfo(array $route): ?array{
        $action = $route['action'];
        if(!str_contains($action, '@')){
            return null;
        }
        [
            $controller,
            $method,
        ] = explode('@', $action);
        try{
            $reflection = new ReflectionClass($controller);
            if(!$reflection->hasMethod($method)){
                return null;
            }
            $methodReflection = $reflection->getMethod($method);
            $attributes       = $methodReflection->getAttributes(ApiRequest::class);
            if(empty($attributes)){
                return null;
            }
            $apiRequest = $attributes[0]->newInstance();

            return [
                'dto'         => $apiRequest->dto,
                'description' => $apiRequest->description,
                'fields'      => $apiRequest->fields,
            ];
        }
        catch(Throwable $e){
            return null;
        }
    }

    protected function generateSummary(array $route, string $method): string{
        if($route['name']){
            return ucfirst(
                str_replace(
                    [
                        '.',
                        '_',
                    ],
                    ' ',
                    $route['name']
                )
            );
        }
        $action = match ($method) {
            'get'          => 'Retrieve',
            'post'         => 'Create',
            'put', 'patch' => 'Update',
            'delete'       => 'Delete',
            default        => 'Handle',
        };

        return $action . ' ' . $route['uri'];
    }

    protected function generateDescription(array $route, string $method, ?array $responseInfo = null): string{
        $descriptions    = [
            'get'    => 'Retrieves resource(s). Returns data wrapped in success response with metadata.',
            'post'   => 'Creates a new resource. Returns created resource with 201 status.',
            'put'    => 'Updates an existing resource. Returns updated resource.',
            'patch'  => 'Partially updates an existing resource. Returns updated resource.',
            'delete' => 'Deletes a resource. Returns 204 No Content on success.',
        ];
        $baseDescription = $descriptions[$method] ?? 'Handles the request.';
        if($responseInfo){
            $modelName       = $responseInfo['model'] ? class_basename($responseInfo['model']) : 'resource';
            $typeDescription = match ($responseInfo['type']) {
                'single'     => 'Returns a single ' . $modelName,
                'collection' => 'Returns a collection of ' . $modelName . ' items',
                'paginated'  => 'Returns a paginated list of ' . $modelName . ' items',
                default      => 'Returns data',
            };
            $baseDescription .= "\n\n**Response Type:** " . $typeDescription;
            if($responseInfo['description']){
                $baseDescription .= "\n\n" . $responseInfo['description'];
            }
        }

        return $baseDescription;
    }

    protected function extractTags(string $path): array{
        $parts = explode('/', trim($path, '/'));

        return !empty($parts[1]) ? [ucfirst($parts[1])] : ['API'];
    }

    protected function extractParameters(string $path): array{
        $parameters = [];
        preg_match_all('/\{([^}]+)}/', $path, $matches);
        foreach($matches[1] as $param){
            $parameters[] = [
                'name'        => $param,
                'in'          => 'path',
                'required'    => true,
                'schema'      => ['type' => 'string'],
                'description' => 'The ' . $param . ' identifier',
            ];
        }

        return $parameters;
    }

    protected function buildResponses(string $method, array $schemas, ?array $responseInfo = null): array{
        $successSchema = [
            'type'       => 'object',
            'properties' => [
                'success' => [
                    'type'        => 'boolean',
                    'description' => 'Indicates if the request was successful',
                    'example'     => true,
                ],
                'message' => [
                    'type'        => 'string',
                    'description' => 'Optional success message',
                ],
                'data'    => [
                    'type'        => 'object',
                    'description' => 'Response data',
                ],
                'meta'    => ['$ref' => '#/components/schemas/ResponseMeta'],
            ],
            'required'   => [
                'success',
                'data',
                'meta',
            ],
        ];
        if($responseInfo && $responseInfo['model']){
            $dtoClass = $this->findDtoForModel($responseInfo['model']);
            if($dtoClass){
                $schemaName    = class_basename($dtoClass);
                $successSchema = match ($responseInfo['type']) {
                    'single'     => [
                        'type'       => 'object',
                        'properties' => [
                            'success' => [
                                'type'    => 'boolean',
                                'example' => true,
                            ],
                            'message' => [
                                'type' => 'string',
                            ],
                            'data'    => ['$ref' => '#/components/schemas/' . $schemaName],
                            'meta'    => ['$ref' => '#/components/schemas/ResponseMeta'],
                        ],
                        'required'   => [
                            'success',
                            'data',
                            'meta',
                        ],
                    ],
                    'collection' => [
                        'type'       => 'object',
                        'properties' => [
                            'success' => [
                                'type'    => 'boolean',
                                'example' => true,
                            ],
                            'message' => [
                                'type' => 'string',
                            ],
                            'data'    => [
                                'type'  => 'array',
                                'items' => ['$ref' => '#/components/schemas/' . $schemaName],
                            ],
                            'meta'    => ['$ref' => '#/components/schemas/ResponseMeta'],
                        ],
                        'required'   => [
                            'success',
                            'data',
                            'meta',
                        ],
                    ],
                    'paginated'  => [
                        'type'       => 'object',
                        'properties' => [
                            'success' => [
                                'type'    => 'boolean',
                                'example' => true,
                            ],
                            'data'    => [
                                'type'  => 'array',
                                'items' => ['$ref' => '#/components/schemas/' . $schemaName],
                            ],
                            'meta'    => ['$ref' => '#/components/schemas/ResponseMeta'],
                            'links'   => ['$ref' => '#/components/schemas/PaginationLinks'],
                        ],
                        'required'   => [
                            'success',
                            'data',
                            'meta',
                            'links',
                        ],
                    ],
                    default      => $successSchema,
                };
            }
        }
        $responses = [
            '200' => [
                'description' => 'Successful response',
                'content'     => [
                    'application/json' => [
                        'schema' => $successSchema,
                    ],
                ],
            ],
        ];
        if($method === 'post'){
            $responses['201'] = [
                'description' => 'Resource created successfully',
                'content'     => [
                    'application/json' => [
                        'schema' => $successSchema,
                    ],
                ],
            ];
        }
        if($method === 'delete'){
            $responses['204'] = [
                'description' => 'Resource deleted successfully (no content)',
            ];
        }

        return $responses;
    }

    protected function findDtoForModel(string $modelClass): ?string{
        if(!class_exists($modelClass)){
            return null;
        }
        try{
            $reflection = new ReflectionClass($modelClass);
            $attributes = $reflection->getAttributes(UseDto::class);
            if(!empty($attributes)){
                return $attributes[0]->newInstance()->dtoClass;
            }
        }
        catch(Throwable $e){
            // Ignore
        }

        return null;
    }

    protected function buildRequestBody(array  $route,
                                        array  $schemas,
                                        ?array $requestInfo = null,
                                        array  &$requestSchemas = []
    ): array{
        $dataSchema = [
            'type'        => 'object',
            'description' => 'Request data',
        ];
        if($requestInfo){
            if($requestInfo['dto'] && class_exists($requestInfo['dto'])){
                $schemaName = class_basename($requestInfo['dto']);
                $dataSchema = ['$ref' => '#/components/schemas/' . $schemaName];
            }
            elseif(!empty($requestInfo['fields'])){
                // Generate a schema name based on route action
                $action = $route['action'] ?? '';
                if(str_contains($action, '@')){
                    [
                        $controller,
                        $method,
                    ] = explode('@', $action);
                    $schemaName = class_basename($controller) . ucfirst($method) . 'Request';
                }
                else{
                    $schemaName = 'Request' . md5(json_encode($requestInfo['fields']));
                }
                $properties = [];
                foreach($requestInfo['fields'] as $field => $config){
                    if(is_string($config)){
                        $properties[$config] = [
                            'type'        => 'string',
                            'description' => 'Field: ' . $config,
                        ];
                    }
                    else{
                        $properties[$field] = $config;
                    }
                }
                // Store the schema definition
                $requestSchemas[$schemaName] = [
                    'type'        => 'object',
                    'properties'  => $properties,
                    'description' => $requestInfo['description'] ?? 'Request payload',
                ];
                $dataSchema                  = ['$ref' => '#/components/schemas/' . $schemaName];
            }
        }
        $description = $requestInfo['description'] ?? 'Request payload';

        return [
            'required'    => true,
            'description' => $description,
            'content'     => [
                'application/json' => [
                    'schema' => $dataSchema,
                ],
            ],
        ];
    }

    protected function buildDescription(): string{
        return implode(
            "\n\n",
            [
                'API automatically transforms models using DTOs with #[UseDto] attribute.',
                '## Response Format',
                'All successful responses follow the structure:',
                '```json',
                '{',
                '  "data": {...},',
                '  "meta": {',
                '    "timestamp": "2025-12-23T03:07:01+00:00",',
                '    "request_id": "e04bcef3-76a1-43c3-ab7e-6e7fd1c0513b",',
                '    "api_version": "v1"',
                '  }',
                '}',
                '```',
                '',
                '## Paginated Response Format',
                'Paginated responses include pagination metadata in meta and navigation links:',
                '```json',
                '{',
                '  "data": [...],',
                '  "meta": {',
                '    "current_page": 1,',
                '    "last_page": 3,',
                '    "per_page": 10,',
                '    "total": 25,',
                '    "from": 1,',
                '    "to": 10,',
                '    "timestamp": "2025-12-23T03:07:01+00:00",',
                '    "request_id": "...",',
                '    "api_version": "v1"',
                '  },',
                '  "links": {',
                '    "first": "http://localhost?page=1",',
                '    "last": "http://localhost?page=3",',
                '    "prev": null,',
                '    "next": "http://localhost?page=2"',
                '  }',
                '}',
                '```',
                '',
                '## Error Format',
                'All error responses follow the structure:',
                '```json',
                '{',
                '  "success": false,',
                '  "message": "Error message",',
                '  "errors": {...},',
                '  "meta": {...}',
                '}',
                '```',
            ]
        );
    }

    protected function buildResponseShapes(): array{
        return [
            'ResponseMeta'    => [
                'type'        => 'object',
                'description' => 'Response metadata (includes pagination info for paginated responses)',
                'properties'  => [
                    'timestamp'    => [
                        'type'        => 'string',
                        'format'      => 'date-time',
                        'description' => 'Response timestamp',
                        'example'     => '2025-12-23T03:07:01+00:00',
                    ],
                    'request_id'   => [
                        'type'        => 'string',
                        'format'      => 'uuid',
                        'description' => 'Unique request identifier',
                        'example'     => 'e04bcef3-76a1-43c3-ab7e-6e7fd1c0513b',
                    ],
                    'api_version'  => [
                        'type'        => 'string',
                        'description' => 'API version',
                        'example'     => 'v1',
                    ],
                    'current_page' => [
                        'type'        => 'integer',
                        'description' => 'Current page number (paginated responses only)',
                        'example'     => 1,
                    ],
                    'last_page'    => [
                        'type'        => 'integer',
                        'description' => 'Last page number (paginated responses only)',
                        'example'     => 3,
                    ],
                    'per_page'     => [
                        'type'        => 'integer',
                        'description' => 'Items per page (paginated responses only)',
                        'example'     => 10,
                    ],
                    'total'        => [
                        'type'        => 'integer',
                        'description' => 'Total number of items (paginated responses only)',
                        'example'     => 25,
                    ],
                    'from'         => [
                        'type'        => 'integer',
                        'description' => 'First item number on current page (paginated responses only)',
                        'example'     => 1,
                    ],
                    'to'           => [
                        'type'        => 'integer',
                        'description' => 'Last item number on current page (paginated responses only)',
                        'example'     => 10,
                    ],
                ],
            ],
            'PaginationLinks' => [
                'type'        => 'object',
                'description' => 'Pagination navigation links',
                'properties'  => [
                    'first' => [
                        'type'        => 'string',
                        'description' => 'URL to first page',
                        'example'     => 'http://localhost?page=1',
                    ],
                    'last'  => [
                        'type'        => 'string',
                        'description' => 'URL to last page',
                        'example'     => 'http://localhost?page=3',
                    ],
                    'prev'  => [
                        'type'        => [
                            'string',
                            'null',
                        ],
                        'description' => 'URL to previous page (null if on first page)',
                        'example'     => null,
                    ],
                    'next'  => [
                        'type'        => [
                            'string',
                            'null',
                        ],
                        'description' => 'URL to next page (null if on last page)',
                        'example'     => 'http://localhost?page=2',
                    ],
                ],
            ],
        ];
    }

    protected function buildErrorSchemas(): array{
        return [
            'ErrorResponse'   => [
                'type'        => 'object',
                'description' => 'Error response structure',
                'properties'  => [
                    'message' => [
                        'type'        => 'string',
                        'description' => 'Error message',
                        'example'     => 'An error occurred',
                    ],
                    'errors'  => [
                        'type'        => 'object',
                        'description' => 'Detailed error information',
                        'example'     => [],
                    ],
                    'meta'    => [
                        '$ref' => '#/components/schemas/ResponseMeta',
                    ],
                ],
                'required'    => [
                    'message',
                    'meta',
                ],
            ],
            'ValidationError' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/ErrorResponse'],
                    [
                        'type'       => 'object',
                        'properties' => [
                            'error' => [
                                'type'       => 'object',
                                'properties' => [
                                    'code'    => [
                                        'type'    => 'string',
                                        'example' => 'VALIDATION_ERROR',
                                    ],
                                    'message' => [
                                        'type'    => 'string',
                                        'example' => 'The given data was invalid.',
                                    ],
                                    'details' => [
                                        'type'        => 'object',
                                        'description' => 'Field-specific validation errors',
                                        'example'     => [
                                            'email' => ['The email field is required.'],
                                            'name'  => ['The name must be at least 3 characters.'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function buildGlobalResponses(): array{
        return [
            'BadRequest'      => [
                'description' => 'Bad Request - Invalid request format',
                'content'     => [
                    'application/json' => [
                        'schema'  => ['$ref' => '#/components/schemas/ErrorResponse'],
                        'example' => [
                            'message' => 'The request could not be understood.',
                            'errors'  => [],
                            'meta'    => [
                                'timestamp'   => '2024-01-01T00:00:00Z',
                                'request_id'  => '550e8400-e29b-41d4-a716-446655440000',
                                'api_version' => 'v1',
                            ],
                        ],
                    ],
                ],
            ],
            'Unauthorized'    => [
                'description' => 'Unauthorized - Authentication required',
                'content'     => [
                    'application/json' => [
                        'schema'  => ['$ref' => '#/components/schemas/ErrorResponse'],
                        'example' => [
                            'message' => 'Authentication is required.',
                            'errors'  => [],
                            'meta'    => [
                                'timestamp'   => '2024-01-01T00:00:00Z',
                                'request_id'  => '550e8400-e29b-41d4-a716-446655440000',
                                'api_version' => 'v1',
                            ],
                        ],
                    ],
                ],
            ],
            'Forbidden'       => [
                'description' => 'Forbidden - Insufficient permissions',
                'content'     => [
                    'application/json' => [
                        'schema'  => ['$ref' => '#/components/schemas/ErrorResponse'],
                        'example' => [
                            'message' => 'You do not have permission to access this resource.',
                            'errors'  => [],
                            'meta'    => [
                                'timestamp'   => '2024-01-01T00:00:00Z',
                                'request_id'  => '550e8400-e29b-41d4-a716-446655440000',
                                'api_version' => 'v1',
                            ],
                        ],
                    ],
                ],
            ],
            'NotFound'        => [
                'description' => 'Not Found - Resource does not exist',
                'content'     => [
                    'application/json' => [
                        'schema'  => ['$ref' => '#/components/schemas/ErrorResponse'],
                        'example' => [
                            'message' => 'The requested resource was not found.',
                            'errors'  => [],
                            'meta'    => [
                                'timestamp'   => '2024-01-01T00:00:00Z',
                                'request_id'  => '550e8400-e29b-41d4-a716-446655440000',
                                'api_version' => 'v1',
                            ],
                        ],
                    ],
                ],
            ],
            'ValidationError' => [
                'description' => 'Validation Error - Invalid input data',
                'content'     => [
                    'application/json' => [
                        'schema'  => ['$ref' => '#/components/schemas/ValidationError'],
                        'example' => [
                            'message' => 'The given data was invalid.',
                            'errors'  => [
                                'email' => ['The email field is required.'],
                                'name'  => ['The name must be at least 3 characters.'],
                            ],
                            'meta'    => [
                                'timestamp'   => '2024-01-01T00:00:00Z',
                                'request_id'  => '550e8400-e29b-41d4-a716-446655440000',
                                'api_version' => 'v1',
                            ],
                        ],
                    ],
                ],
            ],
            'ServerError'     => [
                'description' => 'Internal Server Error',
                'content'     => [
                    'application/json' => [
                        'schema'  => ['$ref' => '#/components/schemas/ErrorResponse'],
                        'example' => [
                            'message' => 'An unexpected error occurred.',
                            'errors'  => [],
                            'meta'    => [
                                'timestamp'   => '2024-01-01T00:00:00Z',
                                'request_id'  => '550e8400-e29b-41d4-a716-446655440000',
                                'api_version' => 'v1',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
