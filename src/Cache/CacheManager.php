<?php
namespace FallegaHQ\ApiResponder\Cache;

use FallegaHQ\ApiResponder\Contracts\CacheManagerInterface;
use FallegaHQ\ApiResponder\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\Cache;

class CacheManager implements CacheManagerInterface{
    protected array                    $config;
    protected EventDispatcherInterface $eventDispatcher;

    public function __construct(EventDispatcherInterface $eventDispatcher){
        $this->config          = config('api-responder.cache');
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function remember(string $key, int $ttl, callable $callback): mixed{
        if(!$this->config['enabled']){
            return $callback();
        }
        $fullKey = $this->prefixKey($key);
        $driver  = Cache::store($this->config['driver']);
        if($driver->has($fullKey)){
            $this->eventDispatcher->onCacheHit($fullKey);
        }
        else{
            $this->eventDispatcher->onCacheMiss($fullKey);
        }

        return $driver->remember($fullKey, $ttl, $callback);
    }

    protected function prefixKey(string $key): string{
        return $this->config['prefix'] . ':' . $key;
    }

    public function forget(string $key): bool{
        if(!$this->config['enabled']){
            return false;
        }
        $fullKey = $this->prefixKey($key);

        return Cache::store($this->config['driver'])
                    ->forget($fullKey);
    }

    /**
     * @throws \JsonException
     */
    public function generateKey(string $context, array $params = []): string{
        $paramString = empty($params) ? '' : ':' . md5(json_encode($params, JSON_THROW_ON_ERROR));

        return $context . $paramString;
    }
}
