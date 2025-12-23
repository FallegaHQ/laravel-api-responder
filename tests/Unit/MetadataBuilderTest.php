<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Http\MetadataBuilder;
use FallegaHQ\ApiResponder\Tests\TestCase;

class MetadataBuilderTest extends TestCase{
    protected MetadataBuilder $builder;

    public function test_builds_metadata_when_enabled(): void{
        $metadata = $this->builder->build();
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('timestamp', $metadata);
        $this->assertArrayHasKey('request_id', $metadata);
        $this->assertArrayHasKey('api_version', $metadata);
    }

    public function test_returns_empty_when_disabled(): void{
        config(['api-responder.metadata.enabled' => false]);
        $builder  = new MetadataBuilder();
        $metadata = $builder->build();
        $this->assertEmpty($metadata);
    }

    public function test_add_timestamps(): void{
        $this->builder->addTimestamps();
        $metadata = $this->builder->build();
        $this->assertArrayHasKey('timestamp', $metadata);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $metadata['timestamp']);
    }

    public function test_add_request_id(): void{
        $this->builder->addRequestId();
        $metadata = $this->builder->build();
        $this->assertArrayHasKey('request_id', $metadata);
        $this->assertNotEmpty($metadata['request_id']);
    }

    public function test_add_version(): void{
        $this->builder->addVersion();
        $metadata = $this->builder->build();
        $this->assertArrayHasKey('api_version', $metadata);
        $this->assertEquals('v1', $metadata['api_version']);
    }

    public function test_add_execution_time(): void{
        $startTime = microtime(true);
        usleep(10000); // Sleep 10ms
        $this->builder->addExecutionTime($startTime);
        $metadata = $this->builder->build();
        $this->assertArrayHasKey('execution_time', $metadata);
        $this->assertStringContainsString('ms', $metadata['execution_time']);
    }

    public function test_add_custom_metadata(): void{
        $this->builder->add('custom_key', 'custom_value');
        $metadata = $this->builder->build();
        $this->assertArrayHasKey('custom_key', $metadata);
        $this->assertEquals('custom_value', $metadata['custom_key']);
    }

    public function test_merge_metadata(): void{
        $this->builder->merge(
            [
                'key1' => 'value1',
                'key2' => 'value2',
            ]
        );
        $metadata = $this->builder->build();
        $this->assertArrayHasKey('key1', $metadata);
        $this->assertArrayHasKey('key2', $metadata);
        $this->assertEquals('value1', $metadata['key1']);
        $this->assertEquals('value2', $metadata['key2']);
    }

    protected function setUp(): void{
        parent::setUp();
        config(
            [
                'api-responder.metadata' => [
                    'enabled'                => true,
                    'include_timestamps'     => true,
                    'include_request_id'     => true,
                    'include_version'        => true,
                    'include_execution_time' => true,
                    'api_version'            => 'v1',
                ],
            ]
        );
        $this->builder = new MetadataBuilder();
    }
}
