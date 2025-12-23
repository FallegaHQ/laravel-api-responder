<?php
namespace FallegaHQ\ApiResponder\Tests;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

class AssertableApiResponse{
    protected TestResponse $response;

    public function __construct(TestResponse $response){
        $this->response = $response;
    }

    public function assertSuccess(?string $message = null): self{
        $this->response->assertJson(['success' => true]);
        if($message){
            $this->response->assertJson(['message' => $message]);
        }

        return $this;
    }

    public function assertError(?string $message = null): self{
        $this->response->assertJson(['success' => false]);
        if($message){
            $this->response->assertJson(['message' => $message]);
        }

        return $this;
    }

    public function assertHasData(): self{
        $this->response->assertJsonStructure(['data']);

        return $this;
    }

    public function assertHasMeta(array $keys = []): self{
        if(empty($keys)){
            $this->response->assertJsonStructure(['meta']);
        }
        else{
            $this->response->assertJsonStructure(['meta' => $keys]);
        }

        return $this;
    }

    public function assertHasPagination(): self{
        $this->response->assertJsonStructure(
            [
                'data',
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
                'links',
            ]
        );

        return $this;
    }

    public function assertFieldVisible(string $field): self{
        $data = $this->response->json('data');
        if(is_array($data) && isset($data[0])){
            // Collection
            Assert::assertArrayHasKey($field, $data[0]);
        }
        else{
            // Single item
            Assert::assertArrayHasKey($field, $data);
        }

        return $this;
    }

    public function assertFieldHidden(string $field): self{
        $data = $this->response->json('data');
        if(is_array($data) && isset($data[0])){
            Assert::assertArrayNotHasKey($field, $data[0]);
        }
        else{
            Assert::assertArrayNotHasKey($field, $data);
        }

        return $this;
    }

    public function assertStatus(int $status): self{
        $this->response->assertStatus($status);

        return $this;
    }
}
