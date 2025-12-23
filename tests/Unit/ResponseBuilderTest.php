<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Http\FieldsetParser;
use FallegaHQ\ApiResponder\Http\MetadataBuilder;
use FallegaHQ\ApiResponder\Http\ResponseBuilder;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Mockery;

class ResponseBuilderTest extends TestCase{
    protected ResponseBuilder                                                   $builder;
    protected MetadataBuilder|Mockery\MockInterface|Mockery\LegacyMockInterface $metadataBuilder;
    protected FieldsetParser|Mockery\MockInterface|Mockery\LegacyMockInterface  $fieldsetParser;

    /**
     * @throws \JsonException
     */
    public function test_success_response_structure(): void{
        $this->metadataBuilder->allows('addExecutionTime')
                              ->andReturnSelf();
        $this->metadataBuilder->allows('merge')
                              ->andReturnSelf();
        $this->metadataBuilder->allows('build')
                              ->andReturns([]);
        $this->fieldsetParser->allows('parse')
                             ->andReturns(
                                 [
                                     'fields'   => null,
                                     'includes' => [],
                                     'excludes' => [],
                                 ]
                             );
        $response = $this->builder->success(['id' => 1], 'Success message');
        $this->assertEquals(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Success message', $data['message']);
        $this->assertEquals(['id' => 1], $data['data']);
    }

    public function test_error_response_structure(): void{
        $this->metadataBuilder->allows('addExecutionTime')
                              ->andReturnSelf();
        $this->metadataBuilder->allows('build')
                              ->andReturns([]);
        $response = $this->builder->error('Error message', ['field' => ['error']]);
        $this->assertEquals(400, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Error message', $data['message']);
        $this->assertEquals(['field' => ['error']], $data['errors']);
    }

    /**
     * @throws \JsonException
     */
    public function test_created_response(): void{
        $this->metadataBuilder->allows('addExecutionTime')
                              ->andReturnSelf();
        $this->metadataBuilder->allows('merge')
                              ->andReturnSelf();
        $this->metadataBuilder->allows('build')
                              ->andReturns([]);
        $this->fieldsetParser->allows('parse')
                             ->andReturns(
                                 [
                                     'fields'   => null,
                                     'includes' => [],
                                     'excludes' => [],
                                 ]
                             );
        $response = $this->builder->created(['id' => 1], 'Created');
        $this->assertEquals(201, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
    }

    public function test_no_content_response(): void{
        $response = $this->builder->noContent();
        $this->assertEquals(204, $response->getStatusCode());
    }

    /**
     * @throws \JsonException
     */
    public function test_with_meta_adds_metadata(): void{
        $this->metadataBuilder->allows('addExecutionTime')
                              ->andReturnSelf();
        $this->metadataBuilder->allows('merge')
                              ->with(['custom' => 'value'])
                              ->andReturnSelf();
        $this->metadataBuilder->allows('build')
                              ->andReturns(['custom' => 'value']);
        $this->fieldsetParser->allows('parse')
                             ->andReturns(
                                 [
                                     'fields'   => null,
                                     'includes' => [],
                                     'excludes' => [],
                                 ]
                             );
        $response = $this->builder->withMeta(['custom' => 'value'])
                                  ->success();
        $data     = $response->getData(true);
        $this->assertArrayHasKey('meta', $data);
        $this->assertEquals('value', $data['meta']['custom']);
    }

    /**
     * @throws \JsonException
     */
    public function test_with_headers_adds_headers(): void{
        $this->metadataBuilder->allows('addExecutionTime')
                              ->andReturnSelf();
        $this->metadataBuilder->allows('merge')
                              ->andReturnSelf();
        $this->metadataBuilder->allows('build')
                              ->andReturns([]);
        $this->fieldsetParser->allows('parse')
                             ->andReturns(
                                 [
                                     'fields'   => null,
                                     'includes' => [],
                                     'excludes' => [],
                                 ]
                             );
        $response = $this->builder->withHeaders(['X-Custom' => 'Header'])
                                  ->success();
        $this->assertEquals('Header', $response->headers->get('X-Custom'));
    }

    protected function setUp(): void{
        parent::setUp();
        $this->metadataBuilder = Mockery::mock(MetadataBuilder::class);
        $this->fieldsetParser  = Mockery::mock(FieldsetParser::class);
        $this->builder         = new ResponseBuilder(
            $this->metadataBuilder, $this->fieldsetParser
        );
    }

    protected function tearDown(): void{
        Mockery::close();
        parent::tearDown();
    }
}
