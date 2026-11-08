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

        $this->assertStringContainsString('cache-lookup;desc="Cache Lookup";dur=12.5', $header);
        $this->assertStringContainsString('no-duration;desc="No Duration";', $header);
    }

    public function testItSeparatesMetricsWithACommaAndNoTrailingSeparator()
    {
        $timing = new ServerTiming(new Stopwatch());
        $timing->setDuration('Cache Lookup', 12.5);
        $timing->addMetric('No Duration');

        $header = $this->handleWith($timing)->headers->get('server-timing');

        // RFC 8941 list: elements are separated by ", " and no element may be empty,
        // so the header must not end with a separator either.
        $this->assertStringContainsString('dur=12.5, no-duration;desc="No Duration";', $header);
        $this->assertStringEndsNotWith(',', rtrim($header));

        $metrics = explode(', ', $header);

        $this->assertGreaterThan(1, count($metrics));

        foreach ($metrics as $index => $metric) {
            $this->assertNotSame('', $metric, "Metric #{$index} is empty in header: {$header}");
            $this->assertSame(
                1,
                preg_match('/^[a-z0-9-]+;desc="[^"]+";(dur=[0-9.eE+-]+)?$/', $metric),
                "Metric #{$index} is not a valid Server-Timing element: {$metric}"
            );
        }
    }

    private function handleWith(ServerTiming $timing): Response
    {
        $middleware = new ServerTimingMiddleware($timing);

        return $middleware->handle(new Request, function ($request) {
            return new Response();
        });
    }
}
