<?php

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * OPS-2 — .env.example is a coherent, Reverb-ready local-development template.
 * The example is exercised as a real fresh clone would see it: a child process
 * boots the application with the example file loaded AS the environment file,
 * so the effective config comes from the actual env → config flow, never from
 * this suite's own phpunit.xml overrides (which force BROADCAST_CONNECTION=null).
 */
const REVERB_SERVER_VARIABLES = ['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'];

const REVERB_BROWSER_VARIABLES = ['VITE_REVERB_APP_KEY', 'VITE_REVERB_HOST', 'VITE_REVERB_PORT', 'VITE_REVERB_SCHEME'];

/**
 * @return array<string, string|null>
 */
function envExampleValues(): array
{
    return Dotenv::parse(file_get_contents(base_path('.env.example')));
}

/**
 * @return array<int, string> Every non-comment line of the example file.
 */
function envExampleAssignmentLines(): array
{
    return array_values(array_filter(
        array_map('trim', file(base_path('.env.example'))),
        fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));
}

/**
 * Effective configuration of the application booted with .env.example as its
 * environment file. $overrides are REAL process environment variables, which
 * Laravel (like every dotenv loader) lets win over the file.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function configFromEnvExample(array $overrides = []): array
{
    // phpunit.xml exports its own values into this process and they would be
    // inherited by the child — clear every variable under test first.
    $cleared = array_fill_keys([...array_keys(envExampleValues()), 'REVERB_SERVER_HOST', 'REVERB_SERVER_PORT'], false);

    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.example');
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode([
            'default' => config('broadcasting.default'),
            'broadcasting' => config('broadcasting.connections.reverb'),
            'server' => config('reverb.servers.reverb'),
            'app' => config('reverb.apps.apps.0'),
        ]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script], base_path(), [...$cleared, ...$overrides]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

test('the example contains every Reverb variable the broadcasting config, the Reverb config and echo.js consume', function () {
    $example = envExampleValues();

    // Discovered from the consumers themselves, so a renamed or newly required
    // variable cannot silently drift away from the example.
    $broadcasting = file_get_contents(config_path('broadcasting.php'));
    $reverbConnection = substr($broadcasting, strpos($broadcasting, "'reverb' => ["), strpos($broadcasting, "'pusher' => [") - strpos($broadcasting, "'reverb' => ["));
    preg_match_all("/env\('(REVERB_[A-Z_]+)'/", $reverbConnection, $serverMatches);
    preg_match_all('/import\.meta\.env\.(VITE_REVERB_[A-Z_]+)/', file_get_contents(resource_path('js/echo.js')), $browserMatches);

    expect(array_values(array_unique($serverMatches[1])))->toEqualCanonicalizing(REVERB_SERVER_VARIABLES);
    expect(array_values(array_unique($browserMatches[1])))->toEqualCanonicalizing(REVERB_BROWSER_VARIABLES);

    foreach ([...REVERB_SERVER_VARIABLES, ...REVERB_BROWSER_VARIABLES, 'BROADCAST_CONNECTION'] as $name) {
        expect($example)->toHaveKey($name);
        expect($example[$name])->not->toBeEmpty();
    }
});

test('the browser never receives the Reverb secret', function () {
    expect(envExampleValues())->not->toHaveKey('VITE_REVERB_APP_SECRET');

    foreach (envExampleAssignmentLines() as $line) {
        if (str_starts_with($line, 'VITE_')) {
            expect(strtoupper($line))->not->toContain('SECRET');
        }
    }
});

test('the example ships no real secret: every credential-like value is empty, null or an explicit local-dev placeholder', function () {
    foreach (envExampleValues() as $name => $value) {
        if (preg_match('/SECRET|PASSWORD|TOKEN|(^|_)KEY$/', $name) !== 1) {
            continue;
        }

        expect((string) $value)->toMatch('/^(|null|local-dev-[a-z-]+)$/', "{$name} holds a value that looks like a real credential.");
    }

    foreach (['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET'] as $name) {
        expect(envExampleValues()[$name])->toStartWith('local-dev-');
    }
});

test('a fresh environment created from the example selects Reverb, with backend, Reverb server and browser values consistent', function () {
    $config = configFromEnvExample();

    expect($config['default'])->toBe('reverb');

    // Backend publisher.
    expect($config['broadcasting'])->toMatchArray([
        'driver' => 'reverb',
        'key' => 'local-dev-key',
        'secret' => 'local-dev-secret',
        'app_id' => 'local-dev-app-id',
    ]);
    expect($config['broadcasting']['options'])->toMatchArray(['host' => 'localhost', 'scheme' => 'http', 'useTLS' => false]);
    expect((int) $config['broadcasting']['options']['port'])->toBe(8080);

    // The Reverb server's own app registry accepts exactly what the publisher sends.
    expect($config['app'])->toMatchArray([
        'key' => $config['broadcasting']['key'],
        'secret' => $config['broadcasting']['secret'],
        'app_id' => $config['broadcasting']['app_id'],
    ]);
    expect($config['app']['options'])->toMatchArray($config['broadcasting']['options']);

    // ...and the server listens on the port the publisher and browser target.
    expect($config['server']['hostname'])->toBe($config['broadcasting']['options']['host']);
    expect((int) $config['server']['port'])->toBe((int) $config['broadcasting']['options']['port']);
});

test('the browser variables resolve to the same public values the backend uses, and only the public key', function () {
    $example = envExampleValues();

    expect($example['VITE_REVERB_APP_KEY'])->toBe($example['REVERB_APP_KEY']);
    expect($example['VITE_REVERB_HOST'])->toBe($example['REVERB_HOST']);
    expect($example['VITE_REVERB_PORT'])->toBe($example['REVERB_PORT']);
    expect($example['VITE_REVERB_SCHEME'])->toBe($example['REVERB_SCHEME']);
    expect(collect($example)->filter(fn ($value, $name) => str_starts_with($name, 'VITE_') && $value === $example['REVERB_APP_SECRET'])->all())->toBe([]);
});

test('real environment variables still override the example values', function () {
    $config = configFromEnvExample([
        'BROADCAST_CONNECTION' => 'log',
        'REVERB_HOST' => 'reverb.internal.test',
        'REVERB_PORT' => '443',
        'REVERB_SCHEME' => 'https',
        'REVERB_APP_KEY' => 'production-generated-key',
    ]);

    expect($config['default'])->toBe('log');
    expect($config['broadcasting']['key'])->toBe('production-generated-key');
    expect($config['broadcasting']['options'])->toMatchArray(['host' => 'reverb.internal.test', 'scheme' => 'https', 'useTLS' => true]);
    expect((int) $config['broadcasting']['options']['port'])->toBe(443);
    expect($config['app']['key'])->toBe('production-generated-key');
    expect($config['app']['secret'])->toBe('local-dev-secret'); // untouched variables keep the example value
});

test('php artisan dev remains the mechanism that starts the Reverb server', function () {
    Artisan::call('dev:list');

    expect(Artisan::output())->toContain('php artisan reverb:start');
});
