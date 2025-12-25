# Laravel API Responder

A comprehensive Laravel package for building consistent, feature-rich API responses with DTOs, caching, role-based
visibility, and more.

[![PHP Version](https://img.shields.io/badge/php-%5E8.1%7C%5E8.2%7C%5E8.3-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E10.0%7C%5E11.0%7C%5E12.0-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

## Features

### Core Features
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

### Advanced Documentation Generation
- 📝 **OpenAPI 3.0 Generation** - Auto-generate complete API documentation
- 🔒 **Authentication Awareness** - Automatically detect and mark protected routes
- 🏷️ **Smart Tagging & Grouping** - Organize endpoints with custom tags and groups
- 📖 **Human-Friendly Descriptions** - Document routes with intuitive attributes
- 🧪 **Test Coverage Validation** - Verify documentation accuracy with test integration
- 🔍 **Query Parameter Documentation** - Full validation rules (min, max, enum, format)
- 🔐 **Security Schemes** - Bearer token authentication included automatically

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

DTOs automatically include all model attributes. Define methods only for **additional computed fields**:

```php
<?php

namespace App\DTOs;

use FallegaHQ\ApiResponder\DTO\BaseDTO;
use FallegaHQ\ApiResponder\DTO\Attributes\{ComputedField, Visible, Cached};

class UserDTO extends BaseDTO
{
    #[ComputedField(name: 'posts_count')]
    #[Cached(ttl: 3600)]
    public function postsCount(): int
    {
        return $this->source->posts()->count();
    }

    #[ComputedField(name: 'is_admin')]
    #[Visible(['admin', 'manager'])]
    public function isAdmin(): bool
    {
        return $this->source->role === 'admin';
    }

    #[ComputedField(name: 'full_name')]
    public function fullName(): string
    {
        return $this->source->first_name . ' ' . $this->source->last_name;
    }

    protected function getHiddenFields(): array
    {
        return ['password', 'remember_token'];
    }
}
```

### Link DTO to Model

Use the `#[UseDto]` attribute on your model:

```php
<?php

namespace App\Models;

use App\DTOs\UserDTO;
use FallegaHQ\ApiResponder\Attributes\UseDto;
use Illuminate\Database\Eloquent\Model;

#[UseDto(UserDTO::class)]
class User extends Model
{
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

### Create a Controller

With `#[UseDto]` on the model, transformation happens automatically:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use FallegaHQ\ApiResponder\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

class UserController extends BaseApiController
{
    public function index()
    {
        $users = User::paginate(15);

        return $this->success($users, 'Users retrieved successfully');
    }

    public function show(User $user)
    {
        return $this->success($user);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
        ]);

        $user = User::create($validated);

        return $this->created($user, 'User created successfully');
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
            "created_at": "2024-12-22T10:00:00+00:00",
            "posts_count": 42,
            "is_admin": true,
            "full_name": "John Doe"
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
    "data": [
        "..."
    ],
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
#[ComputedField(name: 'sensitive_data')]
#[Visible(['admin', 'manager'])]
public function sensitiveData(): string
{
    return $this->source->internal_data;
}
```

### Field-Level Caching

Cache expensive computations:

```php
#[ComputedField(name: 'statistics')]
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
#[ComputedField(name: 'new_field')]
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
use Tests\TestCase;
use FallegaHQ\ApiResponder\Tests\AssertableApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_users()
    {
        User::factory()->count(3)->create();

        $response = $this->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [['id', 'name', 'email', 'posts_count']],
                'meta'
            ]);
    }

    public function test_show_returns_single_user()
    {
        $user = User::factory()->create(['name' => 'John Doe']);

        $response = $this->getJson("/api/users/{$user->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => 'John Doe',
                ]
            ]);
    }
}
```

## Documentation Attributes Reference

The package provides powerful attributes for documenting your API endpoints:

| Attribute | Target | Purpose | Parameters |
|-----------|--------|---------|------------|
| `#[ApiDescription]` | Method | Custom summary and description | `summary`, `description`, `requiresAuth` |
| `#[ApiParam]` | Method | Query parameter documentation | `name`, `type`, `description`, `required`, `example`, `minimum`, `maximum`, `enum`, `format` |
| `#[ApiTag]` | Class/Method | Categorize endpoints | `tags` (string or array) |
| `#[ApiGroup]` | Class/Method | Group with description | `name`, `description`, `priority` |
| `#[ApiRequest]` | Method | Request body schema | `fields`, `description`, `dto` |
| `#[ApiResponse]` | Method | Response schema | `model`, `type`, `description`, `statusCodes` |
| `#[UseDto]` | Class | Link DTO to model | `dtoClass` |

### Attribute Examples

#### `#[ApiDescription]` - Endpoint Documentation

```php
#[ApiDescription(
    summary: 'Create new post',
    description: 'Creates a new blog post with validation',
    requiresAuth: true  // Marks as protected in docs
)]
public function store(Request $request) { }
```

#### `#[ApiParam]` - Query Parameters (Repeatable)

```php
#[ApiParam('page', 'integer', 'Page number', required: false, example: 1, minimum: 1)]
#[ApiParam('per_page', 'integer', 'Items per page', minimum: 1, maximum: 100)]
#[ApiParam('status', 'string', 'Filter by status', enum: ['active', 'inactive'])]
#[ApiParam('search', 'string', 'Search term', required: false)]
public function index() { }
```

#### `#[ApiTag]` - Categorization

```php
// Class-level (applies to all methods)
#[ApiTag(['Users', 'Management'])]
class UserController extends BaseApiController { }

// Method-level (additional tags)
#[ApiTag('Auth')]
public function login() { }
```

#### `#[ApiGroup]` - Grouping with Description

```php
#[ApiGroup(
    name: 'User Management',
    description: 'Endpoints for managing user accounts and profiles',
    priority: 1  // Controls display order
)]
class UserController extends BaseApiController { }
```

## Documentation

- [Configuration Reference](config/api-responder.php) - All available configuration options

## OpenAPI Documentation Generation

The package can automatically generate OpenAPI 3.0 documentation from your routes, DTOs, and attributes with advanced features:

- **Authentication Awareness** - Automatically detects and marks protected routes
- **Smart Grouping & Tagging** - Organizes endpoints by category with custom tags
- **Human-Friendly Descriptions** - Document routes with intuitive attributes
- **Testing Integration** - Validates documentation accuracy and test coverage

### Generate Documentation

```bash
# Generate to default location (api-docs.json)
php artisan api:generate-docs

# Custom output file
php artisan api:generate-docs --output=openapi.json

# Validate test coverage
php artisan api:generate-docs --validate-tests

# Show warnings for missing tests
php artisan api:generate-docs --validate-tests --show-warnings
```

### Document Your API with Attributes

Use attributes to enrich your generated documentation:

```php
use FallegaHQ\ApiResponder\Attributes\{
    ApiDescription, ApiParam, ApiTag, ApiGroup,
    ApiRequest, ApiResponse, UseDto
};

#[UseDto(UserDTO::class)]
class User extends Model
{
    // Model definition
}

#[ApiTag(['Users', 'Management'])]
#[ApiGroup('Users', 'User management and profile operations')]
class UserController extends BaseApiController
{
    #[ApiDescription(
        summary: 'List all users',
        description: 'Retrieves a paginated list of all users in the system',
        requiresAuth: true
    )]
    #[ApiParam('page', 'integer', 'Page number', required: false, example: 1)]
    #[ApiParam('per_page', 'integer', 'Items per page', required: false, minimum: 1, maximum: 100)]
    #[ApiParam('search', 'string', 'Search by name or email', required: false)]
    #[ApiResponse(
        model: User::class,
        type: 'paginated',
        description: 'Returns paginated list of users'
    )]
    public function index()
    {
        return $this->success(User::paginate());
    }

    #[ApiDescription(
        summary: 'Get user details',
        description: 'Retrieves detailed information about a specific user',
        requiresAuth: true
    )]
    #[ApiResponse(
        model: User::class,
        type: 'single',
        description: 'Returns a single user with full details'
    )]
    public function show(User $user)
    {
        return $this->success($user);
    }

    #[ApiDescription(
        summary: 'Create new user',
        description: 'Creates a new user account with the provided information'
    )]
    #[ApiRequest(
        fields: [
            'name' => ['type' => 'string', 'description' => 'User name', 'example' => 'John Doe'],
            'email' => ['type' => 'string', 'format' => 'email', 'description' => 'User email'],
            'password' => ['type' => 'string', 'format' => 'password', 'minimum' => 8]
        ],
        description: 'Create a new user'
    )]
    #[ApiResponse(model: User::class, type: 'single')]
    #[ApiTag('Auth')]
    public function store(Request $request)
    {
        // Implementation
    }
}
```

### Available Documentation Attributes

#### `#[ApiDescription]`
Provides human-friendly summary and description for endpoints:

```php
#[ApiDescription(
    summary: 'Short summary of the endpoint',
    description: 'Detailed description with markdown support',
    requiresAuth: true  // Marks endpoint as protected
)]
```

#### `#[ApiParam]`
Documents query parameters (repeatable):

```php
#[ApiParam(
    name: 'filter',
    type: 'string',
    description: 'Filter results',
    required: false,
    example: 'active',
    enum: ['active', 'inactive', 'pending']
)]
#[ApiParam('page', 'integer', 'Page number', minimum: 1)]
```

#### `#[ApiTag]`
Organizes endpoints into categories (repeatable, can be used on class or method):

```php
#[ApiTag('Users')]  // Single tag
#[ApiTag(['Users', 'Admin'])]  // Multiple tags
```

#### `#[ApiGroup]`
Groups related endpoints with descriptions (can be used on class or method):

```php
#[ApiGroup(
    name: 'User Management',
    description: 'Endpoints for managing user accounts and profiles',
    priority: 1  // Controls display order
)]
```

### Generated Documentation Features

The generated OpenAPI documentation includes:

- **Authentication Detection** - Automatically identifies protected routes via middleware or attributes
- **Security Schemes** - Bearer token authentication schema included
- **Request Schemas** - All request bodies are stored as reusable components in `components/schemas`
- **Response Schemas** - DTOs with computed fields are fully documented
- **URL Parameters** - Path and query parameters with full validation rules
- **Validation Rules** - Field types, formats, minimums, maximums, enums, etc.
- **Descriptions** - Custom descriptions from attributes with markdown support
- **Pagination** - Paginated responses include links and metadata schemas
- **Error Responses** - Standard error response schemas
- **Smart Tagging** - Organized by category with 'Auth' tag for protected endpoints
- **Test Coverage** - Validates that routes have corresponding tests

### Testing Integration

The documentation generator can validate your test coverage:

```bash
php artisan api:generate-docs --validate-tests --show-warnings
```

**Output:**
```
Test Coverage Analysis:

Total Routes: 15
Tested Routes: 12
Coverage: 80%

Routes Missing Tests:
  • GET /api/users/{user}/posts
    Add test: $this->getJson('/api/users/{user}/posts')
  • DELETE /api/posts/{post}
    Add test: $this->deleteJson('/api/posts/{post}')
```

The validator:
- Scans your `tests/Feature` and `tests/Unit` directories
- Checks if route URIs or names appear in test files
- Provides actionable recommendations for missing tests
- Calculates overall test coverage percentage

### Example Generated Schema

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "My API",
    "version": "v1"
  },
  "tags": [
    {
      "name": "Auth",
      "description": "Authentication and authorization endpoints"
    },
    {
      "name": "Users",
      "description": "User management endpoints"
    }
  ],
  "paths": {
    "/api/users": {
      "get": {
        "summary": "List all users",
        "description": "Retrieves a paginated list of all users in the system\n\n**Authentication Required:** Yes",
        "tags": ["Users", "Auth"],
        "security": [{"bearerAuth": []}],
        "parameters": [
          {
            "name": "page",
            "in": "query",
            "required": false,
            "schema": {"type": "integer"},
            "example": 1
          }
        ]
      }
    }
  },
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
      }
    },
    "securitySchemes": {
      "bearerAuth": {
        "type": "http",
        "scheme": "bearer",
        "bearerFormat": "JWT",
        "description": "Enter your bearer token in the format: Bearer {token}"
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

For issues, questions, or contributions:

- 📖 [Documentation](README.md)
- 🐛 [GitHub Issues](https://github.com/fallegahq/laravel-api-responder/issues)
- 📦 [GitHub Repository](https://github.com/fallegahq/laravel-api-responder)
