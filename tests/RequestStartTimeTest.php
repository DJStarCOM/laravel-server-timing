<?php

namespace DJStarCOM\ServerTiming\Tests;

use PHPUnit\Framework\TestCase;

/**
 * ServerTimingMiddleware::getRequestStartTime() has two branches. LARAVEL_START is
 * covered by ServerTimingMiddlewareTest; the $_SERVER['REQUEST_TIME_FLOAT'] fallback
 * - the one the doc-comment advertises for non-Laravel entry points - cannot be
 * reached in the same process, because a constant cannot be undefined once some
 * other test has defined it. So it is measured in a process of its own.
 */
class RequestStartTimeTest extends TestCase
{
    public function testItFallsBackToRequestTimeFloatWhenLaravelStartIsNotDefined()
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        $this->assertFileExists($autoload);

        $script = <<<'PHP'
<?php
require $argv[1];

$_SERVER['REQUEST_TIME_FLOAT'] = 1234.5;

$middleware = new DJStarCOM\ServerTiming\Middleware\ServerTimingMiddleware(
    new DJStarCOM\ServerTiming\ServerTiming(new Symfony\Component\Stopwatch\Stopwatch())
);

$property = new ReflectionProperty($middleware, 'start');
$property->setAccessible(true);

echo json_encode([
    'laravel_start_defined' => defined('LARAVEL_START'),
    'start' => $property->getValue($middleware),
]);
PHP;

        $file = tempnam(sys_get_temp_dir(), 'server-timing-') . '.php';
        file_put_contents($file, $script);

        try {
            $output = shell_exec(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($autoload) . ' 2>&1'
            );
        } finally {
            @unlink($file);
        }

        $decoded = json_decode(trim((string) $output), true);

        $this->assertIsArray($decoded, 'The child process did not produce JSON. Output was: ' . var_export($output, true));

        $this->assertFalse(
            $decoded['laravel_start_defined'],
            'LARAVEL_START is defined in the child process, so this test proves nothing about the fallback.'
        );

        $this->assertSame(
            1234.5,
            $decoded['start'],
            'The middleware ignored $_SERVER["REQUEST_TIME_FLOAT"] and started timing from somewhere else.'
        );
    }
}
