<?php
namespace FallegaHQ\ApiResponder\Exceptions;

use FallegaHQ\ApiResponder\Contracts\ResponseBuilderInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ApiExceptionHandler extends ExceptionHandler{
    public function render($request, Throwable $e){
        if($this->shouldHandleAsApi($request)){
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    protected function shouldHandleAsApi($request): bool{
        $prefix     = config('api-responder.routing.prefix');
        $detectJson = config('api-responder.routing.detect_json', true);
        // If prefix is empty string, check all routes
        if($prefix === '' || $prefix === null){
            return $detectJson ? $request->expectsJson() : true;
        }
        // Check if request matches the API prefix pattern
        $pattern = trim($prefix, '/') . '/*';

        return $request->is($pattern) || ($detectJson && $request->expectsJson());
    }

    protected function handleApiException($request, Throwable $e){
        $builder  = app(ResponseBuilderInterface::class);
        $messages = config('api-responder.exception_messages');
        if($e instanceof ValidationException){
            return $builder->error(
                $messages['validation'] ?? 'Validation failed',
                $e->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if($e instanceof AuthenticationException){
            return $builder->error(
                $messages['authentication'] ?? 'Unauthenticated',
                null,
                Response::HTTP_UNAUTHORIZED
            );
        }
        if($e instanceof AuthorizationException){
            return $builder->error(
                $e->getMessage() ?: ($messages['authorization'] ?? 'Forbidden'),
                null,
                Response::HTTP_FORBIDDEN
            );
        }
        if($e instanceof ModelNotFoundException){
            return $builder->error(
                $messages['not_found'] ?? 'Resource not found',
                null,
                Response::HTTP_NOT_FOUND
            );
        }
        if($e instanceof HttpException){
            return $builder->error(
                $e->getMessage(),
                null,
                $e->getStatusCode()
            );
        }
        // Generic error
        $message = config('app.debug') ? $e->getMessage() : ($messages['server_error'] ?? 'Server error');
        $errors  = null;
        if(config('api-responder.error_handling.include_trace') && config('app.debug')){
            $errors = [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => collect($e->getTrace())
                    ->take(config('api-responder.error_handling.trace_limit', 5))
                    ->toArray(),
            ];
        }

        return $builder->error($message, $errors, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
