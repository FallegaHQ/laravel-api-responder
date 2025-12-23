<?php
namespace Config;

use FallegaHQ\ApiResponder\Cache\CacheManager;
use FallegaHQ\ApiResponder\Events\EventDispatcher;
use FallegaHQ\ApiResponder\Http\FieldsetParser;
use FallegaHQ\ApiResponder\Http\MetadataBuilder;
use FallegaHQ\ApiResponder\Http\ResponseBuilder;
use FallegaHQ\ApiResponder\Policies\VisibilityResolver;
use FallegaHQ\ApiResponder\Validation\ValidationFormatter;

return [
    /*
    |--------------------------------------------------------------------------
    | API Routing Configuration
    |--------------------------------------------------------------------------
    | Configure how the package detects API requests
    */
    'routing'            => [
        'prefix'      => env('API_PREFIX', 'api'),
        'detect_json' => true,
        // Also detect requests expecting JSON
    ],
    /*
    |--------------------------------------------------------------------------
    | Default Response Structure
    |--------------------------------------------------------------------------
    */
    'structure'          => [
        'success_key' => 'success',
        'data_key'    => 'data',
        'message_key' => 'message',
        'errors_key'  => 'errors',
        'meta_key'    => 'meta',
        'links_key'   => 'links',
    ],
    /*
    |--------------------------------------------------------------------------
    | Metadata Configuration
    |--------------------------------------------------------------------------
    */
    'metadata'           => [
        'enabled'                => true,
        'include_timestamps'     => true,
        'include_request_id'     => true,
        'include_version'        => true,
        'include_execution_time' => env('APP_DEBUG', false),
        'api_version'            => 'v1',
    ],
    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache'              => [
        'enabled'      => true,
        'driver'       => env('API_RESPONDER_CACHE_DRIVER', 'redis'),
        'prefix'       => 'api_responder',
        'default_ttl'  => 3600,
        'etag_enabled' => true,
    ],
    /*
    |--------------------------------------------------------------------------
    | Sparse Fieldsets Configuration
    |--------------------------------------------------------------------------
    */
    'sparse_fieldsets'   => [
        'enabled'       => true,
        'query_param'   => 'fields',
        'include_param' => 'include',
        'exclude_param' => 'exclude',
    ],
    /*
    |--------------------------------------------------------------------------
    | Pagination Configuration
    |--------------------------------------------------------------------------
    */
    'pagination'         => [
        'per_page_key'     => 'per_page',
        'current_page_key' => 'current_page',
        'last_page_key'    => 'last_page',
        'total_key'        => 'total',
        'from_key'         => 'from',
        'to_key'           => 'to',
        'default_per_page' => 15,
        'max_per_page'     => 100,
    ],
    /*
    |--------------------------------------------------------------------------
    | Default HTTP Status Codes
    |--------------------------------------------------------------------------
    */
    'status_codes'       => [
        'success'       => 200,
        'created'       => 201,
        'accepted'      => 202,
        'no_content'    => 204,
        'bad_request'   => 400,
        'unauthorized'  => 401,
        'forbidden'     => 403,
        'not_found'     => 404,
        'unprocessable' => 422,
        'server_error'  => 500,
    ],
    /*
    |--------------------------------------------------------------------------
    | Role-Based Visibility
    |--------------------------------------------------------------------------
    */
    'visibility'         => [
        'enabled'      => true,
        'guest_role'   => 'guest',
        'resolve_user' => static fn() => auth()->user(),
    ],
    /*
    |--------------------------------------------------------------------------
    | Event System
    |--------------------------------------------------------------------------
    */
    'events'             => [
        'enabled'               => true,
        'fire_before_transform' => true,
        'fire_after_transform'  => true,
        'fire_cache_events'     => true,
    ],
    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    */
    'localization'       => [
        'enabled'         => true,
        'default_locale'  => 'en',
        'fallback_locale' => 'en',
    ],
    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    */
    'error_handling'     => [
        'include_trace'           => env('APP_DEBUG', false),
        'include_exception_class' => env('APP_DEBUG', false),
        'log_errors'              => true,
        'error_codes'             => true,
        'trace_limit'             => 5,
    ],
    /*
    |--------------------------------------------------------------------------
    | Exception Messages
    |--------------------------------------------------------------------------
    | Customize error messages for different exception types
    */
    'exception_messages' => [
        'authentication'     => 'Unauthenticated',
        'authorization'      => 'Forbidden',
        'validation'         => 'Validation failed',
        'not_found'          => 'Resource not found',
        'unprocessable'      => 'Unprocessable entity',
        'bad_request'        => 'Bad request',
        'method_not_allowed' => 'Method not allowed',
        'server_error'       => 'Internal server error',
    ],
    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limiting'      => [
        'include_headers' => true,
        'include_meta'    => true,
    ],
    /*
    |--------------------------------------------------------------------------
    | API Versioning
    |--------------------------------------------------------------------------
    */
    'versioning'         => [
        'enabled'            => true,
        'header_name'        => 'X-API-Version',
        'default_version'    => 'v1',
        'supported_versions' => [
            'v1',
            'v2',
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Batch Operations
    |--------------------------------------------------------------------------
    */
    'batch'              => [
        'enabled'        => true,
        'max_operations' => 50,
        'stop_on_error'  => false,
    ],
    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */
    'webhooks'           => [
        'enabled'          => false,
        'signature_header' => 'X-Webhook-Signature',
        'secret_key'       => env('WEBHOOK_SECRET'),
    ],
    /*
    |--------------------------------------------------------------------------
    | Response Compression
    |--------------------------------------------------------------------------
    */
    'compression'        => [
        'enabled'    => true,
        'min_size'   => 1024,
        // bytes
        'algorithms' => [
            'gzip',
            'deflate',
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Bindings - Configure custom implementations
    |--------------------------------------------------------------------------
    */
    'bindings'           => [
        'response_builder'     => ResponseBuilder::class,
        'cache_manager'        => CacheManager::class,
        'visibility_resolver'  => VisibilityResolver::class,
        'metadata_builder'     => MetadataBuilder::class,
        'fieldset_parser'      => FieldsetParser::class,
        'event_dispatcher'     => EventDispatcher::class,
        'validation_formatter' => ValidationFormatter::class,
    ],
];
