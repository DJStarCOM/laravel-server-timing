<?php

namespace DJStarCOM\ServerTiming\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SourceCompatibilityTest extends TestCase
{
    /**
     * Deprecations such as "Using ${var} in strings is deprecated" (PHP 8.2+) are raised while the
     * file is being compiled, i.e. before any test code runs and outside the reach of the test
     * runner's error handler. Linting each source file in a separate process is what actually
     * observes them.
     */
    public function testSourceFilesCompileWithoutDeprecationsOnTheRunningPhpVersion()
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $offenders = array_merge($offenders, $this->lint($file));
        }

        $this->assertSame(
            [],
            $offenders,
            'php -l reported diagnostics for src/ on PHP ' . PHP_VERSION
        );
    }

    public function testSourceDirectoryIsActuallyScanned()
    {
        $this->assertNotEmpty(
            $this->sourceFiles(),
            'No PHP files were found in src/, so the lint check above proves nothing.'
        );
    }

    /**
     * Positive control: without it the check above would also pass with a detector that
     * never reports anything.
     */
    public function testTheLintCheckReportsAKnownDeprecatedConstruct()
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('${var} interpolation is only deprecated as of PHP 8.2.');
        }

        $diagnostics = $this->lint(__DIR__ . '/fixtures/deprecated-interpolation.php.fixture');

        $this->assertNotEmpty($diagnostics, 'The lint check did not report the fixture at all.');
        $this->assertStringContainsString('${var} in strings is deprecated', implode("\n", $diagnostics));
    }

    /**
     * @return array<int, string>
     */
    private function lint(string $file): array
    {
        $output = (string) shell_exec(sprintf(
            '%s -d error_reporting=E_ALL -d display_errors=1 -l %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($file)
        ));

        $diagnostics = [];

        foreach (preg_split('/\R/', $output) as $line) {
            if (preg_match('/(Deprecated|Parse error|Fatal error|Warning):/', $line)) {
                $diagnostics[] = trim($line);
            }
        }

        return $diagnostics;
    }

    /**
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__) . '/src';

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
