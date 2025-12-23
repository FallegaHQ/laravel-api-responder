<?php
namespace FallegaHQ\ApiResponder\Tests\Feature;

use FallegaHQ\ApiResponder\Http\FieldsetParser;
use FallegaHQ\ApiResponder\Http\MetadataBuilder;
use FallegaHQ\ApiResponder\Http\ResponseBuilder;
use FallegaHQ\ApiResponder\Tests\AssertableApiResponse;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Testing\TestResponse;

class ResponseStructureTest extends TestCase{
    protected ResponseBuilder $builder;

    /**
     * @throws \JsonException
     */
    public function test_success_response_has_correct_structure(): void{
        $response = $this->builder->success(
            [
                'id'   => 1,
                'name' => 'Test',
            ],
            'Operation successful'
        );
        $this->assertApiResponse($response)
             ->assertSuccess('Operation successful')
             ->assertHasData()
             ->assertStatus(200);
        $data = $response->getData(true);
        $this->assertEquals(
            [
                'id'   => 1,
                'name' => 'Test',
            ],
            $data['data']
        );
    }

    protected function assertApiResponse($response): AssertableApiResponse{
        return new AssertableApiResponse(new TestResponse($response));
    }

    public function test_error_response_has_correct_structure(): void{
        $response = $this->builder->error(
            'Operation failed',
            ['field' => ['error message']]
        );
        $this->assertApiResponse($response)
             ->assertError('Operation failed')
             ->assertStatus(400);
        $data = $response->getData(true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertEquals(['field' => ['error message']], $data['errors']);
    }

    /**
     * @throws \JsonException
     */
    public function test_paginated_response_has_pagination_metadata(): void{
        $items = collect(
            [
                [
                    'id'   => 1,
                    'name' => 'Item 1',
                ],
                [
                    'id'   => 2,
                    'name' => 'Item 2',
                ],
            ]
        );
        $paginator = new LengthAwarePaginator(
            $items, 73, 15, 1, ['path' => 'http://example.com']
        );
        $response = $this->builder->success($paginator);
        $this->assertApiResponse($response)
             ->assertSuccess()
             ->assertHasData()
             ->assertHasPagination();
        $data = $response->getData(true);
        $this->assertEquals(1, $data['meta']['current_page']);
        $this->assertEquals(73, $data['meta']['total']);
        $this->assertEquals(15, $data['meta']['per_page']);
    }

    /**
     * @throws \JsonException
     */
    public function test_response_includes_custom_headers(): void{
        $response = $this->builder->withHeaders(['X-Custom-Header' => 'custom-value'])
                                  ->success(['test' => 'data']);
        $this->assertApiResponse($response)
             ->assertSuccess()
             ->assertHasData();
        $this->assertEquals('custom-value', $response->headers->get('X-Custom-Header'));
    }

    /**
     * @throws \JsonException
     */
    public function test_created_response_has_201_status(): void{
        $response = $this->builder->created(['id' => 1], 'Resource created');
        $this->assertApiResponse($response)
             ->assertSuccess('Resource created')
             ->assertHasData()
             ->assertStatus(201);
    }

    public function test_no_content_response(): void{
        $response = $this->builder->noContent();
        $this->assertApiResponse($response)
             ->assertStatus(204);
    }

    protected function setUp(): void{
        parent::setUp();
        $metadataBuilder = new MetadataBuilder();
        $fieldsetParser  = new FieldsetParser();
        $this->builder   = new ResponseBuilder($metadataBuilder, $fieldsetParser);
    }
}
