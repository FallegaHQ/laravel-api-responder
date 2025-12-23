<?php
namespace FallegaHQ\ApiResponder\Http;

use FallegaHQ\ApiResponder\Attributes\UseDto;
use FallegaHQ\ApiResponder\Contracts\FieldsetParserInterface;
use FallegaHQ\ApiResponder\Contracts\MetadataBuilderInterface;
use FallegaHQ\ApiResponder\Contracts\ResponseBuilderInterface;
use FallegaHQ\ApiResponder\Contracts\Transformable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use ReflectionClass;

class ResponseBuilder implements ResponseBuilderInterface{
    protected array                    $config;
    protected MetadataBuilderInterface $metadataBuilder;
    protected FieldsetParserInterface  $fieldsetParser;
    protected array                    $additionalMeta    = [];
    protected array                    $additionalHeaders = [];
    protected float                    $startTime;
    protected ?string                  $dtoClass          = null;

    public function __construct(MetadataBuilderInterface $metadataBuilder,
                                FieldsetParserInterface  $fieldsetParser
    ){
        $this->config          = config('api-responder');
        $this->metadataBuilder = $metadataBuilder;
        $this->fieldsetParser  = $fieldsetParser;
        $this->startTime       = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
    }

    public function withDto(string $dtoClass): static{
        $this->dtoClass = $dtoClass;

        return $this;
    }

    public function error(?string $message = null, $errors = null, int $status = 400): JsonResponse{
        $response = [
            $this->config['structure']['success_key'] => false,
        ];
        if($message !== null){
            $response[$this->config['structure']['message_key']] = $message;
        }
        if($errors !== null){
            $response[$this->config['structure']['errors_key']] = $errors;
        }
        // Add metadata
        $metadata = $this->metadataBuilder->addExecutionTime($this->startTime)
                                          ->build();
        if(!empty($metadata)){
            $response[$this->config['structure']['meta_key']] = $metadata;
        }

        return response()->json($response, $status);
    }

    /**
     * @throws \JsonException
     */
    public function created($data = null, ?string $message = null): JsonResponse{
        return $this->success(
            $data,
            $message ?? 'Resource created successfully',
            $this->config['status_codes']['created']
        );
    }

    /**
     * @throws \JsonException
     */
    public function success($data = null, ?string $message = null, int $status = 200): JsonResponse{
        $response = [
            $this->config['structure']['success_key'] => true,
        ];
        if($message !== null){
            $response[$this->config['structure']['message_key']] = $message;
        }
        // Parse fieldsets from request
        $this->fieldsetParser->parse(request());
        $transformedData = $this->transformData($data);
        if(is_array($transformedData) && isset($transformedData['data'])){
            $response = array_merge($response, $transformedData);
        }
        else{
            $response[$this->config['structure']['data_key']] = $transformedData;
        }
        // Add metadata
        $metadata = $this->metadataBuilder->addExecutionTime($this->startTime)
                                          ->merge($this->additionalMeta)
                                          ->build();
        if(!empty($metadata)){
            $response[$this->config['structure']['meta_key']] = array_merge(
                $response[$this->config['structure']['meta_key']] ?? [],
                $metadata
            );
        }
        $jsonResponse = response()->json($response, $status);
        // Add headers
        foreach($this->additionalHeaders as $key => $value){
            $jsonResponse->header($key, $value);
        }
        // Add ETag if enabled
        if($this->config['cache']['etag_enabled']){
            $etag = md5(json_encode($response, JSON_THROW_ON_ERROR));
            $jsonResponse->header('ETag', $etag);
        }
        // Add API version header
        if($this->config['versioning']['enabled']){
            $jsonResponse->header(
                $this->config['versioning']['header_name'],
                $this->config['versioning']['default_version']
            );
        }

        return $jsonResponse;
    }

    /**
     * @throws \ReflectionException
     */
    protected function transformData($data): mixed{
        if($data === null){
            return null;
        }
        if($data instanceof LengthAwarePaginator){
            return $this->transformPagination($data);
        }
        if($data instanceof Collection){
            return $data->map(fn($item) => $this->transformItem($item))
                        ->values()
                        ->all();
        }
        if(is_array($data)){
            return array_map(
            /**
             * @throws \ReflectionException
             */ fn($item) => $this->transformItem($item),
                $data
            );
        }

        // Single item - transform it
        return $this->transformItem($data);
    }

    protected function transformPagination(LengthAwarePaginator $paginator): array{
        $paginationConfig = $this->config['pagination'];

        return [
            $this->config['structure']['data_key']  => $paginator->getCollection()
                                                                 ->map(fn($item) => $this->transformItem($item))
                                                                 ->values()
                                                                 ->all(),
            $this->config['structure']['meta_key']  => [
                $paginationConfig['current_page_key'] => $paginator->currentPage(),
                $paginationConfig['last_page_key']    => $paginator->lastPage(),
                $paginationConfig['per_page_key']     => $paginator->perPage(),
                $paginationConfig['total_key']        => $paginator->total(),
                $paginationConfig['from_key']         => $paginator->firstItem(),
                $paginationConfig['to_key']           => $paginator->lastItem(),
            ],
            $this->config['structure']['links_key'] => [
                'first' => $paginator->url(1),
                'last'  => $paginator->url($paginator->lastPage()),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
            ],
        ];
    }

    /**
     * @throws \ReflectionException
     */
    protected function transformItem(mixed $item): mixed{
        // If item is already Transformable (DTO), use it directly
        if($item instanceof Transformable){
            $user   = $this->resolveUser();
            $result = $item->toArray($user);

            // Apply fieldset filtering
            return $this->applyFieldsetFiltering($result);
        }
        // Try to find DTO class for this item
        $dtoClass = $this->resolveDtoClass($item);
        if($dtoClass !== null){
            // Wrap item in DTO
            /**
             * @template T of \FallegaHQ\ApiResponder\DTO\BaseDTO
             * @var  T $dtoClass
             */
            $dto    = $dtoClass::from($item);
            $user   = $this->resolveUser();
            $result = $dto->toArray($user);

            // Apply fieldset filtering
            return $this->applyFieldsetFiltering($result);
        }

        // No DTO found - return raw item
        return $item;
    }

    protected function resolveUser(): mixed{
        return call_user_func($this->config['visibility']['resolve_user']);
    }

    protected function applyFieldsetFiltering(array $data): array{
        if(!config('api-responder.sparse_fieldsets.enabled')){
            return $data;
        }
        $filtered = array_filter(
            $data,
            function($key){
                return $this->fieldsetParser->shouldIncludeField($key);
            },
            ARRAY_FILTER_USE_KEY
        );

        return empty($filtered) ? $data : $filtered;
    }

    protected function resolveDtoClass($item): ?string{
        // Priority 1: Explicit DTO class set via withDto()
        if($this->dtoClass !== null){
            return $this->dtoClass;
        }
        // Priority 2: Check for UseDto attribute on the model class
        if(is_object($item)){
            $reflection = new ReflectionClass($item);
            $attributes = $reflection->getAttributes(UseDto::class);
            if(!empty($attributes)){
                return $attributes[0]->newInstance()->dtoClass;
            }
        }

        return null;
    }

    public function noContent(): JsonResponse{
        return response()->json(null, $this->config['status_codes']['no_content']);
    }

    public function withMeta(array $meta): static{
        $this->additionalMeta = $meta;

        return $this;
    }

    public function withHeaders(array $headers): static{
        $this->additionalHeaders = $headers;

        return $this;
    }
}
