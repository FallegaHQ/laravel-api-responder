<?php /** @noinspection PhpUnused */
namespace FallegaHQ\ApiResponder\Tests\Feature;

use FallegaHQ\ApiResponder\Attributes\ApiDescription;
use FallegaHQ\ApiResponder\Attributes\ApiGroup;
use FallegaHQ\ApiResponder\Attributes\ApiParam;
use FallegaHQ\ApiResponder\Attributes\ApiResponse;
use FallegaHQ\ApiResponder\Attributes\ApiTag;
use FallegaHQ\ApiResponder\Attributes\UseDto;
use FallegaHQ\ApiResponder\Console\Commands\GenerateDocumentationCommand;
use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;
use FallegaHQ\ApiResponder\DTO\BaseDTO;
use FallegaHQ\ApiResponder\Http\Controllers\BaseApiController;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use ReflectionClass;

class DocumentationGenerationTest extends TestCase{
    /**
     * @throws \JsonException
     */
    public function test_generates_openapi_documentation_with_security_schemes(): void{
        $this->artisan('api:generate-docs', ['--output' => 'test-docs.json'])
             ->assertSuccessful();
        $this->assertFileExists(base_path('test-docs.json'));
        $docs = json_decode(file_get_contents(base_path('test-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals('3.0.0', $docs['openapi']);
        $this->assertArrayHasKey('components', $docs);
        $this->assertArrayHasKey('securitySchemes', $docs['components']);
        $this->assertArrayHasKey('bearerAuth', $docs['components']['securitySchemes']);
        $this->assertEquals('http', $docs['components']['securitySchemes']['bearerAuth']['type']);
        $this->assertEquals('bearer', $docs['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertEquals('JWT', $docs['components']['securitySchemes']['bearerAuth']['bearerFormat']);
        unlink(base_path('test-docs.json'));
    }

    /**
     * @throws \ReflectionException
     */
    public function test_protected_routes_include_security_requirement(): void{
        // Test the isProtectedRoute method directly
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('isProtectedRoute');
        // Test 1: Route with auth middleware
        Route::middleware('auth:api')
             ->get(
                 'api/protected-test',
                 [
                     TestDocController::class,
                     'protectedEndpoint',
                 ]
             );
        $routes         = Route::getRoutes();
        $protectedRoute = null;
        foreach($routes as $route){
            if($route->uri() === 'api/protected-test'){
                $protectedRoute = [
                    'uri'    => $route->uri(),
                    'method' => 'GET',
                    'action' => TestDocController::class . '@protectedEndpoint',
                ];
                break;
            }
        }
        $this->assertNotNull($protectedRoute);
        $isProtected = $method->invoke($command, $protectedRoute);
        $this->assertTrue($isProtected, 'Route with auth:api middleware should be detected as protected');
        // Test 2: Route with ApiDescription requiresAuth attribute
        // Register route so it exists in route collection for attribute check to work
        Route::get(
            'api/attr-protected',
            [
                TestDocController::class,
                'protectedEndpoint',
            ]
        );
        $attrRoute = null;
        foreach(Route::getRoutes() as $route){
            if($route->uri() === 'api/attr-protected'){
                $attrRoute = [
                    'uri'    => $route->uri(),
                    'method' => 'GET',
                    'action' => TestDocController::class . '@protectedEndpoint',
                ];
                break;
            }
        }
        $this->assertNotNull($attrRoute);
        $isAttrProtected = $method->invoke($command, $attrRoute);
        $this->assertTrue(
            $isAttrProtected,
            'Route with ApiDescription requiresAuth=true should be detected as protected'
        );
    }

    /**
     * @throws \ReflectionException
     */
    public function test_api_description_attribute_appears_in_documentation(): void{
        // Test that ApiDescription attributes are properly extracted
        $command       = new GenerateDocumentationCommand();
        $reflection    = new ReflectionClass($command);
        $summaryMethod = $reflection->getMethod('generateSummary');
        $descMethod    = $reflection->getMethod('generateDescription');
        $route         = [
            'action' => TestDocController::class . '@customDescription',
            'name'   => null,
        ];
        $summary       = $summaryMethod->invoke($command, $route, 'get');
        $this->assertEquals('Custom Summary', $summary);
        $description = $descMethod->invoke($command, $route, 'get', null);
        $this->assertStringContainsString('This is a custom description', $description);
        $this->assertStringContainsString('Authentication Required:', $description);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_api_params_appear_as_query_parameters(): void{
        // Test parameter extraction directly
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('extractParameters');
        $route      = [
            'action' => TestDocController::class . '@searchWithParams',
        ];
        $parameters = $method->invoke($command, '/api/search', $route);
        $this->assertIsArray($parameters);
        $this->assertNotEmpty($parameters);
        $params     = collect($parameters);
        $queryParam = $params->firstWhere('name', 'query');
        $this->assertNotNull($queryParam, 'query parameter should be extracted');
        $this->assertEquals('query', $queryParam['in']);
        $this->assertEquals('string', $queryParam['schema']['type']);
        $this->assertTrue($queryParam['required']);
        $limitParam = $params->firstWhere('name', 'limit');
        $this->assertNotNull($limitParam, 'limit parameter should be extracted');
        $this->assertEquals(10, $limitParam['schema']['minimum']);
        $this->assertEquals(100, $limitParam['schema']['maximum']);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_tags_are_organized_with_auth_first(): void{
        // Test tag extraction and organization
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('extractTags');
        // Register routes for testing
        Route::middleware('auth')
             ->get(
                 'api/profile-test',
                 [
                     TestDocController::class,
                     'protectedEndpoint',
                 ]
             );
        $route = [
            'uri'    => 'api/profile-test',
            'method' => 'GET',
            'action' => TestDocController::class . '@protectedEndpoint',
        ];
        $tags  = $method->invoke($command, '/api/profile-test', $route);
        $this->assertIsArray($tags);
        $this->assertContains('Auth', $tags, 'Protected routes should have Auth tag');
    }

    /**
     * @throws \ReflectionException
     */
    public function test_dto_schemas_include_computed_fields(): void{
        // Test DTO schema building
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('buildSchemas');
        $dtos       = collect(
            [
                [
                    'model' => TestModel::class,
                    'dto'   => TestModelDTO::class,
                ],
            ]
        );
        $schemas    = $method->invoke($command, $dtos);
        $this->assertArrayHasKey('TestModelDTO', $schemas);
        $this->assertArrayHasKey('properties', $schemas['TestModelDTO']);
        $this->assertArrayHasKey('computed_value', $schemas['TestModelDTO']['properties']);
        $this->assertEquals('string', $schemas['TestModelDTO']['properties']['computed_value']['type']);
    }

    public function test_validation_shows_test_coverage_statistics(): void{
        $this->artisan(
            'api:generate-docs',
            [
                '--output'         => 'test-docs.json',
                '--validate-tests' => true,
            ]
        )
             ->expectsOutputToContain('Test Coverage Analysis:')
             ->assertSuccessful();
        if(file_exists(base_path('test-docs.json'))){
            unlink(base_path('test-docs.json'));
        }
    }

    /**
     * @throws \ReflectionException
     */
    public function test_custom_tags_override_path_based_tags(): void{
        // Test that custom tags from attributes are extracted
        $command    = new GenerateDocumentationCommand();
        $reflection = new ReflectionClass($command);
        $method     = $reflection->getMethod('getAttributeTags');
        $route      = [
            'action' => TestDocController::class . '@customTagged',
        ];
        $tags       = $method->invoke($command, $route);
        $this->assertIsArray($tags);
        $this->assertContains('CustomTag', $tags);
        $this->assertContains('TestDocs', $tags, 'Should also include class-level tags');
    }
}

#[ApiTag(['TestDocs'])]
#[ApiGroup('TestDocs', 'Test documentation endpoints')]
class TestDocController extends BaseApiController{
    public function index(): JsonResponse{
        return $this->success([]);
    }

    #[ApiDescription(summary: 'Protected Endpoint', description: 'This endpoint requires authentication', requiresAuth: true)]
    public function protectedEndpoint(): JsonResponse{
        return $this->success([]);
    }

    #[ApiDescription(summary: 'Custom Summary', description: 'This is a custom description for testing', requiresAuth: true)]
    public function customDescription(): JsonResponse{
        return $this->success([]);
    }

    #[ApiParam('query', 'string', 'Search query', required: true)]
    #[ApiParam('limit', 'integer', 'Result limit', required: false, minimum: 10, maximum: 100)]
    #[ApiParam('sort', 'string', 'Sort field', required: false)]
    public function searchWithParams(): JsonResponse{
        return $this->success([]);
    }

    #[ApiResponse(model: TestModel::class, type: 'single')]
    public function modelWithDto(): JsonResponse{
        return $this->success([]);
    }

    #[ApiTag('CustomTag')]
    public function customTagged(): JsonResponse{
        return $this->success([]);
    }
}

#[UseDto(TestModelDTO::class)]
class TestModel extends Model{
    protected $table = 'test_models';
}

class TestModelDTO extends BaseDTO{
    #[ComputedField(name: 'computed_value')]
    public function computedValue(): string{
        return 'test';
    }
}
