<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\ApiResponderServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class GenerateDocumentationCommandTest extends TestCase{
    protected string $modelsPath;

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_command_generates_documentation_file(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $filePath = base_path('api-docs.json');
        $this->assertTrue(File::exists($filePath));
        $content = json_decode(File::get($filePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('openapi', $content);
        $this->assertEquals('3.0.0', $content['openapi']);
        $this->assertArrayHasKey('info', $content);
        $this->assertArrayHasKey('paths', $content);
        $this->assertArrayHasKey('components', $content);
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_command_includes_dto_transformation_description(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('description', $content['info']);
        $this->assertNotEmpty($content['info']['description']);
        $this->assertStringContainsString('API', $content['info']['description']);
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_command_generates_schemas_for_dtos(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('schemas', $content['components']);
        $this->assertIsArray($content['components']['schemas']);
    }

    public function test_command_output_shows_scan_results(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $output = Artisan::output();
        $this->assertStringContainsString('Scanning routes and DTOs', $output);
        $this->assertStringContainsString('API documentation generated', $output);
    }

    public function test_command_with_custom_output_path(): void{
        $customPath = 'custom-docs.json';
        Artisan::call('api:generate-docs', ['--output' => $customPath]);
        $this->assertTrue(File::exists(base_path($customPath)));
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_generated_documentation_is_valid_json(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = File::get(base_path('api-docs.json'));
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotNull($decoded);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_documentation_includes_openapi_version(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals('3.0.0', $content['openapi']);
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_documentation_includes_api_info(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('title', $content['info']);
        $this->assertArrayHasKey('version', $content['info']);
        $this->assertArrayHasKey('description', $content['info']);
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_documentation_includes_servers(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('servers', $content);
        $this->assertIsArray($content['servers']);
        $this->assertNotEmpty($content['servers']);
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_schemas_have_correct_structure(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ResponseMeta', $content['components']['schemas']);
        $this->assertArrayHasKey('PaginationLinks', $content['components']['schemas']);
        $this->assertArrayHasKey('properties', $content['components']['schemas']['ResponseMeta']);
        $this->assertArrayHasKey('timestamp', $content['components']['schemas']['ResponseMeta']['properties']);
        $this->assertArrayHasKey('api_version', $content['components']['schemas']['ResponseMeta']['properties']);
        $this->assertArrayHasKey('current_page', $content['components']['schemas']['ResponseMeta']['properties']);
        $this->assertArrayHasKey('first', $content['components']['schemas']['PaginationLinks']['properties']);
        $this->assertArrayHasKey('next', $content['components']['schemas']['PaginationLinks']['properties']);
        $this->assertNotEmpty($content['components']['schemas']);
        // Check that we have both DTO schemas and response shape schemas
        // Verify DTO schemas have correct structure
        foreach($content['components']['schemas'] as $schemaName => $schema){
            // Skip ValidationError as it uses allOf
            if($schemaName === 'ValidationError'){
                $this->assertArrayHasKey('allOf', $schema);
                continue;
            }
            $this->assertArrayHasKey('type', $schema);
            $this->assertEquals('object', $schema['type']);
            $this->assertArrayHasKey('description', $schema);
        }
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_computed_fields_have_type_information(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($content['components']['schemas']);
        // Find DTO schemas (they have "DTO" in the name)
        $dtoSchemas = array_filter(
            $content['components']['schemas'],
            static fn($name) => str_contains($name, 'DTO'),
            ARRAY_FILTER_USE_KEY
        );
        $this->assertNotEmpty($dtoSchemas, 'Should have at least one DTO schema');
        foreach($dtoSchemas as $schema){
            if(!empty($schema['properties'])){
                foreach($schema['properties'] as $fieldName => $field){
                    $this->assertArrayHasKey('type', $field);
                    $this->assertContains(
                        $field['type'],
                        [
                            'string',
                            'integer',
                            'boolean',
                            'number',
                            'array',
                            'object',
                        ]
                    );
                    $this->assertArrayHasKey('description', $field);
                }
            }
        }
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_paths_are_structured_correctly(): void{
        Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
        $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('paths', $content);
        $this->assertIsArray($content['paths']);
        foreach($content['paths'] as $path => $methods){
            $this->assertIsString($path);
            $this->assertIsArray($methods);
            foreach($methods as $method => $details){
                $this->assertContains(
                    $method,
                    [
                        'get',
                        'post',
                        'put',
                        'patch',
                        'delete',
                    ]
                );
                $this->assertArrayHasKey('summary', $details);
                $this->assertArrayHasKey('responses', $details);
            }
        }
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_api_response_attribute_documents_response_shapes(): void{
        $controllerContent = <<<'PHP'
<?php
namespace App\Http\Controllers;

use FallegaHQ\ApiResponder\Attributes\ApiResponse;
use App\Models\TestDocUser;

class TestDocUserController {
    #[ApiResponse(model: TestDocUser::class, type: 'single', description: 'Returns a single user with full details')]
    public function show($id) {
        return response()->json(['user' => ['id' => $id]]);
    }
    
    #[ApiResponse(model: TestDocUser::class, type: 'collection', description: 'Returns all users')]
    public function index() {
        return response()->json(['users' => []]);
    }
    
    #[ApiResponse(model: TestDocUser::class, type: 'paginated')]
    public function paginated() {
        return response()->json(['users' => []]);
    }
}
PHP;
        $controllerPath    = app_path('Http/Controllers');
        if(!File::isDirectory($controllerPath)){
            File::makeDirectory($controllerPath, 0755, true);
        }
        $controllerFile = $controllerPath . '/TestDocUserController.php';
        File::put($controllerFile, $controllerContent);
        try{
            // Require the file to ensure it's loaded
            require_once $controllerFile;
            Route::get('/api/test-user/{id}', 'App\Http\Controllers\TestDocUserController@show')
                 ->name('api.test-user.show');
            Route::get('/api/test-users', 'App\Http\Controllers\TestDocUserController@index')
                 ->name('api.test-users.index');
            Route::get('/api/test-users-paginated', 'App\Http\Controllers\TestDocUserController@paginated')
                 ->name('api.test-users.paginated');
            Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
            $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
            // EXPECTATION: Single response should have DTO schema with success field
            $showResponse = $content['paths']['/api/test-user/{id}']['get']['responses']['200'];
            $schema       = $showResponse['content']['application/json']['schema'];
            $this->assertArrayHasKey('success', $schema['properties'], 'Should have success field');
            $this->assertEquals('boolean', $schema['properties']['success']['type']);
            $this->assertEquals('#/components/schemas/TestDocUserDTO', $schema['properties']['data']['$ref']);
            $this->assertEquals('#/components/schemas/ResponseMeta', $schema['properties']['meta']['$ref']);
            // EXPECTATION: Description should include response type from ApiResponse
            $showDescription = $content['paths']['/api/test-user/{id}']['get']['description'];
            $this->assertStringContainsString('Returns a single TestDocUser', $showDescription);
            $this->assertStringContainsString('Returns a single user with full details', $showDescription);
            // EXPECTATION: Collection response should be array of DTOs
            $indexResponse = $content['paths']['/api/test-users']['get']['responses']['200'];
            $indexSchema   = $indexResponse['content']['application/json']['schema'];
            $this->assertEquals('array', $indexSchema['properties']['data']['type']);
            $this->assertEquals(
                '#/components/schemas/TestDocUserDTO',
                $indexSchema['properties']['data']['items']['$ref']
            );
            // EXPECTATION: Paginated response should have links at top level, NOT nested pagination
            $paginatedResponse = $content['paths']['/api/test-users-paginated']['get']['responses']['200'];
            $paginatedSchema   = $paginatedResponse['content']['application/json']['schema'];
            $this->assertArrayHasKey(
                'links',
                $paginatedSchema['properties'],
                'Paginated should have links at top level'
            );
            $this->assertEquals(
                '#/components/schemas/PaginationLinks',
                $paginatedSchema['properties']['links']['$ref']
            );
            $this->assertArrayNotHasKey(
                'pagination',
                $paginatedSchema['properties']['data'] ?? [],
                'Should NOT have nested pagination'
            );
        }
        finally{
            File::delete($controllerFile);
        }
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_api_request_attribute_documents_request_fields(): void{
        $controllerContent = <<<'PHP'
<?php
namespace App\Http\Controllers;

use FallegaHQ\ApiResponder\Attributes\ApiRequest;

class TestRequestController {
    #[ApiRequest(
        fields: [
            'name' => ['type' => 'string', 'description' => 'User name', 'example' => 'John Doe'],
            'email' => ['type' => 'string', 'format' => 'email', 'description' => 'User email'],
            'age' => ['type' => 'integer', 'description' => 'User age', 'minimum' => 18]
        ],
        description: 'Create a new user'
    )]
    public function store() {
        return response()->json(['success' => true]);
    }
}
PHP;
        $controllerPath    = app_path('Http/Controllers');
        if(!File::isDirectory($controllerPath)){
            File::makeDirectory($controllerPath, 0755, true);
        }
        $controllerFile = $controllerPath . '/TestRequestController.php';
        File::put($controllerFile, $controllerContent);
        try{
            // Require the file to ensure it's loaded
            require_once $controllerFile;
            Route::post('/api/test-users', 'App\Http\Controllers\TestRequestController@store')
                 ->name('api.test-users.store');
            Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
            $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
            // EXPECTATION: Request body should have custom description from ApiRequest
            $requestBody = $content['paths']['/api/test-users']['post']['requestBody'];
            $this->assertEquals('Create a new user', $requestBody['description']);
            // EXPECTATION: Request body should reference a schema in components
            $schema = $requestBody['content']['application/json']['schema'];
            $this->assertArrayHasKey('$ref', $schema, 'Should reference a schema component');
            // Extract schema name from reference
            $schemaRef = $schema['$ref'];
            $this->assertStringStartsWith('#/components/schemas/', $schemaRef);
            $schemaName = str_replace('#/components/schemas/', '', $schemaRef);
            // Verify the schema exists in components and has the correct fields
            $this->assertArrayHasKey($schemaName, $content['components']['schemas']);
            $requestSchema = $content['components']['schemas'][$schemaName];
            $this->assertArrayHasKey('properties', $requestSchema, 'Should have field properties');
            $this->assertArrayHasKey('name', $requestSchema['properties']);
            $this->assertEquals('string', $requestSchema['properties']['name']['type']);
            $this->assertEquals('User name', $requestSchema['properties']['name']['description']);
            $this->assertArrayHasKey('email', $requestSchema['properties']);
            $this->assertEquals('email', $requestSchema['properties']['email']['format']);
            $this->assertArrayHasKey('age', $requestSchema['properties']);
            $this->assertEquals(18, $requestSchema['properties']['age']['minimum']);
        }
        finally{
            File::delete($controllerFile);
        }
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_full_integration_with_all_library_features(): void{
        // Create Model with UseDto attribute
        $modelContent = <<<'PHP'
<?php
namespace App\Models;

use FallegaHQ\ApiResponder\Attributes\UseDto;
use Illuminate\Database\Eloquent\Model;

#[UseDto(\App\Models\ProductDTO::class)]
class Product extends Model {
    protected $fillable = ['name', 'price', 'stock', 'category'];
}
PHP;
        // Create DTO with ComputedField attributes (in Models directory for scanning)
        $dtoContent = <<<'PHP'
<?php
namespace App\Models;

use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;

class ProductDTO {
    public int $id;
    public string $name;
    public float $price;
    public int $stock;
    public string $category;
    
    #[ComputedField]
    public function formattedPrice(): string {
        return '$' . number_format($this->price, 2);
    }
    
    #[ComputedField(name: 'in_stock')]
    public function isInStock(): bool {
        return $this->stock > 0;
    }
    
    #[ComputedField]
    public function discountedPrice(): float {
        return $this->price * 0.9;
    }
    
    #[ComputedField]
    public function categoryUppercase(): string {
        return 'CATEGORY';
    }
}
PHP;
        // Create Controller with ApiRequest and ApiResponse attributes
        $controllerContent = <<<'PHP'
<?php
namespace App\Http\Controllers;

use FallegaHQ\ApiResponder\Attributes\ApiRequest;
use FallegaHQ\ApiResponder\Attributes\ApiResponse;
use App\Models\Product;

class ProductController {
    #[ApiResponse(model: Product::class, type: 'paginated', description: 'Returns paginated list of products')]
    public function index() {
        return response()->json(['products' => []]);
    }
    
    #[ApiResponse(model: Product::class, type: 'single', description: 'Returns a single product with all details')]
    public function show($id) {
        return response()->json(['product' => []]);
    }
    
    #[ApiRequest(
        fields: [
            'name' => ['type' => 'string', 'description' => 'Product name', 'example' => 'Laptop'],
            'price' => ['type' => 'number', 'format' => 'float', 'description' => 'Product price', 'minimum' => 0],
            'stock' => ['type' => 'integer', 'description' => 'Available stock', 'minimum' => 0],
            'category' => ['type' => 'string', 'enum' => ['electronics', 'clothing', 'food'], 'description' => 'Product category']
        ],
        description: 'Create a new product'
    )]
    #[ApiResponse(model: Product::class, type: 'single', description: 'Returns the created product')]
    public function store() {
        return response()->json(['product' => []]);
    }
    
    #[ApiRequest(
        fields: [
            'name' => ['type' => 'string', 'description' => 'Product name'],
            'price' => ['type' => 'number', 'format' => 'float', 'description' => 'Product price'],
            'stock' => ['type' => 'integer', 'description' => 'Available stock']
        ],
        description: 'Update product details'
    )]
    #[ApiResponse(model: Product::class, type: 'single', description: 'Returns the updated product')]
    public function update($id) {
        return response()->json(['product' => []]);
    }
}
PHP;
        // Create files
        $modelsPath = app_path('Models');
        if(!File::isDirectory($modelsPath)){
            File::makeDirectory($modelsPath, 0755, true);
        }
        $modelFile = $modelsPath . '/Product.php';
        File::put($modelFile, $modelContent);
        $dtoFile = $modelsPath . '/ProductDTO.php';
        File::put($dtoFile, $dtoContent);
        $controllerPath = app_path('Http/Controllers');
        if(!File::isDirectory($controllerPath)){
            File::makeDirectory($controllerPath, 0755, true);
        }
        $controllerFile = $controllerPath . '/ProductController.php';
        File::put($controllerFile, $controllerContent);
        try{
            require_once $modelFile;
            require_once $dtoFile;
            require_once $controllerFile;
            // Register routes
            Route::get('/api/products', 'App\Http\Controllers\ProductController@index')
                 ->name('api.products.index');
            Route::get('/api/products/{id}', 'App\Http\Controllers\ProductController@show')
                 ->name('api.products.show');
            Route::post('/api/products', 'App\Http\Controllers\ProductController@store')
                 ->name('api.products.store');
            Route::put('/api/products/{id}', 'App\Http\Controllers\ProductController@update')
                 ->name('api.products.update');
            Artisan::call('api:generate-docs', ['--output' => 'api-docs.json']);
            $content = json_decode(File::get(base_path('api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
            // Verify DTO schema with computed fields
            $this->assertArrayHasKey('ProductDTO', $content['components']['schemas'], 'ProductDTO schema should exist');
            $dtoSchema = $content['components']['schemas']['ProductDTO'];
            $this->assertArrayHasKey('properties', $dtoSchema);
            // Check computed fields are documented (property names are snake_case)
            $this->assertArrayHasKey(
                'formatted_price',
                $dtoSchema['properties'],
                'Should have formatted_price computed field'
            );
            $this->assertEquals('string', $dtoSchema['properties']['formatted_price']['type']);
            $this->assertArrayHasKey(
                'in_stock',
                $dtoSchema['properties'],
                'Should have in_stock computed field with custom name'
            );
            $this->assertEquals('boolean', $dtoSchema['properties']['in_stock']['type']);
            $this->assertArrayHasKey(
                'discounted_price',
                $dtoSchema['properties'],
                'Should have discounted_price computed field'
            );
            $this->assertEquals('number', $dtoSchema['properties']['discounted_price']['type']);
            $this->assertArrayHasKey(
                'category_uppercase',
                $dtoSchema['properties'],
                'Should have category_uppercase computed field'
            );
            $this->assertEquals('string', $dtoSchema['properties']['category_uppercase']['type']);
            // Verify paginated response with ApiResponse attribute
            $indexResponse = $content['paths']['/api/products']['get']['responses']['200'];
            $indexSchema   = $indexResponse['content']['application/json']['schema'];
            $this->assertArrayHasKey('success', $indexSchema['properties']);
            $this->assertEquals('array', $indexSchema['properties']['data']['type']);
            $this->assertEquals('#/components/schemas/ProductDTO', $indexSchema['properties']['data']['items']['$ref']);
            $this->assertArrayHasKey('links', $indexSchema['properties'], 'Paginated response should have links');
            $this->assertEquals('#/components/schemas/PaginationLinks', $indexSchema['properties']['links']['$ref']);
            // Verify single response with ApiResponse attribute
            $showResponse = $content['paths']['/api/products/{id}']['get']['responses']['200'];
            $showSchema   = $showResponse['content']['application/json']['schema'];
            $this->assertEquals('#/components/schemas/ProductDTO', $showSchema['properties']['data']['$ref']);
            $showDescription = $content['paths']['/api/products/{id}']['get']['description'];
            $this->assertStringContainsString('Returns a single product with all details', $showDescription);
            // Verify request body with ApiRequest attribute on POST
            $storeRequestBody = $content['paths']['/api/products']['post']['requestBody'];
            $this->assertEquals('Create a new product', $storeRequestBody['description']);
            $storeSchemaRef = $storeRequestBody['content']['application/json']['schema'];
            $this->assertArrayHasKey('$ref', $storeSchemaRef);
            $storeSchemaName = str_replace('#/components/schemas/', '', $storeSchemaRef['$ref']);
            $this->assertArrayHasKey($storeSchemaName, $content['components']['schemas']);
            $storeSchema = $content['components']['schemas'][$storeSchemaName];
            $this->assertArrayHasKey('properties', $storeSchema);
            $this->assertArrayHasKey('name', $storeSchema['properties']);
            $this->assertEquals('Product name', $storeSchema['properties']['name']['description']);
            $this->assertArrayHasKey('price', $storeSchema['properties']);
            $this->assertEquals('float', $storeSchema['properties']['price']['format']);
            $this->assertArrayHasKey('category', $storeSchema['properties']);
            $this->assertEquals(
                [
                    'electronics',
                    'clothing',
                    'food',
                ],
                $storeSchema['properties']['category']['enum']
            );
            // Verify request body with ApiRequest attribute on PUT
            $updateRequestBody = $content['paths']['/api/products/{id}']['put']['requestBody'];
            $this->assertEquals('Update product details', $updateRequestBody['description']);
            $updateSchemaRef = $updateRequestBody['content']['application/json']['schema'];
            $this->assertArrayHasKey('$ref', $updateSchemaRef);
            $updateSchemaName = str_replace('#/components/schemas/', '', $updateSchemaRef['$ref']);
            $this->assertArrayHasKey($updateSchemaName, $content['components']['schemas']);
            $updateSchema = $content['components']['schemas'][$updateSchemaName];
            $this->assertArrayHasKey('properties', $updateSchema);
            // Verify URL parameters
            $showParams = $content['paths']['/api/products/{id}']['get']['parameters'];
            $this->assertNotEmpty($showParams);
            $idParam = collect($showParams)->firstWhere('name', 'id');
            $this->assertNotNull($idParam);
            $this->assertEquals('path', $idParam['in']);
            $this->assertTrue($idParam['required']);
        }
        finally{
            // Cleanup
            File::delete($modelFile);
            File::delete($dtoFile);
            File::delete($controllerFile);
        }
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \JsonException
     */
    public function test_command_scans_models_directory_when_exists(): void{
        // Create temporary Models directory
        $modelsPath = app_path('Models');
        if(!File::exists($modelsPath)){
            File::makeDirectory($modelsPath, 0755, true);
        }
        // Create a test model file with UseDto attribute
        $modelContent = <<<'PHP'
<?php
namespace App\Models;

use FallegaHQ\ApiResponder\Attributes\UseDto;
use Illuminate\Database\Eloquent\Model;

#[UseDto(TestDocUserDTO::class)]
class TestDocUser extends Model {
    protected $fillable = ['name', 'email'];
}
PHP;
        $dtoContent   = <<<'PHP'
<?php
namespace App\Models;

use FallegaHQ\ApiResponder\DTO\BaseDTO;
use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;

class TestDocUserDTO extends BaseDTO {
    #[ComputedField(name: 'full_name')]
    public function fullName(): string {
        return $this->source->name;
    }

    #[ComputedField(name: 'is_verified')]
    public function isVerified(): bool {
        return true;
    }

    #[ComputedField(name: 'post_count')]
    public function postCount(): int {
        return 42;
    }
}
PHP;
        File::put($modelsPath . '/TestDocUser.php', $modelContent);
        File::put($modelsPath . '/TestDocUserDTO.php', $dtoContent);
        try{
            Artisan::call('api:generate-docs', ['--output' => 'test-docs.json']);
            $content = json_decode(File::get(base_path('test-docs.json')), true, 512, JSON_THROW_ON_ERROR);
            // Verify schemas were generated
            $this->assertArrayHasKey('components', $content);
            $this->assertArrayHasKey('schemas', $content['components']);
            // Check if our test DTO was found
            if(!empty($content['components']['schemas'])){
                $hasTestDto = false;
                foreach($content['components']['schemas'] as $schemaName => $schema){
                    if(str_contains($schemaName, 'TestDocUserDTO')){
                        $hasTestDto = true;
                        // Verify computed fields are documented
                        $this->assertArrayHasKey('properties', $schema);
                        $this->assertArrayHasKey('full_name', $schema['properties']);
                        $this->assertArrayHasKey('is_verified', $schema['properties']);
                        $this->assertArrayHasKey('post_count', $schema['properties']);
                        // Verify types
                        $this->assertEquals('string', $schema['properties']['full_name']['type']);
                        $this->assertEquals('boolean', $schema['properties']['is_verified']['type']);
                        $this->assertEquals('integer', $schema['properties']['post_count']['type']);
                    }
                }
                $this->assertTrue($hasTestDto, 'TestDocUserDTO schema should be generated');
            }
        }
        finally{
            // Cleanup
            File::delete($modelsPath . '/TestDocUser.php');
            File::delete($modelsPath . '/TestDocUserDTO.php');
            if(File::exists(base_path('test-docs.json'))){
                File::delete(base_path('test-docs.json'));
            }
        }
    }

    protected function setUp(): void{
        parent::setUp();
        // Create Models directory
        $this->modelsPath = app_path('Models');
        if(!File::exists($this->modelsPath)){
            File::makeDirectory($this->modelsPath, 0755, true);
        }
        // Create test model with UseDto attribute
        $modelContent = <<<'PHP'
<?php
namespace App\Models;

use FallegaHQ\ApiResponder\Attributes\UseDto;
use Illuminate\Database\Eloquent\Model;

#[UseDto(TestDocUserDTO::class)]
class TestDocUser extends Model {
    protected $fillable = ['name', 'email'];
}
PHP;
        $dtoContent   = <<<'PHP'
<?php
namespace App\Models;

use FallegaHQ\ApiResponder\DTO\BaseDTO;
use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;

class TestDocUserDTO extends BaseDTO {
    #[ComputedField(name: 'full_name')]
    public function fullName(): string {
        return $this->source->name ?? 'Unknown';
    }

    #[ComputedField(name: 'is_verified')]
    public function isVerified(): bool {
        return true;
    }

    #[ComputedField(name: 'post_count')]
    public function postCount(): int {
        return 42;
    }
}
PHP;
        File::put($this->modelsPath . '/TestDocUser.php', $modelContent);
        File::put($this->modelsPath . '/TestDocUserDTO.php', $dtoContent);
    }

    protected function defineRoutes($router): void{
        /** @noinspection PhpUndefinedMethodInspection */
        $router->prefix('api')
               ->group(
                   function($router){
                       $router->get(
                           '/users',
                           function(){
                               return response()->json(['users' => []]);
                           }
                       )
                              ->name('api.users.index');
                       $router->get(
                           '/users/{id}',
                           function($id){
                               return response()->json(['user' => ['id' => $id]]);
                           }
                       )
                              ->name('api.users.show');
                       $router->post(
                           '/users',
                           function(){
                               return response()->json(['user' => []], 201);
                           }
                       )
                              ->name('api.users.store');
                       $router->put(
                           '/users/{id}',
                           function($id){
                               return response()->json(['user' => ['id' => $id]]);
                           }
                       )
                              ->name('api.users.update');
                       $router->delete(
                           '/users/{id}',
                           function(){
                               return response()->json(null, 204);
                           }
                       )
                              ->name('api.users.destroy');
                   }
               );
    }

    protected function getPackageProviders($app): array{
        return [
            ApiResponderServiceProvider::class,
        ];
    }

    protected function tearDown(): void{
        $files = [
            'api-docs.json',
            'custom-docs.json',
        ];
        foreach($files as $file){
            if(File::exists(base_path($file))){
                File::delete(base_path($file));
            }
        }
        parent::tearDown();
    }
}
