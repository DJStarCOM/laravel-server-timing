<?php

namespace DJStarCOM\ServerTiming\Tests;

use DJStarCOM\ServerTiming\Facades\ServerTiming as ServerTimingFacade;
use DJStarCOM\ServerTiming\Middleware\ServerTimingMiddleware;
use DJStarCOM\ServerTiming\ServerTiming;
use DJStarCOM\ServerTiming\ServerTimingServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Every other test in this package builds `new ServerTimingMiddleware(new ServerTiming(...))`
 * by hand, so nothing exercises what an application actually uses: the container
 * binding, the measure_timing() helper, the ServerTiming facade and the published
 * config. That gap is not theoretical - with the binding changed from singleton to
 * bind, the middleware holds a different instance than the helper and the facade
 * write to, and every metric the application added disappears from the header while
 * the suite stays green.
 */
class ContainerIntegrationTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServerTimingServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Stopwatch' => ServerTimingFacade::class];
    }

    public function testTheContainerSharesASingleInstance()
    {
        $this->assertSame(
            $this->app->make(ServerTiming::class),
            $this->app->make(ServerTiming::class),
            'ServerTiming must be a singleton: the middleware, the helper and the facade have to write to one recorder.'
        );
    }

    /**
     * The whole public API of the package in one request: the helper's measure()
     * branch, the helper's callable branch, and the facade - all through the real
     * container and the real HTTP kernel.
     */
    public function testMetricsAddedThroughTheHelperAndTheFacadeReachTheHeader()
    {
        $callableRan = false;

        Route::middleware(ServerTimingMiddleware::class)->get('/_timing_probe', function () use (&$callableRan) {
            measure_timing('Helper Block');
            usleep(6000);
            measure_timing('Helper Block');

            measure_timing('Helper Callable', function () use (&$callableRan) {
                $callableRan = true;
                usleep(6000);
            });

            ServerTimingFacade::setDuration('Facade Metric', 42);

            return 'ok';
        });

        $response = $this->get('/_timing_probe');

        $response->assertStatus(200);

        $header = (string) $response->headers->get('server-timing');

        $this->assertTrue($callableRan, 'measure_timing() did not call the callable it was given.');

        $this->assertStringContainsString(
            'helper-block;desc="Helper Block";dur=',
            $header,
            "measure_timing() did not record a block in: {$header}"
        );
        $this->assertStringContainsString(
            'helper-callable;desc="Helper Callable";dur=',
            $header,
            "measure_timing() with a callable did not record anything in: {$header}"
        );
        $this->assertStringContainsString(
            'facade-metric;desc="Facade Metric";dur=42',
            $header,
            "The facade wrote to a different instance than the middleware reads: {$header}"
        );

        // Both blocks slept for 6 ms, so a real measurement cannot be below that.
        $this->assertGreaterThanOrEqual(4.0, $this->durationOf($header, 'helper-block'), "Helper block was not measured: {$header}");
        $this->assertGreaterThanOrEqual(4.0, $this->durationOf($header, 'helper-callable'), "Helper callable was not measured: {$header}");
    }

    /**
     * The middleware honours config('timing.enabled'), and the file this package
     * ships is what an application gets after vendor:publish. Shipping it with
     * enabled => false switches the package off for everyone who publishes it.
     */
    public function testThePublishedConfigLeavesTheMiddlewareEnabled()
    {
        $config = require __DIR__ . '/../src/config/config.php';

        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);

        $this->app['config']->set('timing', $config);

        Route::middleware(ServerTimingMiddleware::class)->get('/_timing_config_probe', function () {
            return 'ok';
        });

        $this->assertNotNull(
            $this->get('/_timing_config_probe')->headers->get('server-timing'),
            'With the config this package ships, the middleware produced no header at all.'
        );
    }

    public function testTheConfigFileIsRegisteredUnderTheDocumentedTag()
    {
        $paths = ServiceProvider::pathsToPublish(ServerTimingServiceProvider::class, 'server-timing-config');

        $this->assertNotEmpty(
            $paths,
            'Nothing is registered under the server-timing-config tag, so vendor:publish --tag=server-timing-config publishes nothing.'
        );

        $source = (string) array_key_first($paths);

        $this->assertFileExists($source, "The publish source does not exist: {$source}");
        $this->assertSame(realpath(__DIR__ . '/../src/config/config.php'), realpath($source));
        $this->assertSame(config_path('timing.php'), $paths[$source]);
    }

    private function durationOf(string $header, string $slug): ?float
    {
        foreach (explode(', ', $header) as $metric) {
            if (preg_match('/^' . preg_quote($slug, '/') . ';desc="[^"]+";dur=([0-9.eE+-]+)$/', $metric, $matches)) {
                return (float) $matches[1];
            }
        }

        return null;
    }
}
