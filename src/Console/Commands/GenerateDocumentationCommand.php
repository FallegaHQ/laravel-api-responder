<?php
namespace FallegaHQ\ApiResponder\Console\Commands;

use BackedEnum;
use FallegaHQ\ApiResponder\Attributes\ApiDeprecated;
use FallegaHQ\ApiResponder\Attributes\ApiDescription;
use FallegaHQ\ApiResponder\Attributes\ApiFileUpload;
use FallegaHQ\ApiResponder\Attributes\ApiGroup;
use FallegaHQ\ApiResponder\Attributes\ApiHidden;
use FallegaHQ\ApiResponder\Attributes\ApiParam;
use FallegaHQ\ApiResponder\Attributes\ApiRequest;
use FallegaHQ\ApiResponder\Attributes\ApiResponse;
use FallegaHQ\ApiResponder\Attributes\ApiTag;
use FallegaHQ\ApiResponder\Attributes\UseDto;
use FallegaHQ\ApiResponder\Console\Services\DocumentationTestValidator;
use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Route as RouteBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use UnitEnum;

class GenerateDocumentationCommand extends Command{
    protected                            $signature   = 'api:generate-docs 
                            {--output=api-docs.json : Output file path}
                            {--validate-tests : Validate test coverage}
                            {--show-warnings : Show missing test warnings}
                            {--include=* : Include only routes matching pattern (supports wildcards)}
                            {--exclude=* : Exclude routes matching pattern (supports wildcards)}
                            {--include-controllers=* : Include only specific controllers}
                            {--exclude-controllers=* : Exclude specific controllers}';
    protected                            $description = 'Generate API documentation from routes and DTOs with authentication, grouping, and test validation';
    protected DocumentationTestValidator $testValidator;

    /**
     * @throws \JsonException
     */
    public function handle(): void{
        $this->info('Scanning routes and DTOs...');
        if($this->option('validate-tests')){
            $this->testValidator = new DocumentationTestValidator();
        }
        $routes        = $this->getApiRoutes();
        $dtos          = $this->scanDtos();
        $documentation = $this->buildDocumentation($routes, $dtos);
        $outputPath    = base_path($this->option('output'));
        file_put_contents($outputPath, json_encode($documentation, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->info("API documentation generated: $outputPath");
        $this->info("Found " . count($routes) . " routes and {$dtos->count()} DTOs");
        if($this->option('validate-tests')){
            $this->displayTestValidation($routes);
        }
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
            ->filter(fn($route) => $this->shouldIncludeRoute($route))
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

    protected function shouldIncludeRoute($route): bool{
        // Check ApiHidden attribute
        if($this->isRouteHidden($route)){
            return false;
        }
        $uri    = $route->uri();
        $action = $route->getActionName();
        // Check include patterns
        $includePatterns = $this->option('include');
        if(!empty($includePatterns)){
            $matches = false;
            foreach($includePatterns as $pattern){
                if($this->matchesPattern($uri, $pattern)){
                    $matches = true;
                    break;
                }
            }
            if(!$matches){
                return false;
            }
        }
        // Check exclude patterns
        $excludePatterns = $this->option('exclude');
        if(!empty($excludePatterns)){
            foreach($excludePatterns as $pattern){
                if($this->matchesPattern($uri, $pattern)){
                    return false;
                }
            }
        }
        // Check controller filters
        if(str_contains($action, '@')){
            [$controller] = explode('@', $action);
            $includeControllers = $this->option('include-controllers');
            if(!empty($includeControllers)){
                $matches = false;
                foreach($includeControllers as $pattern){
                    if($this->matchesPattern($controller, $pattern)){
                        $matches = true;
                        break;
                    }
                }
                if(!$matches){
                    return false;
                }
            }
            $excludeControllers = $this->option('exclude-controllers');
            if(!empty($excludeControllers)){
                foreach($excludeControllers as $pattern){
                    if($this->matchesPattern($controller, $pattern)){
                        return false;
                    }
                }
            }
        }

        return true;
    }

    protected function isRouteHidden($route): bool{
        $action = $route->getActionName();
        if(!str_contains($action, '@')){
            return false;
        }
        [
            $controller,
            $method,
        ] = explode('@', $action);
        if(!class_exists($controller)){
            return false;
        }
        try{
            $reflection = new ReflectionClass($controller);
            // Check class-level ApiHidden
            if(!empty($reflection->getAttributes(ApiHidden::class))){
                return true;
            }
            // Check method-level ApiHidden
            if($reflection->hasMethod($method)){
                $methodReflection = $reflection->getMethod($method);
                if(!empty($methodReflection->getAttributes(ApiHidden::class))){
                    return true;
                }
            }
        }
        catch(Throwable){
            // Ignore
        }

        return false;
    }

    protected function matchesPattern(string $subject, string $pattern): bool{
        $pattern = str_replace(
            [
                '*',
                '?',
            ],
            [
                '.*',
                '.',
            ],
            $pattern
        );

        return (bool) preg_match('/^' . $pattern . '$/i', $subject);
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

    /**
     * @throws \JsonException
     */
    protected function buildDocumentation($routes, $dtos): array{
        $schemas        = $this->buildSchemas($dtos);
        $requestSchemas = [];
        $paths          = $this->buildPaths($routes, $schemas, $requestSchemas);
        $tags           = $this->buildTagsMetadata($routes);

        return [
            'openapi'             => '3.0.0',
            'info'                => [
                'title'       => config('app.name') . ' API',
                'version'     => config('api-responder.metadata.api_version'),
                'description' => $this->buildDescription(),
            ],
            'servers'             => [
                [
                    'url'       => config('app.url'),
                    'variables' => [
                        'bearerToken' => [
                            'default'     => 'your-token-here',
                            'description' => 'Bearer authentication token',
                        ],
                    ],
                ],
            ],
            'tags'                => $tags,
            'paths'               => $paths,
            'components'          => [
                'schemas'         => array_merge(
                    $schemas,
                    $requestSchemas,
                    $this->buildResponseShapes(),
                    $this->buildErrorSchemas()
                ),
                'responses'       => $this->buildGlobalResponses(),
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description'  => 'Enter your bearer token in the format: Bearer {token}',
                    ],
                ],
            ],
            'x-postman-variables' => [
                [
                    'key'         => 'bearerToken',
                    'value'       => '',
                    'type'        => 'string',
                    'description' => 'Bearer authentication token for protected endpoints',
                ],
            ],
        ];
    }

    protected function buildSchemas($dtos): array{
        $schemas       = [];
        $processedDtos = [];
        foreach($dtos as $dtoInfo){
            $this->buildDtoSchema($dtoInfo['dto'], $dtoInfo['model'], $schemas, $processedDtos);
        }

        return $schemas;
    }

    protected function buildDtoSchema(string  $dtoClass,
                                      ?string $modelClass,
                                      array   &$schemas,
                                      array   &$processedDtos,
                                      int     $depth = 0
    ): void{
        // Prevent infinite recursion
        if($depth > 3 || in_array($dtoClass, $processedDtos, true)){
            return;
        }
        if(!class_exists($dtoClass)){
            return;
        }
        $processedDtos[] = $dtoClass;
        $reflection      = new ReflectionClass($dtoClass);
        $schemaName      = class_basename($dtoClass);
        $properties      = [];
        // Get computed fields from DTO
        foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
            if(str_starts_with($method->getName(), '__') || $method->getDeclaringClass()
                                                                   ->getName() !== $dtoClass){
                continue;
            }
            // Check for ComputedField attribute
            $computedAttributes = $method->getAttributes(ComputedField::class);
            if(!empty($computedAttributes)){
                $computedAttr           = $computedAttributes[0]->newInstance();
                $fieldName              = $computedAttr->name ?? $this->getFieldName($method->getName());
                $properties[$fieldName] = [
                    'type'        => $this->inferType($method),
                    'description' => 'Computed field from ' . $method->getName(),
                ];
            }
        }
        // Auto-detect relationships from model if available
        if($modelClass && class_exists($modelClass) && config(
                'api-responder.nested_dtos.auto_detect_relationships',
                true
            )){
            $relationshipProperties = $this->detectModelRelationships($modelClass, $schemas, $processedDtos, $depth);
            $properties             = array_merge($properties, $relationshipProperties);
        }
        // Add note about model attributes
        $description = $modelClass ? 'DTO for ' . class_basename(
                $modelClass
            ) . ' (includes all model attributes + computed fields)' : 'Data Transfer Object with computed fields';
        $schemas[$schemaName] = [
            'type'        => 'object',
            'description' => $description,
            'properties'  => $properties,
        ];
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

    protected function detectModelRelationships(string $modelClass,
                                                array  &$schemas,
                                                array  &$processedDtos,
                                                int    $depth
    ): array{
        $properties = [];
        try{
            $reflection = new ReflectionClass($modelClass);
            foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
                // Skip magic methods and inherited methods
                if(str_starts_with($method->getName(), '__') || $method->getDeclaringClass()
                                                                       ->getName() !== $modelClass){
                    continue;
                }
                // Check if method returns a Relation
                $returnType = $method->getReturnType();
                if(!$returnType){
                    continue;
                }
                $returnTypeName = $returnType->getName();
                if(!is_subclass_of($returnTypeName, Relation::class)){
                    continue;
                }
                $relationName = $method->getName();
                // Try to find the related model's DTO
                $relatedDto = $this->findRelatedModelDto($modelClass, $relationName);
                if(!$relatedDto){
                    continue;
                }
                $relatedSchemaName = class_basename($relatedDto);
                // Recursively build related DTO schema
                $this->buildDtoSchema($relatedDto, null, $schemas, $processedDtos, $depth + 1);
                // Determine if it's a collection relationship
                $isCollection = $this->isCollectionRelationship($returnTypeName);
                if($isCollection){
                    $properties[$relationName] = [
                        'type'        => 'array',
                        'items'       => ['$ref' => '#/components/schemas/' . $relatedSchemaName],
                        'description' => 'Related ' . $relatedSchemaName . ' collection (only included when loaded)',
                    ];
                }
                else{
                    $properties[$relationName] = [
                        'oneOf'       => [
                            ['$ref' => '#/components/schemas/' . $relatedSchemaName],
                            ['type' => 'null'],
                        ],
                        'description' => 'Related ' . $relatedSchemaName . ' (only included when loaded)',
                    ];
                }
            }
        }
        catch(Throwable){
            // Ignore errors
        }

        return $properties;
    }

    protected function findRelatedModelDto(string $modelClass, string $relationName): ?string{
        try{
            // Instantiate the model to get the relationship
            $reflection = new ReflectionClass($modelClass);
            $instance   = $reflection->newInstanceWithoutConstructor();
            if(!method_exists($instance, $relationName)){
                return null;
            }
            $relation     = $instance->$relationName();
            $relatedModel = get_class($relation->getRelated());
            // Check for UseDto attribute on related model
            $relatedReflection = new ReflectionClass($relatedModel);
            $attributes        = $relatedReflection->getAttributes(UseDto::class);
            if(!empty($attributes)){
                return $attributes[0]->newInstance()->dtoClass;
            }
        }
        catch(Throwable){
            // Ignore
        }

        return null;
    }

    protected function isCollectionRelationship(string $relationClass): bool{
        $collectionRelations = [
            HasMany::class,
            BelongsToMany::class,
            MorphMany::class,
            MorphToMany::class,
            HasManyThrough::class,
        ];
        foreach($collectionRelations as $collectionRelation){
            if(is_a($relationClass, $collectionRelation, true)){
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \JsonException
     */
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
                    'tags'        => $this->extractTags($path, $route),
                    'parameters'  => $this->extractParameters($path, $route),
                    'responses'   => $this->buildResponses($method, $schemas, $responseInfo),
                ];
                // Add deprecation info
                $deprecationInfo = $this->getDeprecationInfo($route);
                if($deprecationInfo){
                    $operation['deprecated'] = true;
                    if($deprecationInfo['reason'] || $deprecationInfo['since'] || $deprecationInfo['replacedBy']){
                        $deprecationText = [];
                        if($deprecationInfo['reason']){
                            $deprecationText[] = $deprecationInfo['reason'];
                        }
                        if($deprecationInfo['since']){
                            $deprecationText[] = 'Deprecated since: ' . $deprecationInfo['since'];
                        }
                        if($deprecationInfo['replacedBy']){
                            $deprecationText[] = 'Use instead: ' . $deprecationInfo['replacedBy'];
                        }
                        $operation['description'] .= "\n\n**DEPRECATED:** " . implode('. ', $deprecationText);
                    }
                }
                // Add security requirement if route is protected
                if($this->isProtectedRoute($route)){
                    $operation['security'] = [['bearerAuth' => []]];
                }
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
        catch(Throwable){
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
        catch(Throwable){
            return null;
        }
    }

    protected function generateSummary(array $route, string $method): string{
        // Check for ApiDescription attribute first
        $action = $route['action'];
        if(str_contains($action, '@')){
            [
                $controller,
                $methodName,
            ] = explode('@', $action);
            if(class_exists($controller)){
                try{
                    $reflection = new ReflectionClass($controller);
                    if($reflection->hasMethod($methodName)){
                        $methodReflection = $reflection->getMethod($methodName);
                        $attributes       = $methodReflection->getAttributes(ApiDescription::class);
                        if(!empty($attributes)){
                            return $attributes[0]->newInstance()->summary;
                        }
                    }
                }
                catch(Throwable){
                    // Continue to fallback
                }
            }
        }
        // Fallback to route name or generated summary
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
        $actionVerb = match ($method) {
            'get'          => 'Retrieve',
            'post'         => 'Create',
            'put', 'patch' => 'Update',
            'delete'       => 'Delete',
            default        => 'Handle',
        };

        return $actionVerb . ' ' . $route['uri'];
    }

    protected function generateDescription(array $route, string $method, ?array $responseInfo = null): string{
        // Check for ApiDescription attribute first
        $action = $route['action'];
        if(str_contains($action, '@')){
            [
                $controller,
                $methodName,
            ] = explode('@', $action);
            if(class_exists($controller)){
                try{
                    $reflection = new ReflectionClass($controller);
                    if($reflection->hasMethod($methodName)){
                        $methodReflection = $reflection->getMethod($methodName);
                        $attributes       = $methodReflection->getAttributes(ApiDescription::class);
                        if(!empty($attributes)){
                            $instance = $attributes[0]->newInstance();
                            if($instance->description){
                                $desc = $instance->description;
                                if($instance->requiresAuth){
                                    $desc .= "\n\n**Authentication Required:** Yes";
                                }

                                return $desc;
                            }
                        }
                    }
                }
                catch(Throwable){
                    // Continue to fallback
                }
            }
        }
        // Fallback to default descriptions
        $descriptions    = [
            'get'    => 'Retrieves resource(s). Returns data wrapped in success response with metadata.',
            'post'   => 'Creates a new resource. Returns created resource with 201 status.',
            'put'    => 'Updates an existing resource. Returns updated resource.',
            'patch'  => 'Partially updates an existing resource. Returns updated resource.',
            'delete' => 'Deletes a resource. Returns 204 No Content on success.',
        ];
        $baseDescription = $descriptions[$method] ?? 'Handles the request.';
        // Add authentication info
        if($this->isProtectedRoute($route)){
            $baseDescription .= "\n\n**Authentication Required:** Yes";
        }
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

    protected function isProtectedRoute(array $route): bool{
        // Check middleware for auth
        /** @var RouteBase|null $routeObj */
        $routeObj = collect(Route::getRoutes())->first(
            function($r) use ($route){
                return $r->uri() === $route['uri'] && in_array(strtoupper($route['method']), $r->methods(), true);
            }
        );
        if(!$routeObj){
            return false;
        }
        $middleware = $routeObj->middleware();
        // Check for common auth middleware
        $authMiddleware = [
            'auth',
            'auth:api',
            'auth:sanctum',
            'auth.basic',
            'can',
        ];
        foreach($authMiddleware as $auth){
            if(in_array($auth, $middleware, true) || $this->middlewareContains($middleware, $auth)){
                return true;
            }
        }
        // Check ApiDescription attribute
        $action = $route['action'];
        if(str_contains($action, '@')){
            [
                $controller,
                $method,
            ] = explode('@', $action);
            if(class_exists($controller)){
                try{
                    $reflection = new ReflectionClass($controller);
                    if($reflection->hasMethod($method)){
                        $methodReflection = $reflection->getMethod($method);
                        $attributes       = $methodReflection->getAttributes(ApiDescription::class);
                        if(!empty($attributes)){
                            return $attributes[0]->newInstance()->requiresAuth;
                        }
                    }
                }
                catch(Throwable){
                    // Ignore
                }
            }
        }

        return false;
    }

    protected function middlewareContains(array $middleware, string $search): bool{
        foreach($middleware as $mw){
            if(is_string($mw) && str_starts_with($mw, $search)){
                return true;
            }
        }

        return false;
    }

    protected function extractTags(string $path, array $route): array{
        $tags = [];
        // Check for ApiTag attribute on controller method
        $attributeTags = $this->getAttributeTags($route);
        if(!empty($attributeTags)){
            $tags = array_merge($tags, $attributeTags);
        }
        // Fallback to path-based tags
        if(empty($tags)){
            $parts = explode('/', trim($path, '/'));
            $tags  = !empty($parts[1]) ? [ucfirst($parts[1])] : ['API'];
        }
        // Add authentication tag if route is protected
        if($this->isProtectedRoute($route)){
            $tags[] = 'Auth';
        }

        return array_unique($tags);
    }

    protected function getAttributeTags(array $route): array{
        $action = $route['action'];
        if(!str_contains($action, '@')){
            return [];
        }
        [
            $controller,
            $method,
        ] = explode('@', $action);
        if(!class_exists($controller)){
            return [];
        }
        try{
            $reflection = new ReflectionClass($controller);
            $tags       = [];
            // Check class-level tags
            $classAttributes = $reflection->getAttributes(ApiTag::class);
            foreach($classAttributes as $attr){
                $instance = $attr->newInstance();
                foreach($instance->tags as $tag){
                    $tags[] = $tag;
                }
            }
            // Check method-level tags
            if($reflection->hasMethod($method)){
                $methodReflection = $reflection->getMethod($method);
                $methodAttributes = $methodReflection->getAttributes(ApiTag::class);
                foreach($methodAttributes as $attr){
                    $instance = $attr->newInstance();
                    foreach($instance->tags as $tag){
                        $tags[] = $tag;
                    }
                }
            }

            return array_unique($tags);
        }
        catch(Throwable){
            return [];
        }
    }

    protected function extractParameters(string $path, array $route): array{
        $parameters = [];
        // Extract path parameters
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
        // Extract ApiParam attributes
        $action = $route['action'];
        if(str_contains($action, '@')){
            [
                $controller,
                $method,
            ] = explode('@', $action);
            if(class_exists($controller)){
                try{
                    $reflection = new ReflectionClass($controller);
                    if($reflection->hasMethod($method)){
                        $methodReflection = $reflection->getMethod($method);
                        $attributes       = $methodReflection->getAttributes(ApiParam::class);
                        foreach($attributes as $attr){
                            $instance = $attr->newInstance();
                            $paramDef = [
                                'name'        => $instance->name,
                                'in'          => 'query',
                                'required'    => $instance->required,
                                'schema'      => ['type' => $instance->type],
                                'description' => $instance->description ?? '',
                            ];
                            if($instance->format){
                                $paramDef['schema']['format'] = $instance->format;
                            }
                            if($instance->minimum !== null){
                                $paramDef['schema']['minimum'] = $instance->minimum;
                            }
                            if($instance->maximum !== null){
                                $paramDef['schema']['maximum'] = $instance->maximum;
                            }
                            if($instance->enum !== null){
                                $paramDef['schema']['enum'] = $instance->enum;
                            }
                            if($instance->example !== null){
                                $paramDef['example'] = $instance->example;
                            }
                            $parameters[] = $paramDef;
                        }
                    }
                }
                catch(Throwable){
                    // Ignore
                }
            }
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
        // DELETE methods only return 204 No Content
        if($method === 'delete'){
            return [
                '204' => [
                    'description' => 'Resource deleted successfully (no content)',
                ],
            ];
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
        catch(Throwable){
            // Ignore
        }

        return null;
    }

    protected function getDeprecationInfo(array $route): ?array{
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
            // Check method-level deprecation
            if($reflection->hasMethod($method)){
                $methodReflection = $reflection->getMethod($method);
                $attributes       = $methodReflection->getAttributes(ApiDeprecated::class);
                if(!empty($attributes)){
                    $instance = $attributes[0]->newInstance();

                    return [
                        'deprecated' => true,
                        'reason'     => $instance->reason,
                        'since'      => $instance->since,
                        'replacedBy' => $instance->replacedBy,
                    ];
                }
            }
            // Check class-level deprecation
            $classAttributes = $reflection->getAttributes(ApiDeprecated::class);
            if(!empty($classAttributes)){
                $instance = $classAttributes[0]->newInstance();

                return [
                    'deprecated' => true,
                    'reason'     => $instance->reason,
                    'since'      => $instance->since,
                    'replacedBy' => $instance->replacedBy,
                ];
            }
        }
        catch(Throwable){
            // Ignore
        }

        return null;
    }

    /**
     * @throws \JsonException
     */
    protected function buildRequestBody(array  $route,
                                        array  $schemas,
                                        ?array $requestInfo = null,
                                        array  &$requestSchemas = []
    ): array{
        // Check for file uploads
        $fileUploads = $this->extractFileUploads($route);
        $hasFiles    = !empty($fileUploads);
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
                    $schemaName = 'Request' . md5(json_encode($requestInfo['fields'], JSON_THROW_ON_ERROR));
                }
                $properties = [];
                $required   = [];
                foreach($requestInfo['fields'] as $field => $config){
                    if(is_string($config)){
                        $properties[$config] = [
                            'type'        => 'string',
                            'description' => 'Field: ' . $config,
                        ];
                    }
                    else{
                        $fieldSchema = [
                            'type'        => $config['type'] ?? 'string',
                            'description' => $config['description'] ?? '',
                        ];
                        // Add enum values if present
                        $enumValues = $this->detectEnumValues($field, $requestInfo);
                        if($enumValues){
                            $fieldSchema['enum'] = $enumValues;
                        }
                        if(isset($config['example'])){
                            $fieldSchema['example'] = $config['example'];
                        }
                        if(isset($config['format'])){
                            $fieldSchema['format'] = $config['format'];
                        }
                        if(isset($config['minimum'])){
                            $fieldSchema['minimum'] = $config['minimum'];
                        }
                        if(isset($config['maximum'])){
                            $fieldSchema['maximum'] = $config['maximum'];
                        }
                        if(isset($config['minLength'])){
                            $fieldSchema['minLength'] = $config['minLength'];
                        }
                        if(isset($config['maxLength'])){
                            $fieldSchema['maxLength'] = $config['maxLength'];
                        }
                        if(isset($config['pattern'])){
                            $fieldSchema['pattern'] = $config['pattern'];
                        }
                        if(isset($config['required']) && $config['required']){
                            $required[] = $field;
                        }
                        $properties[$field] = $fieldSchema;
                    }
                }
                // Store the schema definition
                $requestSchemas[$schemaName] = [
                    'type'        => 'object',
                    'properties'  => $properties,
                    'description' => $requestInfo['description'] ?? 'Request payload',
                ];
                if(!empty($required)){
                    $requestSchemas[$schemaName]['required'] = $required;
                }
                $dataSchema = ['$ref' => '#/components/schemas/' . $schemaName];
            }
        }
        $description = $requestInfo['description'] ?? 'Request payload';
        // Build content based on whether we have file uploads
        $content = [];
        if($hasFiles){
            // Use multipart/form-data for file uploads
            $multipartSchema = [
                'type'       => 'object',
                'properties' => [],
            ];
            // Add file upload fields
            foreach($fileUploads as $file){
                $fileSchema = [
                    'type'        => 'string',
                    'format'      => 'binary',
                    'description' => $file['description'] ?? 'File upload',
                ];
                if($file['allowedMimeTypes']){
                    $fileSchema['description'] .= ' (Allowed types: ' . implode(', ', $file['allowedMimeTypes']) . ')';
                }
                if($file['maxSizeKb']){
                    $fileSchema['description'] .= ' (Max size: ' . $file['maxSizeKb'] . 'KB)';
                }
                if($file['multiple']){
                    $multipartSchema['properties'][$file['name']] = [
                        'type'  => 'array',
                        'items' => $fileSchema,
                    ];
                }
                else{
                    $multipartSchema['properties'][$file['name']] = $fileSchema;
                }
                if($file['required']){
                    if(!isset($multipartSchema['required'])){
                        $multipartSchema['required'] = [];
                    }
                    $multipartSchema['required'][] = $file['name'];
                }
            }
            // Add regular fields if present
            if($requestInfo && !empty($requestInfo['fields'])){
                foreach($requestInfo['fields'] as $field => $config){
                    if(!is_string($config)){
                        $multipartSchema['properties'][$field] = [
                            'type'        => $config['type'] ?? 'string',
                            'description' => $config['description'] ?? '',
                        ];
                    }
                }
            }
            $content['multipart/form-data'] = [
                'schema' => $multipartSchema,
            ];
        }
        else{
            $content['application/json'] = [
                'schema' => $dataSchema,
            ];
        }

        return [
            'required'    => true,
            'description' => $description,
            'content'     => $content,
        ];
    }

    protected function extractFileUploads(array $route): array{
        $action = $route['action'];
        if(!str_contains($action, '@')){
            return [];
        }
        [
            $controller,
            $method,
        ] = explode('@', $action);
        if(!class_exists($controller)){
            return [];
        }
        try{
            $reflection = new ReflectionClass($controller);
            if($reflection->hasMethod($method)){
                $methodReflection = $reflection->getMethod($method);
                $attributes       = $methodReflection->getAttributes(ApiFileUpload::class);
                $files            = [];
                foreach($attributes as $attr){
                    $instance = $attr->newInstance();
                    $files[]  = [
                        'name'             => $instance->name,
                        'description'      => $instance->description,
                        'required'         => $instance->required,
                        'allowedMimeTypes' => $instance->allowedMimeTypes,
                        'maxSizeKb'        => $instance->maxSizeKb,
                        'multiple'         => $instance->multiple,
                    ];
                }

                return $files;
            }
        }
        catch(Throwable){
            // Ignore
        }

        return [];
    }

    protected function detectEnumValues(string $fieldName, array $requestInfo = null): ?array{
        // Check ApiEnum attribute first
        if($requestInfo && isset($requestInfo['fields'][$fieldName]['enum'])){
            return $requestInfo['fields'][$fieldName]['enum'];
        }
        // Try to detect from validation rules
        if($requestInfo && isset($requestInfo['fields'][$fieldName]['validation'])){
            $validation = $requestInfo['fields'][$fieldName]['validation'];
            if(preg_match('/in:([^|]+)/', $validation, $matches)){
                return explode(',', $matches[1]);
            }
        }

        return null;
    }

    protected function buildTagsMetadata(array $routes): array{
        $tagsMap = [];
        foreach($routes as $route){
            $tags = $this->extractTags('/' . $route['uri'], $route);
            foreach($tags as $tag){
                if(!isset($tagsMap[$tag])){
                    $tagsMap[$tag] = [
                        'name'        => $tag,
                        'description' => $this->getTagDescription($tag, $route),
                    ];
                }
            }
        }
        // Sort tags: Auth first, then alphabetically
        $sortedTags = [];
        if(isset($tagsMap['Auth'])){
            $sortedTags[] = $tagsMap['Auth'];
            unset($tagsMap['Auth']);
        }
        ksort($tagsMap);
        foreach($tagsMap as $tag){
            $sortedTags[] = $tag;
        }

        return $sortedTags;
    }

    // ==================== ROUTE FILTERING ====================

    protected function getTagDescription(string $tag, array $route): string{
        // Check for ApiGroup attribute
        $action = $route['action'];
        if(str_contains($action, '@')){
            [
                $controller,
                $method,
            ] = explode('@', $action);
            if(class_exists($controller)){
                try{
                    $reflection = new ReflectionClass($controller);
                    // Check class-level group
                    $classAttributes = $reflection->getAttributes(ApiGroup::class);
                    foreach($classAttributes as $attr){
                        $instance = $attr->newInstance();
                        if($instance->name === $tag && $instance->description){
                            return $instance->description;
                        }
                    }
                    // Check method-level group
                    if($reflection->hasMethod($method)){
                        $methodReflection = $reflection->getMethod($method);
                        $methodAttributes = $methodReflection->getAttributes(ApiGroup::class);
                        foreach($methodAttributes as $attr){
                            $instance = $attr->newInstance();
                            if($instance->name === $tag && $instance->description){
                                return $instance->description;
                            }
                        }
                    }
                }
                catch(Throwable){
                    // Ignore
                }
            }
        }

        // Default descriptions
        return match ($tag) {
            'Auth'  => 'Authentication and authorization endpoints',
            'Users' => 'User management endpoints',
            'API'   => 'General API endpoints',
            default => ucfirst($tag) . ' related endpoints',
        };
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
                '',
                '## Authentication',
                'Protected endpoints require a Bearer token in the Authorization header:',
                '```',
                'Authorization: Bearer {your-token}',
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

    // ==================== DEPRECATION SUPPORT ====================

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

    // ==================== ENUM DETECTION ====================

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

    protected function displayTestValidation(array $routes): void{
        $this->newLine();
        $this->info('Test Coverage Analysis:');
        $this->newLine();
        $totalRoutes  = count($routes);
        $testedRoutes = 0;
        $warnings     = [];
        foreach($routes as $route){
            $validation = $this->testValidator->validateRoute($route);
            if($validation['has_test']){
                $testedRoutes++;
            }
            else{
                $warnings[] = [
                    'route'          => $route['method'] . ' /' . $route['uri'],
                    'recommendation' => $validation['recommendations'][0] ?? 'Add test coverage',
                ];
            }
        }
        $coverage = $totalRoutes > 0 ? round(($testedRoutes / $totalRoutes) * 100, 2) : 0;
        $this->info("Total Routes: $totalRoutes");
        $this->info("Tested Routes: $testedRoutes");
        $this->info("Coverage: $coverage%");
        if(!empty($warnings) && $this->option('show-warnings')){
            $this->newLine();
            $this->warn('Routes Missing Tests:');
            foreach($warnings as $warning){
                $this->line("  • {$warning['route']}");
                $this->line("    {$warning['recommendation']}");
            }
        }
        $this->newLine();
    }

    protected function getModelCasts(string $modelClass): array{
        if(!class_exists($modelClass)){
            return [];
        }
        try{
            $reflection = new ReflectionClass($modelClass);
            // Try to get casts from casts() method (Laravel 11+)
            if($reflection->hasMethod('casts')){
                $method = $reflection->getMethod('casts');
                if($method->isPublic()){
                    $instance = $reflection->newInstanceWithoutConstructor();

                    return $method->invoke($instance);
                }
            }
            // Try to get from $casts property
            if($reflection->hasProperty('casts')){
                $property = $reflection->getProperty('casts');
                $instance = $reflection->newInstanceWithoutConstructor();

                return $property->getValue($instance) ?? [];
            }
        }
        catch(Throwable){
            // Ignore
        }

        return [];
    }

    // ==================== FILE UPLOAD SUPPORT ====================

    protected function detectPhpEnum(string $type): ?array{
        if(!class_exists($type)){
            return null;
        }
        try{
            $reflection = new ReflectionClass($type);
            if($reflection->isEnum()){
                $cases = [];
                foreach($reflection->getConstants() as $case){
                    if($case instanceof UnitEnum){
                        $cases[] = $case instanceof BackedEnum ? $case->value : $case->name;
                    }
                }

                return $cases;
            }
        }
        catch(Throwable){
            // Ignore
        }

        return null;
    }

    protected function hasFileUploads(array $route): bool{
        $action = $route['action'];
        if(!str_contains($action, '@')){
            return false;
        }
        [
            $controller,
            $method,
        ] = explode('@', $action);
        if(!class_exists($controller)){
            return false;
        }
        try{
            $reflection = new ReflectionClass($controller);
            if($reflection->hasMethod($method)){
                $methodReflection = $reflection->getMethod($method);

                return !empty($methodReflection->getAttributes(ApiFileUpload::class));
            }
        }
        catch(Throwable){
            // Ignore
        }

        return false;
    }
}
