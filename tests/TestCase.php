<?php
namespace FallegaHQ\ApiResponder\Tests;

use FallegaHQ\ApiResponder\ApiResponderServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase{
    protected function getPackageProviders($app): array{
        return [
            ApiResponderServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void{
        // Setup default configuration
        $app['config']->set('api-responder', require __DIR__ . '/../config/api-responder.php');
    }

    protected function setUp(): void{
        parent::setUp();
        // Ensure service provider is registered
        $this->app->register(ApiResponderServiceProvider::class);
    }
}
