<?php
namespace FallegaHQ\ApiResponder;

use FallegaHQ\ApiResponder\Console\Commands\GenerateDocumentationCommand;
use FallegaHQ\ApiResponder\Contracts\CacheManagerInterface;
use FallegaHQ\ApiResponder\Contracts\EventDispatcherInterface;
use FallegaHQ\ApiResponder\Contracts\FieldsetParserInterface;
use FallegaHQ\ApiResponder\Contracts\MetadataBuilderInterface;
use FallegaHQ\ApiResponder\Contracts\ResponseBuilderInterface;
use FallegaHQ\ApiResponder\Contracts\ValidationFormatterInterface;
use FallegaHQ\ApiResponder\Contracts\VisibilityResolverInterface;
use FallegaHQ\ApiResponder\Exceptions\ApiExceptionHandler;
use FallegaHQ\ApiResponder\Http\Middleware\ApiResponderMiddleware;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;

class ApiResponderServiceProvider extends ServiceProvider{
    public function register(): void{
        $this->mergeConfigFrom(__DIR__ . '/../config/api-responder.php', 'api-responder');
        // Bind all interfaces
        $this->registerBindings();
        // Register response macros
        $this->registerMacros();
    }

    protected function registerBindings(): void{
        $bindings = [
            ResponseBuilderInterface::class     => 'response_builder',
            CacheManagerInterface::class        => 'cache_manager',
            VisibilityResolverInterface::class  => 'visibility_resolver',
            MetadataBuilderInterface::class     => 'metadata_builder',
            FieldsetParserInterface::class      => 'fieldset_parser',
            EventDispatcherInterface::class     => 'event_dispatcher',
            ValidationFormatterInterface::class => 'validation_formatter',
        ];
        foreach($bindings as $interface => $configKey){
            $this->app->singleton(
                $interface,
                function($app) use ($configKey){
                    $class = config("api-responder.bindings.$configKey");

                    return $class ? $app->make($class) : null;
                }
            );
        }
        $this->app->alias(ResponseBuilderInterface::class, 'api.responder');
    }

    protected function registerMacros(): void{
        Response::macro(
            'api',
            static function($data = null, ?string $message = null, int $status = 200){
                return app(ResponseBuilderInterface::class)->success($data, $message, $status);
            }
        );
        Response::macro(
            'apiError',
            static function(?string $message = null, $errors = null, int $status = 400){
                return app(ResponseBuilderInterface::class)->error($message, $errors, $status);
            }
        );
    }

    public function boot(): void{
        if($this->app->runningInConsole()){
            $this->publishes(
                [
                    __DIR__ . '/../config/api-responder.php' => config_path('api-responder.php'),
                ],
                'api-responder-config'
            );
            $this->commands(
                [
                    GenerateDocumentationCommand::class,
                ]
            );
        }
        // Register middleware
        $this->app['router']->aliasMiddleware('api.responder', ApiResponderMiddleware::class);
        // Register exception handler
        if(config('api-responder.error_handling.log_errors')){
            $this->registerExceptionHandler();
        }
    }

    protected function registerExceptionHandler(): void{
        $this->app->singleton(
            ExceptionHandler::class,
            ApiExceptionHandler::class
        );
    }
}
