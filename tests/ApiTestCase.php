<?php
namespace FallegaHQ\ApiResponder\Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class ApiTestCase extends BaseTestCase{
    protected function assertApiResponse($response): AssertableApiResponse{
        return new AssertableApiResponse($response);
    }
}
