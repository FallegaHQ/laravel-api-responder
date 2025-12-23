<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Bootstrap\ApiRequestDetector;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Http\Request;
use Mockery;

class ApiRequestDetectorTest extends TestCase{
    public function test_detects_api_request_with_default_prefix(): void{
        config(['api-responder.routing.prefix' => 'api']);
        config(['api-responder.routing.detect_json' => true]);
        $request = Mockery::mock(Request::class);
        $request->allows('is')
                ->with('api/*')
                ->andReturns(true);
        $request->allows('expectsJson')
                ->andReturns(false);
        $this->assertTrue(ApiRequestDetector::shouldHandle($request));
    }

    public function test_detects_api_request_with_empty_prefix(): void{
        config(['api-responder.routing.prefix' => '']);
        config(['api-responder.routing.detect_json' => true]);
        $request = Mockery::mock(Request::class);
        $request->allows('expectsJson')
                ->andReturns(true);
        $this->assertTrue(ApiRequestDetector::shouldHandle($request));
    }

    public function test_detects_json_request_regardless_of_prefix(): void{
        config(['api-responder.routing.prefix' => 'api']);
        config(['api-responder.routing.detect_json' => true]);
        $request = Mockery::mock(Request::class);
        $request->allows('is')
                ->with('api/*')
                ->andReturns(false);
        $request->allows('expectsJson')
                ->andReturns(true);
        $this->assertTrue(ApiRequestDetector::shouldHandle($request));
    }

    public function test_does_not_detect_non_api_request(): void{
        config(['api-responder.routing.prefix' => 'api']);
        config(['api-responder.routing.detect_json' => true]);
        $request = Mockery::mock(Request::class);
        $request->allows('is')
                ->with('api/*')
                ->andReturns(false);
        $request->allows('expectsJson')
                ->andReturns(false);
        $this->assertFalse(ApiRequestDetector::shouldHandle($request));
    }

    public function test_detects_custom_prefix(): void{
        config(['api-responder.routing.prefix' => 'v1']);
        config(['api-responder.routing.detect_json' => true]);
        $request = Mockery::mock(Request::class);
        $request->allows('is')
                ->with('v1/*')
                ->andReturns(true);
        $request->allows('expectsJson')
                ->andReturns(false);
        $this->assertTrue(ApiRequestDetector::shouldHandle($request));
    }

    public function test_get_prefix_returns_configured_prefix(): void{
        config(['api-responder.routing.prefix' => 'custom']);
        $this->assertEquals('custom', ApiRequestDetector::getPrefix());
    }

    protected function tearDown(): void{
        Mockery::close();
        parent::tearDown();
    }
}
