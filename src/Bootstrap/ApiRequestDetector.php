<?php
namespace FallegaHQ\ApiResponder\Bootstrap;

use Illuminate\Http\Request;

class ApiRequestDetector{
    /**
     * Determine if the request should be handled as an API request.
     */
    public static function shouldHandle(Request $request): bool{
        $prefix     = config('api-responder.routing.prefix');
        $detectJson = config('api-responder.routing.detect_json', true);
        // If prefix is empty string or null, check all routes
        if($prefix === '' || $prefix === null){
            return !$detectJson || $request->expectsJson();
        }
        // Check if request matches the API prefix pattern
        $pattern = trim($prefix, '/') . '/*';

        return $request->is($pattern) || ($detectJson && $request->expectsJson());
    }

    /**
     * Get the configured API prefix.
     */
    public static function getPrefix(): string{
        return config('api-responder.routing.prefix', 'api');
    }
}
