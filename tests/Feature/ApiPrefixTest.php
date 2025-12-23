<?php
namespace FallegaHQ\ApiResponder\Tests\Feature;

use FallegaHQ\ApiResponder\Bootstrap\ApiRequestDetector;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Http\Request;

class ApiPrefixTest extends TestCase{
    public function test_detects_default_api_prefix(): void{
        config(['api-responder.routing.prefix' => 'api']);
        $request = Request::create('/api/users');
        $this->assertTrue(ApiRequestDetector::shouldHandle($request));
    }

    public function test_detects_custom_prefix(): void{
        config(['api-responder.routing.prefix' => 'v1']);
        $request = Request::create('/v1/users');
        $this->assertTrue(ApiRequestDetector::shouldHandle($request));
    }

    public function test_handles_empty_prefix(): void{
        config(['api-responder.routing.prefix' => '']);
        config(['api-responder.routing.detect_json' => true]);
        $request = Request::create('/users');
        $request->headers->set('Accept', 'application/json');
        $this->assertTrue(ApiRequestDetector::shouldHandle($request));
    }

    public function test_does_not_detect_non_api_routes(): void{
        config(['api-responder.routing.prefix' => 'api']);
        config(['api-responder.routing.detect_json' => false]);
        $request = Request::create('/web/users');
        $this->assertFalse(ApiRequestDetector::shouldHandle($request));
    }

    public function test_detects_json_requests_regardless_of_prefix(): void{
        config(['api-responder.routing.prefix' => 'api']);
        config(['api-responder.routing.detect_json' => true]);
        $request = Request::create('/other/endpoint');
        $request->headers->set('Accept', 'application/json');
        $this->assertTrue(ApiRequestDetector::shouldHandle($request));
    }

    public function test_respects_detect_json_configuration(): void{
        config(['api-responder.routing.prefix' => 'api']);
        config(['api-responder.routing.detect_json' => false]);
        $request = Request::create('/other/endpoint');
        $request->headers->set('Accept', 'application/json');
        $this->assertFalse(ApiRequestDetector::shouldHandle($request));
    }
}
