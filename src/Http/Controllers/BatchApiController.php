<?php
namespace FallegaHQ\ApiResponder\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BatchApiController extends BaseApiController{
    public function batch(Request $request): JsonResponse{
        if(!config('api-responder.batch.enabled')){
            return $this->error('Batch operations are not enabled', null, 501);
        }
        $validated   = $request->validate(
            [
                'operations'          => 'required|array|max:' . config('api-responder.batch.max_operations'),
                'operations.*.method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
                'operations.*.url'    => 'required|string',
                'operations.*.data'   => 'sometimes|array',
            ]
        );
        $results     = [];
        $stopOnError = config('api-responder.batch.stop_on_error');
        foreach($validated['operations'] as $index => $operation){
            try{
                $response  = $this->executeOperation($operation);
                $results[] = [
                    'index'  => $index,
                    'status' => $response->getStatusCode(),
                    'data'   => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR),
                ];
            }
            catch(Exception $e){
                $results[] = [
                    'index'  => $index,
                    'status' => 500,
                    'error'  => $e->getMessage(),
                ];
                if($stopOnError){
                    break;
                }
            }
        }

        return $this->success(
            [
                'results'    => $results,
                'total'      => count($validated['operations']),
                'successful' => count(array_filter($results, static fn($r) => $r['status'] < 400)),
                'failed'     => count(array_filter($results, static fn($r) => $r['status'] >= 400)),
            ]
        );
    }

    /**
     * @throws \Exception
     */
    protected function executeOperation(array $operation): Response{
        $method  = strtolower($operation['method']);
        $url     = $operation['url'];
        $data    = $operation['data'] ?? [];
        $request = Request::create($url, strtoupper($method), $data);

        return app()->handle($request);
    }
}
