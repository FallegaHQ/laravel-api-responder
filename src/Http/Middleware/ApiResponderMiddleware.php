<?php
namespace FallegaHQ\ApiResponder\Http\Middleware;

use Closure;
use FallegaHQ\ApiResponder\Contracts\ResponseBuilderInterface;
use Illuminate\Http\Request;

class ApiResponderMiddleware{
    protected ResponseBuilderInterface $responseBuilder;

    public function __construct(ResponseBuilderInterface $responseBuilder){
        $this->responseBuilder = $responseBuilder;
    }

    public function handle(Request $request, Closure $next){
        $response = $next($request);
        // Add rate limiting metadata if available
        if($this->hasRateLimitHeaders($response)){
            $rateLimits = $this->extractRateLimitInfo($response);
            $this->responseBuilder->withMeta(['rate_limit' => $rateLimits]);
        }

        return $response;
    }

    protected function hasRateLimitHeaders($response): bool{
        return $response->headers->has('X-RateLimit-Limit');
    }

    protected function extractRateLimitInfo($response): array{
        return [
            'limit'     => (int) $response->headers->get('X-RateLimit-Limit'),
            'remaining' => (int) $response->headers->get('X-RateLimit-Remaining'),
            'reset'     => $response->headers->get('X-RateLimit-Reset'),
        ];
    }
}
