<?php /** @noinspection PhpUnusedParameterInspection */
namespace FallegaHQ\ApiResponder\Tests\Feature;

use FallegaHQ\ApiResponder\Attributes\ApiDescription;
use FallegaHQ\ApiResponder\Attributes\ApiGroup;
use FallegaHQ\ApiResponder\Attributes\ApiRequiresAuth;
use FallegaHQ\ApiResponder\Attributes\ApiTag;
use FallegaHQ\ApiResponder\Http\Controllers\BaseApiController;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

class ProductionAuthDetectionTest extends TestCase{
    /**
     * Test that matches production TodoController structure
     * Routes inside auth:sanctum middleware group should ALL get security field
     *
     * @throws \JsonException
     */
    public function test_api_resource_routes_in_auth_middleware_group_get_security_field(): void{
        // Register routes exactly like production: Route::middleware('auth:sanctum')->group()
        Route::middleware('auth:sanctum')
             ->group(
                 function(){
                     Route::apiResource('todos', ProductionTodoController::class);
                 }
             );
        // Generate documentation
        Artisan::call('api:generate-docs');
        // Read generated documentation
        $json = json_decode(file_get_contents(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        // All methods should have security field
        $methods = [
            'get',
            'post',
            'put',
            'patch',
            'delete',
        ];
        foreach($methods as $method){
            if($method === 'get'){
                // GET /api/todos (index)
                $this->assertArrayHasKey('/api/todos', $json['paths']);
                $this->assertArrayHasKey('get', $json['paths']['/api/todos']);
                $this->assertArrayHasKey(
                    'security',
                    $json['paths']['/api/todos']['get'],
                    "GET /api/todos should have security field (route is in auth:sanctum group)"
                );
            }
            elseif($method === 'post'){
                // POST /api/todos (store)
                $this->assertArrayHasKey('post', $json['paths']['/api/todos']);
                $this->assertArrayHasKey(
                    'security',
                    $json['paths']['/api/todos']['post'],
                    "POST /api/todos should have security field (route is in auth:sanctum group)"
                );
            }
            else{
                // PUT/PATCH/DELETE /api/todos/{todo}
                $this->assertArrayHasKey('/api/todos/{todo}', $json['paths']);
                $this->assertArrayHasKey($method, $json['paths']['/api/todos/{todo}']);
                $this->assertArrayHasKey(
                    'security',
                    $json['paths']['/api/todos/{todo}'][$method],
                    strtoupper(
                        $method
                    ) . " /api/todos/{todo} should have security field (route is in auth:sanctum group)"
                );
            }
        }
    }

    /**
     * Test class-level ApiRequiresAuth attribute
     *
     * @throws \JsonException
     */
    public function test_class_level_api_requires_auth_applies_to_all_methods(): void{
        Route::apiResource('categories', ProductionCategoryController::class);
        Artisan::call('api:generate-docs');
        $json = json_decode(file_get_contents(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        // All methods should have security field due to class-level #[ApiRequiresAuth]
        $this->assertArrayHasKey(
            'security',
            $json['paths']['/api/categories']['get'],
            "GET should have security from class-level ApiRequiresAuth"
        );
        $this->assertArrayHasKey(
            'security',
            $json['paths']['/api/categories']['post'],
            "POST should have security from class-level ApiRequiresAuth"
        );
        $this->assertArrayHasKey(
            'security',
            $json['paths']['/api/categories/{category}']['put'],
            "PUT should have security from class-level ApiRequiresAuth"
        );
        $this->assertArrayHasKey(
            'security',
            $json['paths']['/api/categories/{category}']['patch'],
            "PATCH should have security from class-level ApiRequiresAuth"
        );
        $this->assertArrayHasKey(
            'security',
            $json['paths']['/api/categories/{category}']['delete'],
            "DELETE: should have security from class-level ApiRequiresAuth"
        );
    }

    /**
     * Test method-level ApiRequiresAuth attribute
     *
     * @throws \JsonException
     */
    public function test_method_level_api_requires_auth_on_get_endpoint(): void{
        Route::get(
            'api/me',
            [
                ProductionAuthController::class,
                'me',
            ]
        );
        Artisan::call('api:generate-docs');
        $json = json_decode(file_get_contents(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey(
            'security',
            $json['paths']['/api/me']['get'],
            "GET /api/me should have security from method-level ApiRequiresAuth"
        );
    }
}

#[ApiGroup(name: 'Todo Management', description: 'Manage todos', priority: 1)]
#[ApiTag('Todos')]
#[ApiRequiresAuth]
class ProductionTodoController extends BaseApiController{
    #[ApiDescription(summary: 'List all todos', description: 'Returns all todos')]
    public function index(): JsonResponse{
        return $this->success([]);
    }

    #[ApiDescription(summary: 'Create a new todo', description: 'Creates a new todo')]
    public function store(): JsonResponse{
        return $this->created([]);
    }

    #[ApiDescription(summary: 'Get a single todo', description: 'Returns a specific todo')]
    public function show($id): JsonResponse{
        return $this->success([]);
    }

    #[ApiDescription(summary: 'Update a todo', description: 'Updates an existing todo')]
    public function update($id): JsonResponse{
        return $this->success([]);
    }

    #[ApiDescription(summary: 'Delete a todo', description: 'Deletes a todo')]
    public function destroy($id): JsonResponse{
        return $this->noContent();
    }
}

#[ApiGroup(name: 'Category Management', description: 'Manage categories', priority: 2)]
#[ApiTag('Categories')]
#[ApiRequiresAuth]
class ProductionCategoryController extends BaseApiController{
    #[ApiDescription(summary: 'List all categories', description: 'Returns all categories')]
    public function index(): JsonResponse{
        return $this->success([]);
    }

    public function store(): JsonResponse{
        return $this->created([]);
    }

    public function update($id): JsonResponse{
        return $this->success([]);
    }

    public function destroy($id): JsonResponse{
        return $this->noContent();
    }
}

#[ApiGroup(name: 'Authentication', description: 'User authentication', priority: 0)]
#[ApiTag('Auth')]
class ProductionAuthController extends BaseApiController{
    #[ApiDescription(summary: 'Get current user', description: 'Returns authenticated user data')]
    #[ApiRequiresAuth]
    public function me(): JsonResponse{
        return $this->success([]);
    }
}
