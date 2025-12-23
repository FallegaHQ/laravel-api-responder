<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Cache\CacheManager;
use FallegaHQ\ApiResponder\Contracts\EventDispatcherInterface;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Mockery;

class CacheManagerTest extends TestCase{
    protected CacheManager                                                               $manager;
    protected EventDispatcherInterface|Mockery\MockInterface|Mockery\LegacyMockInterface $eventDispatcher;

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function test_remember_caches_value_when_enabled(): void{
        $this->eventDispatcher->expects('onCacheMiss');
        $result = $this->manager->remember(
            'test_key',
            60,
            function(){
                return 'cached_value';
            }
        );
        $this->assertEquals('cached_value', $result);
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function test_remember_fires_cache_hit_event(): void{
        Cache::shouldReceive('store')
             ->andReturnSelf();
        Cache::shouldReceive('has')
             ->andReturn(true);
        Cache::shouldReceive('remember')
             ->andReturn('cached_value');
        $this->eventDispatcher->expects('onCacheHit');
        $result = $this->manager->remember('test_key', 60, fn() => 'value');
        $this->assertEquals('cached_value', $result);
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function test_remember_bypasses_cache_when_disabled(): void{
        config(['api-responder.cache.enabled' => false]);
        $manager = new CacheManager($this->eventDispatcher);
        $result  = $manager->remember(
            'test_key',
            60,
            function(){
                return 'direct_value';
            }
        );
        $this->assertEquals('direct_value', $result);
    }

    public function test_forget_removes_cached_value(): void{
        Cache::shouldReceive('store')
             ->andReturnSelf();
        Cache::shouldReceive('forget')
             ->with('api_responder:test_key')
             ->andReturn(true);
        $result = $this->manager->forget('test_key');
        $this->assertTrue($result);
    }

    public function test_forget_returns_false_when_disabled(): void{
        config(['api-responder.cache.enabled' => false]);
        $manager = new CacheManager($this->eventDispatcher);
        $result  = $manager->forget('test_key');
        $this->assertFalse($result);
    }

    /**
     * @throws \JsonException
     */
    public function test_generate_key_creates_unique_key(): void{
        $key = $this->manager->generateKey(
            'context',
            [
                'id'   => 1,
                'type' => 'user',
            ]
        );
        $this->assertStringContainsString('context', $key);
        $this->assertStringContainsString(':', $key);
    }

    /**
     * @throws \JsonException
     */
    public function test_generate_key_without_params(): void{
        $key = $this->manager->generateKey('simple_context');
        $this->assertEquals('simple_context', $key);
    }

    protected function setUp(): void{
        parent::setUp();
        config(
            [
                'api-responder.cache' => [
                    'enabled'     => true,
                    'driver'      => 'array',
                    'prefix'      => 'api_responder',
                    'default_ttl' => 3600,
                ],
            ]
        );
        $this->eventDispatcher = Mockery::mock(EventDispatcherInterface::class);
        $this->manager         = new CacheManager($this->eventDispatcher);
    }

    protected function tearDown(): void{
        Mockery::close();
        parent::tearDown();
    }
}
