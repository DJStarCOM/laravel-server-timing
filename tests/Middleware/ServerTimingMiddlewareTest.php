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
    /** @test */
    public function it_add_server_timing_header()
    {
        $request = new Request;

        $timing = new ServerTiming(new Stopwatch());

        $middleware = new ServerTimingMiddleware($timing);

        $response = $middleware->handle($request, function ($req) {
            return new Response();
        });

        $this->assertArrayHasKey('server-timing', $response->headers->all());

    }

    /** @test */
    public function it_is_bypassed_if_configuration_false()
    {
        $this->app['config']->set('timing.enabled', false);

        $request = new Request;

        $timing = new ServerTiming(new Stopwatch());

        $middleware = new ServerTimingMiddleware($timing);

        $response = $middleware->handle($request, function ($req) {
            return new Response();
        });

        $this->assertArrayNotHasKey('server-timing', $response->headers->all());
    }

    /** @test */
    public function it_renders_metric_values_into_the_header()
    {
        $request = new Request;

        $timing = new ServerTiming(new Stopwatch());
        $timing->setDuration('Cache Lookup', 12.5);
        $timing->addMetric('No Duration');

        $middleware = new ServerTimingMiddleware($timing);

        $response = $middleware->handle($request, function ($req) {
            return new Response();
        });

        $header = $response->headers->get('server-timing');

        // Имя метрики и её значение обязаны попасть в заголовок целиком.
        // Шаблон "${var}" в двойных кавычках даёт тот же результат на PHP < 8.2,
        // но помечен deprecated в 8.2 и удалён в PHP 9 — тест фиксирует вывод,
        // а не способ его получения.
        $this->assertStringContainsString('cache-lookup;desc="Cache Lookup";dur=12.5', $header);
        $this->assertStringContainsString('no-duration;desc="No Duration";', $header);
        $this->assertStringNotContainsString('${', $header);
    }
}
