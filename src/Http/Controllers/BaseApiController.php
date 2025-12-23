<?php
namespace FallegaHQ\ApiResponder\Http\Controllers;

use FallegaHQ\ApiResponder\Contracts\ResponseBuilderInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class BaseApiController extends Controller{
    use AuthorizesRequests, ValidatesRequests;

    protected ResponseBuilderInterface $responseBuilder;

    public function __construct(ResponseBuilderInterface $responseBuilder){
        $this->responseBuilder = $responseBuilder;
    }

    protected function created($data = null, ?string $message = null): JsonResponse{
        return $this->responseBuilder->created($data, $message);
    }

    protected function noContent(): JsonResponse{
        return $this->responseBuilder->noContent();
    }

    protected function unauthorized(?string $message = null): JsonResponse{
        return $this->error(
            $message ?? 'Unauthorized',
            null,
            config('api-responder.status_codes.unauthorized')
        );
    }

    protected function error(?string $message = null, $errors = null, int $status = 400): JsonResponse{
        return $this->responseBuilder->error($message, $errors, $status);
    }

    protected function forbidden(?string $message = null): JsonResponse{
        return $this->error(
            $message ?? 'Forbidden',
            null,
            config('api-responder.status_codes.forbidden')
        );
    }

    protected function notFound(?string $message = null): JsonResponse{
        return $this->error(
            $message ?? 'Resource not found',
            null,
            config('api-responder.status_codes.not_found')
        );
    }

    protected function validationError(?string $message = null, $errors = null): JsonResponse{
        return $this->error(
            $message ?? 'Validation failed',
            $errors,
            config('api-responder.status_codes.unprocessable')
        );
    }

    protected function serverError(?string $message = null): JsonResponse{
        return $this->error(
            $message ?? 'Internal server error',
            null,
            config('api-responder.status_codes.server_error')
        );
    }

    protected function accepted($data = null, ?string $message = null): JsonResponse{
        return $this->success(
            $data,
            $message ?? 'Request accepted for processing',
            config('api-responder.status_codes.accepted')
        );
    }

    protected function success($data = null, ?string $message = null, int $status = 200): JsonResponse{
        return $this->responseBuilder->success($data, $message, $status);
    }

    protected function withMeta(array $meta): BaseApiController{
        $this->responseBuilder->withMeta($meta);

        return $this;
    }

    protected function withHeaders(array $headers): BaseApiController{
        $this->responseBuilder->withHeaders($headers);

        return $this;
    }
}
