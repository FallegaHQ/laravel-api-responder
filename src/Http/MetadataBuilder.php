<?php
namespace FallegaHQ\ApiResponder\Http;

use FallegaHQ\ApiResponder\Contracts\MetadataBuilderInterface;
use Illuminate\Support\Str;

class MetadataBuilder implements MetadataBuilderInterface{
    protected array $meta = [];
    protected array $config;

    public function __construct(){
        $this->config = config('api-responder.metadata');
    }

    public function build(): array{
        if(!$this->config['enabled']){
            return [];
        }
        if($this->config['include_timestamps']){
            $this->addTimestamps();
        }
        if($this->config['include_request_id']){
            $this->addRequestId();
        }
        if($this->config['include_version']){
            $this->addVersion();
        }

        return $this->meta;
    }

    public function addTimestamps(): self{
        $this->meta['timestamp'] = now()->toIso8601String();

        return $this;
    }

    public function addRequestId(): self{
        $this->meta['request_id'] = request()->header('X-Request-ID') ?? Str::uuid()
                                                                            ->toString();

        return $this;
    }

    public function addVersion(): self{
        $this->meta['api_version'] = $this->config['api_version'];

        return $this;
    }

    public function addExecutionTime(float $startTime): self{
        if($this->config['include_execution_time']){
            $this->meta['execution_time'] = round((microtime(true) - $startTime) * 1000, 2) . 'ms';
        }

        return $this;
    }

    public function addRateLimiting(array $limits): self{
        if(config('api-responder.rate_limiting.include_meta')){
            $this->meta['rate_limit'] = $limits;
        }

        return $this;
    }

    public function add(string $key, mixed $value): self{
        $this->meta[$key] = $value;

        return $this;
    }

    public function merge(array $meta): self{
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }
}
