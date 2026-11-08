<?php

namespace DJStarCOM\ServerTiming\Tests\Middleware;

use DJStarCOM\ServerTiming\Middleware\ServerTimingMiddleware;
use DJStarCOM\ServerTiming\ServerTiming;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\HttpFoundation\Response;

class ServerTimingMiddlewareTest extends TestCase
{
    public function testItAddsServerTimingHeader()
    {
        $response = $this->handleWith(new ServerTiming(new Stopwatch()));

        $this->assertArrayHasKey('server-timing', $response->headers->all());
    }

    public function testItIsBypassedIfConfigurationFalse()
    {
        $this->app['config']->set('timing.enabled', false);

        $response = $this->handleWith(new ServerTiming(new Stopwatch()));

        $this->assertArrayNotHasKey('server-timing', $response->headers->all());
    }

    public function testItRendersMetricValuesIntoTheHeader()
    {
        $timing = new ServerTiming(new Stopwatch());
        $timing->setDuration('Cache Lookup', 12.5);
        $timing->addMetric('No Duration');

        $header = $this->handleWith($timing)->headers->get('server-timing');

        $this->assertContains('cache-lookup;desc="Cache Lookup";dur=12.5', $this->metricsIn($header));
        $this->assertContains('no-duration;desc="No Duration"', $this->metricsIn($header));
    }

    public function testItSeparatesMetricsWithACommaAndNoTrailingSeparator()
    {
        $timing = new ServerTiming(new Stopwatch());
        $timing->setDuration('Cache Lookup', 12.5);
        $timing->addMetric('No Duration');

        $header = $this->handleWith($timing)->headers->get('server-timing');

        // RFC 8941 list: elements are separated by ", " and no element may be empty,
        // so the header must not end with a separator either.
        $this->assertStringContainsString('dur=12.5, no-duration;desc="No Duration"', $header);
        $this->assertStringEndsNotWith(',', rtrim($header));

        $metrics = $this->metricsIn($header);

        $this->assertGreaterThan(1, count($metrics));

        foreach ($metrics as $index => $metric) {
            $this->assertNotSame('', $metric, "Metric #{$index} is empty in header: {$header}");

            // RFC 8941 section 3.1.2: a parameter is ";" name ["=" value]; a ";"
            // with nothing after it is not a parameter, so no element may end in one.
            $this->assertStringEndsNotWith(';', $metric, "Metric #{$index} ends in a dangling ';': {$metric}");

            $this->assertSame(
                1,
                preg_match('/^[a-z0-9-]+;desc="[^"]+"(;dur=[0-9.eE+-]+)?$/', $metric),
                "Metric #{$index} is not a valid Server-Timing element: {$metric}"
            );
        }
    }

    /**
     * The point of the package: the middleware must time the request itself, not
     * merely render whatever metrics somebody else happened to add. Bootstrap is
     * the time before the middleware ran, App the time spent in the rest of the
     * pipeline, Total the whole request.
     */
    public function testItMeasuresBootstrapAppAndTotal()
    {
        $header = $this->handleWith(new ServerTiming(new Stopwatch()), function () {
            usleep(5000); // 5 ms of "application"

            return new Response();
        })->headers->get('server-timing');

        $metrics = $this->metricsIn($header);

        $this->assertStringContainsString('bootstrap;desc="Bootstrap";dur=', $header, "Bootstrap metric missing from: {$header}");
        $this->assertStringContainsString('app;desc="App";dur=', $header, "App metric missing from: {$header}");
        $this->assertStringContainsString('total;desc="Total";dur=', $header, "Total metric missing from: {$header}");

        $bootstrap = $this->durationOf($header, 'bootstrap');
        $app = $this->durationOf($header, 'app');
        $total = $this->durationOf($header, 'total');

        $this->assertNotNull($bootstrap, "Bootstrap has no duration in: {$header}");
        $this->assertNotNull($app, "App has no duration in: {$header}");
        $this->assertNotNull($total, "Total has no duration in: {$header}");

        // Bootstrap is the time from the request start to this middleware. Asserting
        // only that it is present leaves setDuration('Bootstrap', 0) - a constant
        // instead of a measurement - indistinguishable from a working package.
        $this->assertGreaterThan(0.0, $bootstrap, "Bootstrap was not measured in: {$header}");

        // The pipeline slept for 5 ms, so a real measurement cannot be below that.
        $this->assertGreaterThanOrEqual(4.0, $app, "App was not measured in: {$header}");

        // Total covers the same window as App plus everything before it.
        $this->assertGreaterThanOrEqual($app, $total, "Total is smaller than App in: {$header}");
        $this->assertGreaterThan(0.0, $total, "Total was not measured in: {$header}");

        $this->assertCount(3, $metrics, "Expected exactly the three built-in metrics, got: {$header}");
    }

    /**
     * An event started during the request and never stopped by the caller has no
     * duration of its own. The middleware is responsible for closing it, otherwise
     * it is rendered as a bare name and the measurement is silently lost.
     */
    public function testItStopsEventsThatWereLeftRunning()
    {
        $timing = new ServerTiming(new Stopwatch());

        $header = $this->handleWith($timing, function () use ($timing) {
            $timing->start('Left Running');
            usleep(5000);

            return new Response();
        })->headers->get('server-timing');

        $this->assertStringContainsString('left-running;desc="Left Running";dur=', $header, "Unfinished event was not stopped: {$header}");

        $duration = $this->durationOf($header, 'left-running');

        $this->assertNotNull($duration, "Unfinished event has no duration in: {$header}");
        $this->assertGreaterThanOrEqual(4.0, $duration, "Unfinished event was not actually measured: {$header}");
    }

    /**
     * LARAVEL_START is set in public/index.php before the framework boots, so it is
     * the only value that makes Bootstrap mean anything. REQUEST_TIME_FLOAT is the
     * fallback for non-Laravel entry points.
     */
    public function testItMeasuresFromLaravelStartWhenItIsDefined()
    {
        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        $original = array_key_exists('REQUEST_TIME_FLOAT', $_SERVER) ? $_SERVER['REQUEST_TIME_FLOAT'] : null;

        // One hour before the framework booted. Only a middleware that ignores
        // LARAVEL_START can pick this up.
        $_SERVER['REQUEST_TIME_FLOAT'] = LARAVEL_START - 3600.0;

        try {
            $header = $this->handleWith(new ServerTiming(new Stopwatch()))->headers->get('server-timing');
        } finally {
            if ($original === null) {
                unset($_SERVER['REQUEST_TIME_FLOAT']);
            } else {
                $_SERVER['REQUEST_TIME_FLOAT'] = $original;
            }
        }

        $total = $this->durationOf($header, 'total');

        $this->assertNotNull($total, "Total has no duration in: {$header}");
        $this->assertLessThan(
            3600000.0,
            $total,
            "Timing started from REQUEST_TIME_FLOAT instead of LARAVEL_START: {$header}"
        );
    }

    /**
     * @return string[]
     */
    private function metricsIn(string $header): array
    {
        return explode(', ', $header);
    }

    private function durationOf(string $header, string $slug): ?float
    {
        foreach ($this->metricsIn($header) as $metric) {
            if (preg_match('/^' . preg_quote($slug, '/') . ';desc="[^"]+";dur=([0-9.eE+-]+)$/', $metric, $matches)) {
                return (float)$matches[1];
            }
        }

        return null;
    }

    private function handleWith(ServerTiming $timing, ?callable $next = null): Response
    {
        $middleware = new ServerTimingMiddleware($timing);

        $next = $next ?: function ($request) {
            return new Response();
        };

        return $middleware->handle(new Request, function ($request) use ($next) {
            return $next($request);
        });
    }
}
