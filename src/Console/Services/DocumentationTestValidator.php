<?php
namespace FallegaHQ\ApiResponder\Console\Services;

use Illuminate\Support\Facades\File;

class DocumentationTestValidator{
    protected array $testFiles = [];
    protected array $warnings  = [];

    public function __construct(){
        $this->scanTestFiles();
    }

    protected function scanTestFiles(): void{
        $testPaths = [
            base_path('tests/Feature'),
            base_path('tests/Unit'),
        ];
        foreach($testPaths as $path){
            if(!is_dir($path)){
                continue;
            }
            $files = File::allFiles($path);
            foreach($files as $file){
                if($file->getExtension() === 'php'){
                    $this->testFiles[] = [
                        'path'    => $file->getPathname(),
                        'content' => file_get_contents($file->getPathname()),
                    ];
                }
            }
        }
    }

    public function validateRoute(array $route): array{
        $validation = [
            'has_test'        => false,
            'missing_tests'   => [],
            'test_files'      => [],
            'recommendations' => [],
        ];
        $uri        = $route['uri'];
        $method     = strtolower($route['method']);
        // Check if route has tests
        foreach($this->testFiles as $testFile){
            $content = $testFile['content'];
            // Look for route URI or route name in test files
            if(str_contains($content, $uri) || ($route['name'] && str_contains($content, $route['name']))){
                $validation['has_test']     = true;
                $validation['test_files'][] = basename($testFile['path']);
            }
        }
        // Calculate coverage score
        if($validation['has_test']){
            $validation['test_coverage'] = 100;
        }
        else{
            $validation['test_coverage']     = 0;
            $validation['missing_tests'][]   = "No tests found for $method $uri";
            $validation['recommendations'][] = $this->generateTestRecommendation($route);
        }

        return $validation;
    }

    protected function generateTestRecommendation(array $route): string{
        $method     = strtolower($route['method']);
        $uri        = $route['uri'];
        $testMethod = match ($method) {
            'get'    => 'getJson',
            'post'   => 'postJson',
            'put'    => 'putJson',
            'patch'  => 'patchJson',
            'delete' => 'deleteJson',
            default  => 'json',
        };

        return "Add test: \$this->$testMethod('/$uri')";
    }

    public function getWarnings(): array{
        return $this->warnings;
    }

    public function generateTestSuggestion(array $route): string{
        $method         = strtolower($route['method']);
        $uri            = $route['uri'];
        $routeName      = $route['name'] ?? str_replace('/', '_', $uri);
        $testMethod     = 'test_' . str_replace(
                [
                    '.',
                    '-',
                ],
                '_',
                $routeName
            );
        $httpMethod     = match ($method) {
            'get'    => 'getJson',
            'post'   => 'postJson',
            'put'    => 'putJson',
            'patch'  => 'patchJson',
            'delete' => 'deleteJson',
            default  => 'json',
        };
        $expectedStatus = match ($method) {
            'post'   => 201,
            'delete' => 204,
            default  => 200,
        };

        return <<<PHP
public function $testMethod()
{
    \$response = \$this->$httpMethod('/$uri');
    
    \$response->assertStatus($expectedStatus);
}
PHP;
    }
}
