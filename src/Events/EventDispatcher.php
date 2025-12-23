<?php
namespace FallegaHQ\ApiResponder\Events;

use FallegaHQ\ApiResponder\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\Event;

class EventDispatcher implements EventDispatcherInterface{
    public function beforeTransform($source): void{
        if(config('api-responder.events.enabled')){
            Event::dispatch(new BeforeTransformEvent($source, get_class($source)));
        }
    }

    public function afterTransform($source, array $result): void{
        if(config('api-responder.events.enabled')){
            Event::dispatch(new AfterTransformEvent($source, $result, get_class($source)));
        }
    }

    public function onCacheHit(string $key): void{
        if(config('api-responder.events.fire_cache_events')){
            Event::dispatch(new CacheHitEvent($key, null));
        }
    }

    public function onCacheMiss(string $key): void{
        if(config('api-responder.events.fire_cache_events')){
            Event::dispatch(new CacheMissEvent($key));
        }
    }
}
