# Laravel API Responder

A comprehensive Laravel package for building consistent, feature-rich API responses with DTOs, caching, role-based
visibility, and more.

[![PHP Version](https://img.shields.io/badge/php-%5E8.1%7C%5E8.2%7C%5E8.3-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E10.0%7C%5E11.0%7C%5E12.0-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

## Features

- 🎯 **Consistent API Responses** - Standardized JSON response structure
- 🔄 **DTO Transformation** - Attribute-based data transformation with PHP 8.1+ attributes
- 🔐 **Role-Based Visibility** - Control field visibility based on user roles
- ⚡ **Field-Level Caching** - Cache expensive computed fields automatically
- 📊 **Sparse Fieldsets** - Support for `?fields=id,name` query parameters
- 📄 **Automatic Pagination** - Built-in pagination support with metadata
- 🌍 **API Versioning** - Header-based API versioning
- 🎨 **Exception Handling** - Unified exception handling for Laravel 10, 11, and 12
- 📦 **Batch Operations** - Process multiple API requests in a single call
- 🧪 **Testing Helpers** - Fluent assertion helpers for API testing
- 📝 **Auto Documentation** - Generate OpenAPI documentation from routes

## Requirements

- PHP 8.1, 8.2, or 8.3
- Laravel 10.x, 11.x, or 12.x

## Installation

Install via Composer:

```bash
composer require fallegahq/laravel-api-responder
```

The package will auto-register via Laravel's package discovery.

### Publish Configuration

```bash
php artisan vendor:publish --tag=api-responder-config
```

This creates `config/api-responder.php` where you can customize all settings.

## Quick Start

### Laravel 12 Bootstrap Configuration

In your `bootstrap/app.php`:

```php
<?php

use FallegaHQ\ApiResponder\Bootstrap\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(
            prepend: [
                \FallegaHQ\ApiResponder\Http\Middleware\ApiResponderMiddleware::class,
            ]
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        ApiExceptionHandler::configure($exceptions, [
            'dontFlash' => ['current_password', 'password', 'password_confirmation'],
            'dontReportDuplicates' => true,
        ]);
    })
    ->create();
```

### Create a DTO

```php
<?php

namespace App\DTOs;

use FallegaHQ\ApiResponder\DTO\BaseDTO;
use FallegaHQ\ApiResponder\DTO\Attributes\{ComputedField, Visible, Cached, Versioned};

class UserDTO extends BaseDTO
{
    #[ComputedField]
    public function id(): int
    {
        return $this->source->id;
    }

    #[ComputedField]
    public function name(): string
    {
        return $this->source->name;
    }

    #[ComputedField]
    public function email(): string
    {
        return $this->source->email;
    }

    #[ComputedField]
    #[Visible(['admin', 'manager'])]
    public function role(): string
    {
        return $this->source->role;
    }

    #[ComputedField]
    #[Cached(ttl: 3600)]
    public function postsCount(): int
    {
        return $this->source->posts()->count();
    }
}
```

### Create a Controller

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\DTOs\UserDTO;
use FallegaHQ\ApiResponder\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

class UserController extends BaseApiController
{
    public function index()
    {
        $users = User::paginate(15);

        return $this->success(
            $users->through(fn($user) => UserDTO::from($user)),
            'Users retrieved successfully'
        );
    }

    public function show(User $user)
    {
        return $this->success(UserDTO::from($user));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
        ]);

        $user = User::create($validated);

        return $this->created(UserDTO::from($user), 'User created successfully');
    }

    public function destroy(User $user)
    {
        $user->delete();
        return $this->noContent();
    }
}
```

## Response Examples

### Success Response

```json
{
    "success": true,
    "message": "Users retrieved successfully",
    "data": [
        {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "posts_count": 42
        }
    ],
    "meta": {
        "timestamp": "2024-12-22T22:00:00+00:00",
        "request_id": "550e8400-e29b-41d4-a716-446655440000",
        "api_version": "v1",
        "execution_time": "45.23ms"
    }
}
```

### Paginated Response

```json
{
    "success": true,
    "data": [...],
    "meta": {
        "current_page": 1,
        "last_page": 5,
        "per_page": 15,
        "total": 73,
        "from": 1,
        "to": 15
    },
    "links": {
        "first": "http://api.example.com/users?page=1",
        "last": "http://api.example.com/users?page=5",
        "prev": null,
        "next": "http://api.example.com/users?page=2"
    }
}
```

### Error Response

```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "email": ["The email field is required."],
        "password": ["The password must be at least 8 characters."]
    },
    "meta": {
        "timestamp": "2024-12-22T22:00:00+00:00",
        "request_id": "550e8400-e29b-41d4-a716-446655440000"
    }
}
```

## Key Features

### Sparse Fieldsets

Request only the fields you need:

```bash
# Only return id and name
GET /api/users?fields=id,name

# Exclude sensitive fields
GET /api/users?exclude=email,role

# Include relationships
GET /api/users?include=posts,comments
```

### Role-Based Visibility

Control field visibility based on user roles:

```php
#[ComputedField]
#[Visible(['admin', 'manager'])]
public function sensitiveData(): string
{
    return $this->source->sensitive_data;
}
```

### Field-Level Caching

Cache expensive computations:

```php
#[ComputedField]
#[Cached(ttl: 3600, key: 'user_stats')]
public function statistics(): array
{
    return [
        'posts' => $this->source->posts()->count(),
        'followers' => $this->source->followers()->count(),
    ];
}
```

### API Versioning

Version your API responses:

```php
#[ComputedField]
#[Versioned(['v2'])]
public function newField(): string
{
    return 'Only visible in v2';
}
```

Request with header:

```bash
curl -H "X-API-Version: v2" https://api.example.com/users
```

### Testing

Use fluent assertion helpers:

```php
use FallegaHQ\ApiResponder\Tests\ApiTestCase;

class UserControllerTest extends ApiTestCase
{
    public function test_index_returns_users()
    {
        $response = $this->getJson('/api/users');

        $this->assertApiResponse($response)
            ->assertSuccess()
            ->assertHasPagination()
            ->assertFieldVisible('id')
            ->assertFieldHidden('password')
            ->assertStatus(200);
    }
}
```

## Documentation

- [Configuration Reference](config/api-responder.php) - All available configuration options

## OpenAPI Documentation Generation

The package can automatically generate OpenAPI 3.0 documentation from your routes, DTOs, and attributes.

### Generate Documentation

```bash
# Generate to default location (api-docs.json)
php artisan api:generate-docs

# Custom output file
php artisan api:generate-docs --output=openapi.json
```

### Document Your API with Attributes

Use attributes to enrich your generated documentation:

```php
use FallegaHQ\ApiResponder\Attributes\{ApiRequest, ApiResponse, UseDto};

#[UseDto(UserDTO::class)]
class User extends Model
{
    // Model definition
}

class UserController extends BaseApiController
{
    #[ApiResponse(
        model: User::class,
        type: 'paginated',
        description: 'Returns paginated list of users'
    )]
    public function index()
    {
        return $this->success(User::paginate());
    }

    #[ApiResponse(
        model: User::class,
        type: 'single',
        description: 'Returns a single user with full details'
    )]
    public function show(User $user)
    {
        return $this->success(UserDTO::from($user));
    }

    #[ApiRequest(
        fields: [
            'name' => ['type' => 'string', 'description' => 'User name', 'example' => 'John Doe'],
            'email' => ['type' => 'string', 'format' => 'email', 'description' => 'User email'],
            'password' => ['type' => 'string', 'format' => 'password', 'minimum' => 8]
        ],
        description: 'Create a new user'
    )]
    #[ApiResponse(model: User::class, type: 'single')]
    public function store(Request $request)
    {
        // Implementation
    }
}
```

### Generated Documentation Features

The generated OpenAPI documentation includes:

- **Request Schemas** - All request bodies are stored as reusable components in `components/schemas`
- **Response Schemas** - DTOs with computed fields are fully documented
- **URL Parameters** - Path parameters are automatically extracted
- **Validation Rules** - Field types, formats, minimums, enums, etc.
- **Descriptions** - Custom descriptions from attributes
- **Pagination** - Paginated responses include links and metadata schemas
- **Error Responses** - Standard error response schemas

### Example Generated Schema

```json
{
  "components": {
    "schemas": {
      "UserDTO": {
        "type": "object",
        "description": "DTO for User (includes all model attributes + computed fields)",
        "properties": {
          "id": {"type": "integer"},
          "name": {"type": "string"},
          "email": {"type": "string"},
          "posts_count": {
            "type": "integer",
            "description": "Computed field from postsCount"
          }
        }
      },
      "UserControllerStoreRequest": {
        "type": "object",
        "description": "Create a new user",
        "properties": {
          "name": {
            "type": "string",
            "description": "User name",
            "example": "John Doe"
          },
          "email": {
            "type": "string",
            "format": "email",
            "description": "User email"
          }
        }
      }
    }
  }
}
```

## Configuration

Key configuration options in `config/api-responder.php`:

```php
return [
    // API routing detection
    'routing' => [
        'prefix' => env('API_PREFIX', 'api'), // Set to '' for no prefix
        'detect_json' => true,
    ],

    // Response structure keys
    'structure' => [
        'success_key' => 'success',
        'data_key' => 'data',
        'message_key' => 'message',
        'errors_key' => 'errors',
        'meta_key' => 'meta',
    ],

    // Exception messages (customizable)
    'exception_messages' => [
        'authentication' => 'Unauthenticated',
        'validation' => 'Validation failed',
        'not_found' => 'Resource not found',
        // ... more
    ],

    // Enable/disable features
    'cache' => ['enabled' => true],
    'sparse_fieldsets' => ['enabled' => true],
    'versioning' => ['enabled' => true],
    'batch' => ['enabled' => true],
    
    // Customize behavior
    'pagination' => ['default_per_page' => 15],
    'visibility' => ['resolve_user' => fn() => auth()->user()],
];
```

### Customizing API Prefix

The package supports flexible API route detection:

```php
// No prefix - handle all routes
'routing' => ['prefix' => ''],

// Custom prefix
'routing' => ['prefix' => 'v1'],

// Via environment variable
API_PREFIX=api/v2
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## Credits

- **Author**: SAKHRAOUI Omar
- **Email**: softwyx@softwyx.com
- **Organization**: SoftWyx

## Support

For issues, questions, or contributions, please visit
the [GitHub repository](https://github.com/fallegahq/laravel-api-responder).
