<?php
namespace FallegaHQ\ApiResponder\Bootstrap;

use FallegaHQ\ApiResponder\Contracts\ResponseBuilderInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

class ApiExceptionHandler{
    /**
     * Configure additional exception settings.
     */
    public static function configure(Exceptions $exceptions, array $options = []): void{
        // Don't flash sensitive fields
        $dontFlash = array_merge(
            [
                'current_password',
                'password',
                'password_confirmation',
            ],
            $options['dontFlash'] ?? []
        );
        $exceptions->dontFlash($dontFlash);
        // Don't report duplicates
        if($options['dontReportDuplicates'] ?? true){
            $exceptions->dontReportDuplicates();
        }
        // Register the render callback
        self::register($exceptions);
    }

    /**
     * Register API exception handlers for Laravel 12 bootstrap configuration.
     *
     * Usage in bootstrap/app.php:
     *
     * use FallegaHQ\ApiResponder\Bootstrap\ApiExceptionHandler;
     *
     * ->withExceptions(function (Exceptions $exceptions) {
     *     ApiExceptionHandler::register($exceptions);
     * })
     *
     * @noinspection PhpInconsistentReturnPointsInspection
     */
    public static function register(Exceptions $exceptions): void{
        $exceptions->render(
            function(Throwable $e, Request $request){
                if(self::shouldHandleAsApi($request)){
                    return self::handleException($e);
                }
            }
        );
    }

    /**
     * Determine if the request should be handled as an API request.
     */
    protected static function shouldHandleAsApi($request): bool{
        return ApiRequestDetector::shouldHandle($request);
    }

    /**
     * Handle the exception and return appropriate JSON response.
     */
    public static function handleException(Throwable $e){
        $builder  = app(ResponseBuilderInterface::class);
        $messages = config('api-responder.exception_messages');
        // Authentication Exception
        if($e instanceof AuthenticationException){
            return $builder->error(
                $messages['authentication'] ?? 'Unauthenticated',
                null,
                Response::HTTP_UNAUTHORIZED
            );
        }
        // Authorization Exceptions
        if($e instanceof AccessDeniedHttpException || $e instanceof AuthorizationException){
            return $builder->error(
                $e->getMessage() ?: ($messages['authorization'] ?? 'Forbidden'),
                null,
                Response::HTTP_FORBIDDEN
            );
        }
        // Validation Exception
        if($e instanceof ValidationException){
            return $builder->error(
                $messages['validation'] ?? 'Validation failed',
                $e->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        // Not Found Exceptions
        if($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException){
            return $builder->error(
                $messages['not_found'] ?? 'Resource not found',
                null,
                Response::HTTP_NOT_FOUND
            );
        }
        // Unprocessable Entity
        if($e instanceof UnprocessableEntityHttpException){
            return $builder->error(
                $messages['unprocessable'] ?? 'Unprocessable entity',
                null,
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        // Bad Request
        if($e instanceof BadRequestHttpException){
            return $builder->error(
                $messages['bad_request'] ?? 'Bad request',
                null,
                Response::HTTP_BAD_REQUEST
            );
        }
        // Method Not Allowed
        if($e instanceof MethodNotAllowedHttpException){
            return $builder->error(
                $messages['method_not_allowed'] ?? 'Method not allowed',
                null,
                Response::HTTP_METHOD_NOT_ALLOWED
            );
        }
        // Generic HTTP Exception
        if($e instanceof HttpException){
            return $builder->error(
                $e->getMessage(),
                null,
                $e->getStatusCode()
            );
        }
        // Generic Server Error
        $message = config('app.debug') ? $e->getMessage() : ($messages['server_error'] ?? 'Internal server error');
        $errors  = null;
        if(config('api-responder.error_handling.include_trace') && config('app.debug')){
            $errors = [
                'instanceof' => get_class($e),
                'message'    => $e->getMessage(),
                'at'         => $e->getFile() . ':' . $e->getLine(),
                'trace'      => collect($e->getTrace())
                    ->take(config('api-responder.error_handling.trace_limit', 5))
                    ->toArray(),
            ];
        }

        return $builder->error($message, $errors, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
