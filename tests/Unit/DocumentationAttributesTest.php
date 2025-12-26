<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Attributes\ApiDescription;
use FallegaHQ\ApiResponder\Attributes\ApiGroup;
use FallegaHQ\ApiResponder\Attributes\ApiParam;
use FallegaHQ\ApiResponder\Attributes\ApiRequiresAuth;
use FallegaHQ\ApiResponder\Attributes\ApiTag;
use FallegaHQ\ApiResponder\Console\Commands\GenerateDocumentationCommand;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use ReflectionClass;

class DocumentationAttributesTest extends TestCase{
    /**
     * @throws \ReflectionException
     */
    public function test_api_description_overrides_generated_summary(): void{
        Route::get(
            'test-route',
            [
                TestController::class,
                'methodWithDescription',
            ]
        );
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('generateSummary');
        $route      = [
            'action' => TestController::class . '@methodWithDescription',
            'name'   => null,
        ];
        $summary    = $method->invoke($command, $route, 'get');
        $this->assertEquals('Custom endpoint summary', $summary);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_api_description_marks_route_as_protected(): void{
        Route::get(
            'test-auth',
            [
                TestController::class,
                'protectedMethod',
            ]
        );
        $command     = new GenerateDocumentationCommand();
        $reflection  = new ReflectionClass($command);
        $method      = $reflection->getMethod('isProtectedRoute');
        $route       = [
            'uri'    => 'test-auth',
            'method' => 'GET',
            'action' => TestController::class . '@protectedMethod',
        ];
        $isProtected = $method->invoke($command, $route);
        $this->assertTrue($isProtected);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_api_params_are_extracted_to_openapi_parameters(): void{
        Route::get(
            'test-params',
            [
                TestController::class,
                'methodWithParams',
            ]
        );
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('extractParameters');
        $route      = [
            'action' => TestController::class . '@methodWithParams',
        ];
        $parameters = $method->invoke($command, '/test-params', $route);
        $this->assertCount(3, $parameters);
        $pageParam = collect($parameters)->firstWhere('name', 'page');
        $this->assertEquals('query', $pageParam['in']);
        $this->assertEquals('integer', $pageParam['schema']['type']);
        $this->assertFalse($pageParam['required']);
        $this->assertEquals(1, $pageParam['example']);
        $statusParam = collect($parameters)->firstWhere('name', 'status');
        $this->assertEquals(
            [
                'active',
                'inactive',
                'pending',
            ],
            $statusParam['schema']['enum']
        );
    }

    /**
     * @throws \ReflectionException
     */
    public function test_api_tags_are_extracted_from_controller(): void{
        Route::get(
            'test-tags',
            [
                TestController::class,
                'methodWithTags',
            ]
        );
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('getAttributeTags');
        $route      = [
            'action' => TestController::class . '@methodWithTags',
        ];
        $tags       = $method->invoke($command, $route);
        $this->assertContains('Users', $tags);
        $this->assertContains('Admin', $tags);
        $this->assertContains('Management', $tags);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_protected_routes_get_auth_tag(): void{
        Route::get(
            'test-auth-tag',
            [
                TestController::class,
                'protectedMethod',
            ]
        );
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('extractTags');
        $route      = [
            'uri'    => 'test-auth-tag',
            'method' => 'GET',
            'action' => TestController::class . '@protectedMethod',
        ];
        $tags       = $method->invoke($command, '/test-auth-tag', $route);
        $this->assertContains('Auth', $tags);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_api_group_provides_tag_description(): void{
        Route::get(
            'test-group',
            [
                TestController::class,
                'methodWithGroup',
            ]
        );
        $command     = new GenerateDocumentationCommand();
        $reflection  = new ReflectionClass($command);
        $method      = $reflection->getMethod('getTagDescription');
        $route       = [
            'action' => TestController::class . '@methodWithGroup',
        ];
        $description = $method->invoke($command, 'UserManagement', $route);
        $this->assertEquals('Manage user accounts and permissions', $description);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_multiple_api_params_create_multiple_parameters(): void{
        Route::get(
            'test-multi-params',
            [
                TestController::class,
                'methodWithParams',
            ]
        );
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('extractParameters');
        $route      = [
            'action' => TestController::class . '@methodWithParams',
        ];
        $parameters = $method->invoke($command, '/test-multi-params', $route);
        $names      = collect($parameters)
            ->pluck('name')
            ->toArray();
        $this->assertContains('page', $names);
        $this->assertContains('limit', $names);
        $this->assertContains('status', $names);
    }
}

#[ApiTag([
    'Users',
    'Admin',
])]
class TestController{
    #[ApiDescription(summary: 'Custom endpoint summary', description: 'This is a detailed description')]
    public function methodWithDescription(): void{}

    #[ApiDescription(summary: 'Protected endpoint', description: 'Requires authentication')]
    #[ApiRequiresAuth]
    public function protectedMethod(): void{}

    #[ApiParam('page', 'integer', 'Page number', required: false, example: 1)]
    #[ApiParam('limit', 'integer', 'Items per page', required: false, minimum: 1, maximum: 100)]
    #[ApiParam('status', 'string', 'Filter by status', required: false, enum: [
        'active',
        'inactive',
        'pending',
    ])]
    public function methodWithParams(): void{}

    #[ApiTag('Management')]
    public function methodWithTags(): void{}

    #[ApiGroup('UserManagement', 'Manage user accounts and permissions')]
    public function methodWithGroup(): void{}
}
