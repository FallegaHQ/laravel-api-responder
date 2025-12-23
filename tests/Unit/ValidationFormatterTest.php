<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Tests\TestCase;
use FallegaHQ\ApiResponder\Validation\ValidationFormatter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\MessageBag;
use Mockery;

class ValidationFormatterTest extends TestCase{
    protected ValidationFormatter $formatter;

    public function test_formats_validation_errors(): void{
        $messageBag = new MessageBag(
            [
                'email'    => ['The email field is required.'],
                'password' => ['The password must be at least 8 characters.'],
            ]
        );
        $validator  = Mockery::mock(Validator::class);
        $validator->allows('errors')
                  ->andReturns($messageBag);
        $result = $this->formatter->format($validator);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('password', $result);
        $this->assertEquals(['The email field is required.'], $result['email']);
        $this->assertEquals(['The password must be at least 8 characters.'], $result['password']);
    }

    public function test_formats_multiple_errors_per_field(): void{
        $messageBag = new MessageBag(
            [
                'password' => [
                    'The password must be at least 8 characters.',
                    'The password must contain at least one uppercase letter.',
                ],
            ]
        );
        $validator  = Mockery::mock(Validator::class);
        $validator->allows('errors')
                  ->andReturns($messageBag);
        $result = $this->formatter->format($validator);
        $this->assertCount(2, $result['password']);
    }

    public function test_returns_empty_array_for_no_errors(): void{
        $messageBag = new MessageBag([]);
        $validator  = Mockery::mock(Validator::class);
        $validator->allows('errors')
                  ->andReturns($messageBag);
        $result = $this->formatter->format($validator);
        $this->assertEmpty($result);
    }

    protected function setUp(): void{
        parent::setUp();
        $this->formatter = new ValidationFormatter();
    }

    protected function tearDown(): void{
        Mockery::close();
        parent::tearDown();
    }
}
