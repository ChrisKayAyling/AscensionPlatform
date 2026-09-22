#!/usr/bin/env php
<?php

/**
 * Portable build/QA runner for AscensionPlatform.
 *
 * This is the same tool as build.sh, reimplemented in pure PHP with no
 * dependencies of its own, so it can be compiled into a single build.phar
 * (see box.json, and `./build.sh phar`) and run anywhere a PHP CLI binary
 * is available - copy build.phar to any checkout, on any machine, and run:
 *
 *   php build.phar install
 *   php build.phar test lint
 *   php build.phar all --root=/path/to/AscensionPlatform
 *
 * --root defaults to the current working directory, so running it from
 * inside a checkout needs no flag at all.
 */

declare(strict_types=1);

namespace AscensionPlatform\Build;

final class Runner
{
    private string $root;
    private string $reportsDir;
    private int $status = 0;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->reportsDir = $this->root . '/build/reports';
    }

    /**
     * @param string[] $commands
     */
    public function run(array $commands): int
    {
        if ($commands === []) {
            $this->usage();
            return 0;
        }

        $dispatch = [
            'install' => fn () => $this->install(),
            'db:init' => fn () => $this->dbInit(),
            'test' => fn () => $this->test(),
            'lint' => fn () => $this->lint(),
            'lint:fix' => fn () => $this->lintFix(),
            'docs' => fn () => $this->docs(),
            'swagger' => fn () => $this->swagger(),
            'reports' => fn () => $this->reports(),
            'all' => fn () => $this->all(),
            'version' => fn () => $this->version(),
        ];

        foreach ($commands as $command) {
            if (!isset($dispatch[$command])) {
                fwrite(STDERR, "Unknown command: {$command}\n");
                $this->status = 1;
                continue;
            }

            $dispatch[$command]();
        }

        return $this->status;
    }

    private function all(): void
    {
        $this->install();
        $this->dbInit();
        $this->lint();
        $this->test();
        $this->docs();
        $this->swagger();
        $this->reports();
    }

    private function install(): void
    {
        $this->ensureDir($this->root);
        $composer = $this->composerCommand();

        if ($composer === null) {
            $this->fail('composer install (could not locate or bootstrap composer)');
            return;
        }

        $this->exec([...$composer, 'install', '--no-interaction', '--prefer-dist'], 'composer install');
    }

    private function dbInit(): void
    {
        $setup = $this->root . '/db/setup.php';

        if (!is_file($setup)) {
            $this->fail("db:init ({$setup} not found)");
            return;
        }

        $this->exec([PHP_BINARY, $setup], 'db:init');
    }

    private function test(): void
    {
        $this->ensureReportsDir();
        $phpunit = $this->root . '/vendor/bin/phpunit';

        if (!is_file($phpunit)) {
            $this->fail('phpunit not installed - run the install command first');
            return;
        }

        $this->exec([
            PHP_BINARY,
            $phpunit,
            '--log-junit',
            $this->reportsDir . '/junit.xml',
            '--testdox-text',
            $this->reportsDir . '/phpunit.txt',
        ], 'phpunit');
    }

    private function lint(): void
    {
        $this->ensureReportsDir();
        $phpcs = $this->root . '/vendor/bin/phpcs';
        $standard = $this->root . '/tools/phpcs.xml';

        if (!is_file($phpcs)) {
            $this->fail('phpcs not installed - run the install command first');
            return;
        }

        // Checkstyle report first (never gates the run), then a
        // human-readable pass whose exit code we do track.
        $this->exec([
            PHP_BINARY, $phpcs,
            '--standard=' . $standard,
            '--report=checkstyle',
            '--report-file=' . $this->reportsDir . '/checkstyle.xml',
            $this->root . '/lib', $this->root . '/src',
        ], 'phpcs (checkstyle)', gate: false);

        $this->exec([
            PHP_BINARY, $phpcs, '--standard=' . $standard, $this->root . '/lib', $this->root . '/src',
        ], 'phpcs', gate: false);
    }

    private function lintFix(): void
    {
        $phpcbf = $this->root . '/vendor/bin/phpcbf';
        $standard = $this->root . '/tools/phpcs.xml';

        if (!is_file($phpcbf)) {
            $this->fail('phpcbf not installed - run the install command first');
            return;
        }

        $this->exec([
            PHP_BINARY, $phpcbf, '--standard=' . $standard, $this->root . '/lib', $this->root . '/src',
        ], 'phpcbf', gate: false);
    }

    private function docs(): void
    {
        $this->ensureReportsDir();
        $phpdoc = $this->root . '/vendor/bin/phpdoc';

        if (!is_file($phpdoc)) {
            $this->fail('phpdoc not installed - run the install command first');
            return;
        }

        $this->exec([
            PHP_BINARY, $phpdoc, 'run',
            '-d', $this->root . '/src',
            '-d', $this->root . '/lib',
            '-t', $this->reportsDir . '/docs',
        ], 'phpdoc');
    }

    private function swagger(): void
    {
        $this->ensureReportsDir();
        $openapi = $this->root . '/vendor/bin/openapi';

        if (!is_file($openapi)) {
            $this->fail('zircote/swagger-php not installed - run the install command first');
            return;
        }

        $this->exec([
            PHP_BINARY, $openapi, $this->root . '/lib', '--output', $this->reportsDir . '/openapi.json',
        ], 'swagger-php');
    }

    private function reports(): void
    {
        $this->ensureReportsDir();

        $summary = [
            'generatedAt' => date(DATE_ATOM),
            'version' => $this->currentVersion(),
            'phpunit' => is_file($this->reportsDir . '/junit.xml'),
            'checkstyle' => is_file($this->reportsDir . '/checkstyle.xml'),
            'docs' => is_dir($this->reportsDir . '/docs'),
            'openapi' => is_file($this->reportsDir . '/openapi.json'),
        ];

        file_put_contents(
            $this->reportsDir . '/summary.json',
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->log('Wrote ' . $this->reportsDir . '/summary.json');
    }

    private function version(): void
    {
        echo $this->currentVersion() . "\n";
    }

    private function currentVersion(): string
    {
        $describe = trim((string)shell_exec(sprintf(
            'git -C %s describe --tags --always 2>/dev/null',
            escapeshellarg($this->root)
        )));

        return $describe !== '' ? $describe : '0.0.0-dev';
    }

    private function composerCommand(): ?array
    {
        if ($this->commandExists('composer')) {
            return ['composer'];
        }

        $local = $this->root . '/composer.phar';
        if (is_file($local)) {
            return [PHP_BINARY, $local];
        }

        $this->log('composer not found - downloading composer.phar');
        $installer = $this->root . '/composer-setup.php';

        $contents = @file_get_contents('https://getcomposer.org/installer');
        if ($contents === false) {
            return null;
        }

        file_put_contents($installer, $contents);
        $this->exec([PHP_BINARY, $installer, '--quiet', '--install-dir=' . $this->root], 'composer-setup');
        @unlink($installer);

        return is_file($local) ? [PHP_BINARY, $local] : null;
    }

    private function commandExists(string $binary): bool
    {
        $which = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where' : 'command -v';
        $result = shell_exec("{$which} " . escapeshellarg($binary) . ' 2>/dev/null');

        return $result !== null && trim($result) !== '';
    }

    /**
     * @param string[] $command
     */
    private function exec(array $command, ?string $label = null, bool $gate = true): bool
    {
        $label ??= implode(' ', $command);
        $this->log($label);

        $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes, $this->root);

        if (!is_resource($process)) {
            $this->fail("Failed to start: {$label}");
            return false;
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && $gate) {
            $this->fail("{$label} (exit {$exitCode})");
            return false;
        }

        return true;
    }

    private function ensureReportsDir(): void
    {
        $this->ensureDir($this->reportsDir);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function log(string $message): void
    {
        fwrite(STDERR, "\n==> {$message}\n");
    }

    private function fail(string $message): void
    {
        fwrite(STDERR, "FAILED: {$message}\n");
        $this->status = 1;
    }

    private function usage(): void
    {
        echo <<<TXT
        AscensionPlatform build & QA toolchain runner (portable/phar build).

        Usage: php build.phar <command> [command...] [--root=/path/to/project]

          install     Install composer dependencies (bootstraps composer.phar if needed).
          db:init     (Re)initialise etc/db.db from db/schema.sql.
          test        Run PHPUnit -> build/reports/junit.xml.
          lint        Run PHP_CodeSniffer -> build/reports/checkstyle.xml.
          lint:fix    Auto-fix what PHP_CodeSniffer can (phpcbf).
          docs        Generate phpDocumentor output -> build/reports/docs/.
          swagger     Generate an OpenAPI document -> build/reports/openapi.json.
          reports     Refresh build/reports/summary.json.
          all         install, db:init, lint, test, docs, swagger, reports.
          version     Print the current platform version.

        --root defaults to the current working directory.

        TXT;
    }
}

$root = null;
$commands = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = substr($arg, strlen('--root='));
    } else {
        $commands[] = $arg;
    }
}

$runner = new Runner($root ?? getcwd());
exit($runner->run($commands));
