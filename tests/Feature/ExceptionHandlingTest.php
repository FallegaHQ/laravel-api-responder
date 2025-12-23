<?php
namespace FallegaHQ\ApiResponder\Tests\Feature;

use FallegaHQ\ApiResponder\Bootstrap\ApiExceptionHandler;
use FallegaHQ\ApiResponder\Contracts\ResponseBuilderInterface;
use FallegaHQ\ApiResponder\Http\FieldsetParser;
use FallegaHQ\ApiResponder\Http\MetadataBuilder;
use FallegaHQ\ApiResponder\Http\ResponseBuilder;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ExceptionHandlingTest extends TestCase{
    /**
     * @throws \ReflectionException
     */
    public function test_handles_authentication_exception(): void{
        config(['api-responder.exception_messages.authentication' => 'Unauthenticated']);
        $exception = new AuthenticationException();
        $response  = $this->callHandleException($exception);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Unauthenticated', $data['message']);
    }

    /**
     * @throws \ReflectionException
     */
    protected function callHandleException(Throwable $exception){
        // Manually create dependencies since container binding is failing
        $metadataBuilder = new MetadataBuilder();
        $fieldsetParser  = new FieldsetParser();
        $responseBuilder = new ResponseBuilder($metadataBuilder, $fieldsetParser);
        // Temporarily bind to container
        $this->app->instance(ResponseBuilderInterface::class, $responseBuilder);
        // Use reflection to call the protected static method
        $reflection = new ReflectionClass(ApiExceptionHandler::class);

        return $reflection->getMethod('handleException')
                          ->invoke(null, $exception);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_handles_authorization_exception(): void{
        config(['api-responder.exception_messages.authorization' => 'Forbidden']);
        /** @noinspection PhpMultipleClassDeclarationsInspection */
        $exception = new AuthorizationException('You cannot do this');
        $response  = $this->callHandleException($exception);
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('You cannot do this', $data['message']);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_handles_validation_exception(): void{
        config(['api-responder.exception_messages.validation' => 'Validation failed']);
        $validator = Validator::make(
            ['email' => ''],
            ['email' => 'required|email']
        );
        /** @noinspection PhpMultipleClassDeclarationsInspection */
        $exception = new ValidationException($validator);
        $response  = $this->callHandleException($exception);
        $this->assertEquals(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Validation failed', $data['message']);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('email', $data['errors']);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_handles_not_found_exception(): void{
        config(['api-responder.exception_messages.not_found' => 'Resource not found']);
        $exception = new NotFoundHttpException();
        $response  = $this->callHandleException($exception);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Resource not found', $data['message']);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_handles_model_not_found_exception(): void{
        config(['api-responder.exception_messages.not_found' => 'Resource not found']);
        $exception = new ModelNotFoundException();
        $response  = $this->callHandleException($exception);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Resource not found', $data['message']);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_handles_bad_request_exception(): void{
        config(['api-responder.exception_messages.bad_request' => 'Bad request']);
        $exception = new BadRequestHttpException();
        $response  = $this->callHandleException($exception);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Bad request', $data['message']);
    }
}
