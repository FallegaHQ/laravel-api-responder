<?php
namespace FallegaHQ\ApiResponder\DTO;

use FallegaHQ\ApiResponder\Attributes\UseDto;
use FallegaHQ\ApiResponder\Contracts\CacheManagerInterface;
use FallegaHQ\ApiResponder\Contracts\EventDispatcherInterface;
use FallegaHQ\ApiResponder\Contracts\Transformable;
use FallegaHQ\ApiResponder\Contracts\VisibilityResolverInterface;
use FallegaHQ\ApiResponder\DTO\Attributes\Cached;
use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;
use FallegaHQ\ApiResponder\DTO\Attributes\Relationship;
use FallegaHQ\ApiResponder\DTO\Attributes\Translatable;
use FallegaHQ\ApiResponder\DTO\Attributes\Versioned;
use FallegaHQ\ApiResponder\DTO\Attributes\Visible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

abstract class BaseDTO implements Transformable{
    protected CacheManagerInterface       $cacheManager;
    protected VisibilityResolverInterface $visibilityResolver;
    protected EventDispatcherInterface    $eventDispatcher;
    protected mixed                       $source;
    protected int                         $nestingDepth = 0;

    public function __construct(mixed $source){
        $this->source             = $source;
        $this->cacheManager       = app(CacheManagerInterface::class);
        $this->visibilityResolver = app(VisibilityResolverInterface::class);
        $this->eventDispatcher    = app(EventDispatcherInterface::class);
    }

    public static function from(mixed $source): static{
        return new static($source);
    }

    /**
     * @throws \ReflectionException
     */
    public function toArray($user = null): array{
        // Fire before transform event
        if(config('api-responder.events.fire_before_transform')){
            $this->eventDispatcher->beforeTransform($this->source);
        }
        // Start with model attributes if source is a Model
        $result = $this->getModelAttributes();
        // Apply hidden fields
        $hiddenFields = $this->getHiddenFields();
        foreach($hiddenFields as $field){
            unset($result[$field]);
        }
        // Add computed fields
        $reflection     = new ReflectionClass($this);
        $currentVersion = $this->getCurrentVersion();
        foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
            if(str_starts_with($method->getName(), '__') || $method->getDeclaringClass()
                                                                   ->getName() !== static::class){
                continue;
            }
            $attributes = $this->parseMethodAttributes($method);
            if(!isset($attributes['computed'])){
                continue;
            }
            // Check version compatibility
            if(isset($attributes['versioned']) && !in_array($currentVersion, $attributes['versioned']->versions, true)){
                continue;
            }
            $fieldName = $attributes['computed']->name ?? $this->getFieldName($method->getName());
            // Check if field is hidden
            if(in_array($fieldName, $hiddenFields, true)){
                continue;
            }
            // Check visibility
            if(isset($attributes['visible']) && !$this->visibilityResolver->canSeeField(
                    $fieldName,
                    $attributes['visible']->roles,
                    $user
                )){
                continue;
            }
            // Get value with optional caching
            if(isset($attributes['cached'])){
                $cacheKey = $attributes['cached']->key ?? $this->generateCacheKey($fieldName);
                $value    = $this->cacheManager->remember(
                    $cacheKey,
                    $attributes['cached']->ttl,
                    fn() => $this->getFieldValue($method, $attributes)
                );
            }
            else{
                $value = $this->getFieldValue($method, $attributes);
            }
            $result[$fieldName] = $value;
        }
        // Auto-detect and include nested DTOs from relationships
        if(config('api-responder.nested_dtos.auto_detect_relationships', true)){
            $result = $this->includeNestedDtos($result, $user);
        }
        // Fire after transform event
        if(config('api-responder.events.fire_after_transform')){
            $this->eventDispatcher->afterTransform($this->source, $result);
        }

        return $result;
    }

    protected function getModelAttributes(): array{
        // Check if source is an Eloquent Model
        if(method_exists($this->source, 'toArray')){
            return $this->source->toArray();
        }
        // Check if source is an array
        if(is_array($this->source)){
            return $this->source;
        }
        // Check if source is an object with public properties
        if(is_object($this->source)){
            return get_object_vars($this->source);
        }

        return [];
    }

    protected function getHiddenFields(): array{
        // Override this method in child DTOs to hide specific fields
        return [];
    }

    protected function getCurrentVersion(): string{
        if(!config('api-responder.versioning.enabled')){
            return config('api-responder.versioning.default_version');
        }

        return request()->header(config('api-responder.versioning.header_name')) ?? config(
            'api-responder.versioning.default_version'
        );
    }

    protected function parseMethodAttributes(ReflectionMethod $method): array{
        $attributes = [];
        foreach($method->getAttributes() as $attribute){
            $instance = $attribute->newInstance();
            if($instance instanceof ComputedField){
                $attributes['computed'] = $instance;
            }
            elseif($instance instanceof Visible){
                $attributes['visible'] = $instance;
            }
            elseif($instance instanceof Cached){
                $attributes['cached'] = $instance;
            }
            elseif($instance instanceof Translatable){
                $attributes['translatable'] = $instance;
            }
            elseif($instance instanceof Relationship){
                $attributes['relationship'] = $instance;
            }
            elseif($instance instanceof Versioned){
                $attributes['versioned'] = $instance;
            }
        }

        return $attributes;
    }

    protected function getFieldName(string $methodName): string{
        if(str_starts_with($methodName, 'get')){
            $methodName = substr($methodName, 3);
        }

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $methodName));
    }

    protected function generateCacheKey(string $fieldName): string{
        $sourceId = method_exists($this->source, 'getKey') ? $this->source->getKey() : spl_object_id($this->source);

        return $this->cacheManager->generateKey(
            static::class . ':' . $fieldName,
            ['id' => $sourceId]
        );
    }

    /**
     * @throws \ReflectionException
     */
    protected function getFieldValue(ReflectionMethod $method, array $attributes): mixed{
        $value = $method->invoke($this);
        // Handle translations
        if(isset($attributes['translatable']) && config('api-responder.localization.enabled')){
            $locale = $attributes['translatable']->locale ?? app()->getLocale();
            if(is_array($value) && isset($value[$locale])){
                return $value[$locale];
            }
        }

        return $value;
    }

    /**
     * @throws \ReflectionException
     */
    protected function includeNestedDtos(array $result, $user = null): array{
        // Check nesting depth to prevent infinite loops
        $maxDepth = config('api-responder.nested_dtos.max_nesting_depth', 3);
        if($this->nestingDepth >= $maxDepth){
            return $result;
        }
        // Only process if source is an Eloquent Model
        if(!($this->source instanceof Model)){
            return $result;
        }
        // Get all relationships from the model
        $relationships = $this->getModelRelationships();
        foreach($relationships as $relationName => $relationType){
            // Check if relationship is loaded
            // Skip if config says not to include unloaded relationships
            if(!$this->source->relationLoaded($relationName) && !config(
                    'api-responder.nested_dtos.include_unloaded_relationships',
                    false
                )){
                continue;
            }
            // Get the relationship value
            $relationValue = $this->source->$relationName;
            // Skip if null or empty
            if($relationValue === null){
                continue;
            }
            // Find DTO for the related model
            $dto = $this->findDtoForRelation($relationValue);
            if(!$dto){
                continue;
            }
            // Transform the relationship
            $result[$relationName] = $this->transformRelationship($relationValue, $dto, $user);
        }

        return $result;
    }

    /**
     * @throws \ReflectionException
     */
    protected function getModelRelationships(): array{
        if(!($this->source instanceof Model)){
            return [];
        }
        $relationships = [];
        $reflection    = new ReflectionClass($this->source);
        foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
            // Skip magic methods and inherited methods
            if(str_starts_with($method->getName(), '__') || $method->getDeclaringClass()
                                                                   ->getName() !== get_class($this->source)){
                continue;
            }
            // Check if method returns a Relation
            $returnType = $method->getReturnType();
            if(!$returnType){
                continue;
            }
            $returnTypeName = $returnType->getName();
            if(is_subclass_of($returnTypeName, Relation::class)){
                $relationships[$method->getName()] = $returnTypeName;
            }
        }

        return $relationships;
    }

    protected function findDtoForRelation($relationValue): ?string{
        // Get the model class
        $modelClass = null;
        if($relationValue instanceof Model){
            $modelClass = get_class($relationValue);
        }
        elseif($relationValue instanceof Collection && $relationValue->isNotEmpty()){
            $modelClass = get_class($relationValue->first());
        }
        if(!$modelClass){
            return null;
        }
        // Check for UseDto attribute on the model
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

    protected function transformRelationship($relationValue, string $dtoClass, $user = null): mixed{
        if($relationValue instanceof Collection){
            return $relationValue->map(
                function($item) use ($dtoClass, $user){
                    $dto               = new $dtoClass($item);
                    $dto->nestingDepth = $this->nestingDepth + 1;

                    return $dto->toArray($user);
                }
            )
                                 ->toArray();
        }
        if($relationValue instanceof Model){
            $dto               = new $dtoClass($relationValue);
            $dto->nestingDepth = $this->nestingDepth + 1;

            return $dto->toArray($user);
        }

        return null;
    }
}
