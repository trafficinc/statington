<?php

declare(strict_types=1);

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Util/Sanitizer.php';
require __DIR__ . '/../src/Transport.php';
require __DIR__ . '/../src/Client.php';
require __DIR__ . '/../src/RequestContext.php';
require __DIR__ . '/../src/Database/PdoProxy.php';
require __DIR__ . '/../src/Database/StatementProxy.php';
require __DIR__ . '/../src/Span.php';
require __DIR__ . '/../src/Tracer.php';
require __DIR__ . '/../src/Logger.php';
require __DIR__ . '/../src/ErrorHandler.php';
require __DIR__ . '/../src/Statington.php';
require __DIR__ . '/../server/Database.php';
require __DIR__ . '/../server/EventValidator.php';
require __DIR__ . '/../server/RequestStore.php';

use Statington\Client;
use Statington\Config;
use Statington\Database\PdoProxy;
use Statington\Statington;
use Statington\RequestContext;
use Statington\Server\Database as ServerDatabase;
use Statington\Server\EventValidator;
use Statington\Server\RequestStore;
use Statington\Tracer;
use Statington\Util\Sanitizer;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$localConfigPath = dirname(__DIR__) . '/config/statington.local.php';
$localConfigBackup = is_file($localConfigPath) ? file_get_contents($localConfigPath) : false;
if ($localConfigBackup !== false) {
    unlink($localConfigPath);
}
register_shutdown_function(static function () use ($localConfigPath, $localConfigBackup): void {
    if ($localConfigBackup !== false) {
        file_put_contents($localConfigPath, $localConfigBackup);
    }
});

$config = new Config();
assert_true($config->get('app') === 'default', 'default app is default');
assert_true($config->get('environment') === (getenv('APP_ENV') ?: 'dev'), 'default environment uses APP_ENV or dev');
assert_true($config->get('capture_headers') === true, 'headers are captured by default');
assert_true($config->get('slow_request_ms') === 200, 'slow request threshold defaults to 200ms');
assert_true($config->get('redact_sensitive') === true, 'sensitive redaction is enabled by default');
assert_true($config->get('max_context_bytes') === 65536, 'context limit defaults to 65536');
assert_true($config->get('max_body_bytes') === 65536, 'body limit defaults to 65536');
assert_true($config->get('max_stacktrace_bytes') === 131072, 'stacktrace limit defaults to 131072');
assert_true(in_array('/favicon.ico', $config->get('ignore_paths'), true), 'static browser noise is ignored by default');
assert_true($config->dbOptions()['enabled'] === false, 'database impact is disabled by default');
assert_true($config->dbOptions()['driver'] === null, 'database impact driver is explicit by default');
assert_true($config->dbOptions()['track_mutations_only'] === true, 'database impact tracks mutations by default');
assert_true($config->dbOptions()['capture_source'] === true, 'database impact captures source by default');
assert_true($config->dbOptions()['source_root'] === null, 'database impact source root defaults to automatic');
assert_true(in_array('Statington\\', $config->dbOptions()['ignore_source_classes'], true), 'database impact ignores internal source classes by default');
assert_true(in_array('sessions', $config->dbOptions()['ignore_tables'], true), 'database impact ignores noisy tables by default');
assert_true(in_array('cache_*', $config->dbOptions()['ignore_tables'], true), 'database impact supports noisy table wildcard defaults');
assert_true($config->dbOptions()['slow_query_ms'] === 50, 'database impact slow query threshold defaults to 50ms');

try {
    file_put_contents($localConfigPath, "<?php\nreturn ['app' => 'local-app', 'slow_request_ms' => 321];\n");
    $localConfig = new Config();
    assert_true($localConfig->get('app') === 'local-app', 'local config overrides package defaults');
    assert_true($localConfig->get('slow_request_ms') === 321, 'local config values are normalized');
    $explicitLocalConfig = new Config(['app' => 'explicit-local-app']);
    assert_true($explicitLocalConfig->get('app') === 'explicit-local-app', 'explicit config overrides local config');
} finally {
    if (is_file($localConfigPath)) {
        unlink($localConfigPath);
    }
}

$originalCwd = getcwd();
$tmpConfigDir = sys_get_temp_dir() . '/statington-config-test-' . bin2hex(random_bytes(4));
mkdir($tmpConfigDir . '/config', 0777, true);
file_put_contents($tmpConfigDir . '/config/statington.php', "<?php\nreturn ['app' => 'file-app', 'endpoint' => 'http://localhost:9999', 'capture_headers' => false];\n");
chdir($tmpConfigDir);
$fileConfig = new Config();
assert_true($fileConfig->get('app') === 'file-app', 'app config file overrides package defaults');
assert_true($fileConfig->get('capture_headers') === false, 'app config booleans are normalized');
$explicitConfig = new Config(['app' => 'explicit-app']);
assert_true($explicitConfig->get('app') === 'explicit-app', 'explicit config overrides app config file');
chdir($originalCwd);

$ignoreConfig = new Config([
    'ignore_paths' => [
        '/favicon.ico',
        '/assets/*',
    ],
]);
assert_true($ignoreConfig->shouldIgnorePath('/favicon.ico'), 'ignored paths support exact matches');
assert_true($ignoreConfig->shouldIgnorePath('/assets/app.css'), 'ignored paths support wildcard matches');
assert_true(!$ignoreConfig->shouldIgnorePath('/users'), 'non-matching paths are not ignored');

$clean = Sanitizer::clean([
    'password' => 'secret',
    'nested' => [
        'api_key' => 'abc',
        'Authorization' => 'Bearer secret',
        'set-cookie' => 'session=abc',
        'profile' => ['ssn' => '123-45-6789'],
        'ok' => 'value',
    ],
]);
assert_true($clean['password'] === '[REDACTED]', 'password is redacted');
assert_true($clean['nested']['api_key'] === '[REDACTED]', 'api_key is redacted');
assert_true($clean['nested']['Authorization'] === '[REDACTED]', 'authorization is redacted');
assert_true($clean['nested']['set-cookie'] === '[REDACTED]', 'set-cookie is redacted');
assert_true($clean['nested']['profile']['ssn'] === '[REDACTED]', 'nested ssn is redacted');
assert_true($clean['nested']['ok'] === 'value', 'safe values pass through');

$notRedacted = Sanitizer::clean(['password' => 'secret'], ['redact_sensitive' => false]);
assert_true($notRedacted['password'] === 'secret', 'redaction can be disabled');

$truncated = Sanitizer::clean(['text' => str_repeat('a', 80)], ['max_context_bytes' => 32]);
assert_true(str_ends_with($truncated['text'], '... [truncated]'), 'long context strings are truncated');

$body = Sanitizer::capturedBody(
    '{"password":"secret","safe":"ok","nested":{"refresh_token":"abc"}}',
    'application/json',
    ['max_body_bytes' => 65536]
);
assert_true(is_array($body), 'json body is decoded');
assert_true($body['password'] === '[REDACTED]', 'json body password is redacted');
assert_true($body['nested']['refresh_token'] === '[REDACTED]', 'json body nested token is redacted');

$plainBody = Sanitizer::capturedBody(str_repeat('b', 80), 'text/plain', ['max_body_bytes' => 32]);
assert_true(is_string($plainBody) && str_ends_with($plainBody, '... [truncated]'), 'plain body is truncated');

$validator = new EventValidator();
assert_true($validator->validate(['type' => 'log'])['ok'] === true, 'valid collector event passes validation');
assert_true($validator->validate(['payload' => []])['error'] === 'missing_type', 'collector event requires type');
assert_true($validator->validate(['type' => 'bad type'])['error'] === 'invalid_type', 'collector event validates type shape');
assert_true($validator->validate(['type' => 'log', 'payload' => 'bad'])['error'] === 'invalid_payload', 'collector event validates payload shape');

$collector = new RequestStore((new ServerDatabase(':memory:'))->pdo());
$eventId = $collector->storeEvent([
    'type' => 'db_query',
    'request_id' => 'bad-db',
    'payload' => [
        'request_id' => 'bad-db',
        'operation' => 'SELECT',
        'tables' => 'not-array',
        'bindings' => 'not-array',
        'sql' => 'SELECT 1',
    ],
]);
assert_true($eventId > 0, 'collector stores raw event with malformed normalized fields');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/users?page=1';
$_SERVER['QUERY_STRING'] = 'page=1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Statington Test';
$_GET = ['page' => '1'];
$_POST = ['token' => 'secret-token'];

$request = new RequestContext(true, true);
$startPayload = $request->startPayload();
assert_true(strlen($request->id()) === 32, 'request id is generated');
assert_true($startPayload['method'] === 'CLI', 'CLI request method is captured');
assert_true($startPayload['path'] === basename((string) ($_SERVER['argv'][0] ?? 'cli')), 'CLI command path is captured');
assert_true($startPayload['post_params']['token'] === '[REDACTED]', 'post params are sanitized');

$client = new Client(new Config([
    'endpoint' => 'http://127.0.0.1:9',
    'enabled' => true,
]));
$tracer = new Tracer($client, $request);
$span = $tracer->startSpan('unit.test');
usleep(1000);
$span->end();
assert_true(true, 'span end does not throw when transport is unavailable');

$dbConfig = new Config([
    'db' => [
        'enabled' => true,
        'driver' => 'SQLite',
        'source_root' => __DIR__ . '/..',
        'capture_bindings' => true,
    ],
]);
assert_true($dbConfig->dbOptions()['enabled'] === true, 'database impact can be enabled');
assert_true($dbConfig->dbOptions()['driver'] === 'sqlite', 'database impact driver is normalized');
assert_true($dbConfig->dbOptions()['source_root'] === str_replace('\\', '/', dirname(__DIR__)), 'database impact source root is normalized');
assert_true($dbConfig->dbOptions()['capture_bindings'] === true, 'database impact can capture bindings');

$sanitizeBindings = new ReflectionMethod(Statington::class, 'sanitizeBindings');
$visibleBindings = $sanitizeBindings->invoke(null, ['password' => 'secret', 'id' => 42], [
    'capture_bindings' => true,
    'redact_bindings' => false,
], $dbConfig->sanitizerOptions());
assert_true($visibleBindings['password'] === 'secret', 'database bindings can be shown without redaction');
assert_true($visibleBindings['id'] === 42, 'database bindings keep non-sensitive values');

$redactedBindings = $sanitizeBindings->invoke(null, ['password' => 'secret', 'id' => 42], [
    'capture_bindings' => true,
    'redact_bindings' => true,
], $dbConfig->sanitizerOptions());
assert_true($redactedBindings['password'] === '[REDACTED]', 'database bindings are redacted when enabled');
assert_true($redactedBindings['id'] === '[REDACTED]', 'database binding redaction hides all binding values');

$shouldIgnoreTables = new ReflectionMethod(Statington::class, 'shouldIgnoreTables');
assert_true($shouldIgnoreTables->invoke(null, ['sessions'], ['sessions']) === true, 'database impact can ignore noisy tables');
assert_true($shouldIgnoreTables->invoke(null, ['main.sessions'], ['sessions']) === true, 'database impact ignores qualified noisy tables');
assert_true($shouldIgnoreTables->invoke(null, ['sessions_abc'], ['sessions*']) === true, 'database impact supports suffix table wildcards');
assert_true($shouldIgnoreTables->invoke(null, ['cache_items'], ['cache_*']) === true, 'database impact supports prefix table wildcards');
assert_true($shouldIgnoreTables->invoke(null, ['users'], ['sessions']) === false, 'database impact keeps non-noisy tables');

$querySource = new ReflectionMethod(Statington::class, 'querySource');
$source = $querySource->invoke(null, [
    'source_root' => dirname(__DIR__),
    'source_paths' => ['tests'],
    'ignore_source_paths' => [],
]);
assert_true(is_array($source) && ($source['file'] ?? null) === 'tests/run.php', 'database impact can capture query source file');
assert_true(is_int($source['line'] ?? null), 'database impact can capture query source line');
assert_true(($source['confidence'] ?? null) === 'source', 'database impact marks preferred source confidence');

$sqlite = new PdoProxy(new PDO('sqlite::memory:'));
$sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
$insert = $sqlite->prepare('INSERT INTO users (name) VALUES (:name)');
assert_true($insert !== false, 'sqlite wrapped prepare works');
$insert->execute(['name' => 'Ada']);
$select = $sqlite->query('SELECT id, name FROM users');
assert_true($select !== false && $select->fetch(PDO::FETCH_ASSOC)['name'] === 'Ada', 'sqlite wrapped query works');

if (getenv('STATINGTON_MYSQL_DSN')) {
    $mysql = new PdoProxy(new PDO(
        getenv('STATINGTON_MYSQL_DSN'),
        getenv('STATINGTON_MYSQL_USER') ?: null,
        getenv('STATINGTON_MYSQL_PASSWORD') ?: null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    ));
    $mysql->exec('CREATE TEMPORARY TABLE statington_impact_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
    $mysqlInsert = $mysql->prepare('INSERT INTO `statington_impact_test` (`name`) VALUES (:name)');
    assert_true($mysqlInsert !== false, 'mysql wrapped prepare works');
    $mysqlInsert->execute(['name' => 'Ada']);
    $mysqlSelect = $mysql->query('SELECT `id`, `name` FROM `statington_impact_test`');
    assert_true($mysqlSelect !== false && $mysqlSelect->fetch(PDO::FETCH_ASSOC)['name'] === 'Ada', 'mysql wrapped query works');
}

$ignoredScript = <<<'PHP'
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Util/Sanitizer.php';
require __DIR__ . '/src/Transport.php';
require __DIR__ . '/src/Client.php';
require __DIR__ . '/src/RequestContext.php';
require __DIR__ . '/src/Span.php';
require __DIR__ . '/src/Tracer.php';
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/ErrorHandler.php';
require __DIR__ . '/src/Database/PdoProxy.php';
require __DIR__ . '/src/Statington.php';
$_SERVER['REQUEST_URI'] = '/favicon.ico';
Statington\Statington::install(['ignore_paths' => ['/favicon.ico']]);
$value = Statington\Statington::span('ignored', static fn () => 'ok');
Statington\Statington::log('ignored');
if ($value !== 'ok' || Statington\Statington::requestId() !== null) {
    exit(1);
}
PHP;
$ignoredCommand = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($ignoredScript);
exec($ignoredCommand, $ignoredOutput, $ignoredCode);
assert_true($ignoredCode === 0, 'ignored requests stay silent and spans still execute');

echo "All simple tests passed.\n";
