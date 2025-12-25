<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Console\Services\DocumentationTestValidator;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Support\Facades\File;

class DocumentationTestValidatorTest extends TestCase{
    protected DocumentationTestValidator $validator;
    protected string                     $tempTestDir;

    public function test_detects_existing_tests_by_route_uri(): void{
        $this->createTempTestFile(
            'Feature/UserControllerTest.php',
            "
            public function test_index_returns_users() {
                \$response = \$this->getJson('/api/users');
                \$response->assertStatus(200);
            }
        "
        );
        // Re-instantiate validator to pick up new files
        $this->validator = new DocumentationTestValidator();
        $route = [
            'uri'    => 'api/users',
            'method' => 'GET',
            'name'   => 'users.index',
        ];
        $validation = $this->validator->validateRoute($route);
        $this->assertTrue($validation['has_test']);
        $this->assertEquals(100, $validation['test_coverage']);
        $this->assertEmpty($validation['missing_tests']);
        $this->assertNotEmpty($validation['test_files']);
    }

    protected function createTempTestFile(string $filename, string $content): void{
        $path = $this->tempTestDir . '/' . dirname($filename);
        if(!is_dir($path)){
            File::makeDirectory($path, 0755, true);
        }
        File::put($this->tempTestDir . '/' . $filename, "<?php\n" . $content);
    }

    public function test_detects_existing_tests_by_route_name(): void{
        $this->createTempTestFile(
            'Feature/PostControllerTest.php',
            "
            public function test_can_create_post() {
                \$response = \$this->postJson(route('posts.store'), []);
            }
        "
        );
        // Re-instantiate validator to pick up new files
        $this->validator = new DocumentationTestValidator();
        $route = [
            'uri'    => 'api/posts',
            'method' => 'POST',
            'name'   => 'posts.store',
        ];
        $validation = $this->validator->validateRoute($route);
        $this->assertTrue($validation['has_test']);
        $this->assertEquals(100, $validation['test_coverage']);
    }

    public function test_identifies_routes_without_tests(): void{
        $route = [
            'uri'    => 'api/completely-untested-endpoint',
            'method' => 'GET',
            'name'   => 'untested.endpoint',
        ];
        $validation = $this->validator->validateRoute($route);
        $this->assertFalse($validation['has_test']);
        $this->assertEquals(0, $validation['test_coverage']);
        $this->assertNotEmpty($validation['missing_tests']);
        $this->assertStringContainsString('No tests found', $validation['missing_tests'][0]);
    }

    public function test_generates_correct_test_method_for_get_request(): void{
        $route = [
            'uri'    => 'api/products',
            'method' => 'GET',
            'name'   => 'products.index',
        ];
        $suggestion = $this->validator->generateTestSuggestion($route);
        $this->assertStringContainsString('public function test_products_index', $suggestion);
        $this->assertStringContainsString('getJson', $suggestion);
        $this->assertStringContainsString('/api/products', $suggestion);
        $this->assertStringContainsString('assertStatus(200)', $suggestion);
    }

    public function test_generates_correct_test_method_for_post_request(): void{
        $route = [
            'uri'    => 'api/orders',
            'method' => 'POST',
            'name'   => 'orders.store',
        ];
        $suggestion = $this->validator->generateTestSuggestion($route);
        $this->assertStringContainsString('postJson', $suggestion);
        $this->assertStringContainsString('assertStatus(201)', $suggestion);
    }

    public function test_generates_correct_test_method_for_delete_request(): void{
        $route = [
            'uri'    => 'api/comments/1',
            'method' => 'DELETE',
            'name'   => 'comments.destroy',
        ];
        $suggestion = $this->validator->generateTestSuggestion($route);
        $this->assertStringContainsString('deleteJson', $suggestion);
        $this->assertStringContainsString('assertStatus(204)', $suggestion);
    }

    public function test_scans_both_feature_and_unit_test_directories(): void{
        $this->createTempTestFile(
            'Feature/ApiTest.php',
            "
            public function test_api_endpoint() {
                \$this->getJson('/api/feature-test');
            }
        "
        );
        $this->createTempTestFile(
            'Unit/ServiceTest.php',
            "
            public function test_service_method() {
                \$this->getJson('/api/unit-test');
            }
        "
        );
        // Re-instantiate validator to pick up new files
        $this->validator = new DocumentationTestValidator();
        $featureRoute = [
            'uri'    => 'api/feature-test',
            'method' => 'GET',
            'name'   => null,
        ];
        $unitRoute = [
            'uri'    => 'api/unit-test',
            'method' => 'GET',
            'name'   => null,
        ];
        $featureValidation = $this->validator->validateRoute($featureRoute);
        $unitValidation    = $this->validator->validateRoute($unitRoute);
        $this->assertTrue($featureValidation['has_test']);
        $this->assertTrue($unitValidation['has_test']);
    }

    public function test_provides_actionable_recommendations_for_missing_tests(): void{
        $route = [
            'uri'    => 'api/analytics/reports',
            'method' => 'GET',
            'name'   => 'analytics.reports',
        ];
        $validation = $this->validator->validateRoute($route);
        $this->assertNotEmpty($validation['recommendations']);
        $recommendation = $validation['recommendations'][0];
        $this->assertStringContainsString('getJson', $recommendation);
        $this->assertStringContainsString('/api/analytics/reports', $recommendation);
    }

    protected function setUp(): void{
        parent::setUp();
        $this->tempTestDir = base_path('tests');
        if(!is_dir($this->tempTestDir . '/Feature')){
            File::makeDirectory($this->tempTestDir . '/Feature', 0755, true);
        }
        if(!is_dir($this->tempTestDir . '/Unit')){
            File::makeDirectory($this->tempTestDir . '/Unit', 0755, true);
        }
        $this->validator = new DocumentationTestValidator();
    }

    protected function tearDown(): void{
        if(isset($this->tempTestDir)){
            $testFiles = File::glob($this->tempTestDir . '/Feature/*Test.php');
            foreach($testFiles as $file){
                if(basename($file) !== 'ExceptionHandlingTest.php' && basename(
                        $file
                    ) !== 'ModelResponseTest.php' && basename($file) !== 'ResponseStructureTest.php' && basename(
                        $file
                    ) !== 'ApiPrefixTest.php'){
                    File::delete($file);
                }
            }
        }
        parent::tearDown();
    }
}
