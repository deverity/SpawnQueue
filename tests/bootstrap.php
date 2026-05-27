<?php

declare(strict_types=1);

/**
 * SpawnQueue test bootstrap.
 *
 * Resolves paths relative to plugins/SpawnQueue/tests/bootstrap.php:
 *   __DIR__              = .../plugins/SpawnQueue/tests
 *   dirname(__DIR__, 1)  = .../plugins/SpawnQueue
 *   dirname(__DIR__, 2)  = .../plugins
 *   dirname(__DIR__, 3)  = SIMPROS root (app root)
 */

$appRoot = dirname(__DIR__, 3);

// CakePHP required constants
define('ROOT',    $appRoot);
define('APP',     $appRoot . '/src/');
define('CONFIG',  $appRoot . '/config/');
define('DS',      DIRECTORY_SEPARATOR);
define('WWW_ROOT', $appRoot . '/webroot/');
define('TMP',     $appRoot . '/tmp/');
define('LOGS',    $appRoot . '/logs/');
define('CACHE',   $appRoot . '/tmp/cache/');

define('CAKE_CORE_INCLUDE_PATH', $appRoot . '/vendor/cakephp/cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE',    CORE_PATH . 'src' . DS);

require $appRoot . '/vendor/autoload.php';

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;

Configure::write('App.namespace', 'App');
Configure::write('debug', true);

// Default SpawnQueue config used by unit tests
Configure::write('SpawnQueue', [
    'poll_interval'        => 1,
    'shutdown_timeout'     => 5,
    'stuck_job_timeout'    => 300,
    'stuck_check_interval' => 60,
    'default_timeout'      => 30,
    'default_max_attempts' => 5,
    'queues' => [
        'default' => ['max_workers' => 2, 'timeout' => 30, 'max_attempts' => 3],
        'emails'  => ['max_workers' => 4, 'timeout' => 60, 'max_attempts' => 5],
        'fast'    => ['max_workers' => 8, 'timeout' => 10, 'max_attempts' => 2],
    ],
]);

// ── Integration test DB ────────────────────────────────────────────────────
// Integration tests are skipped automatically if this connection is not
// configured. Set DB_TEST_DSN in your environment to enable them:
//
//   export DB_TEST_DSN="mysql://root:@127.0.0.1/spawnqueue_test"
//   vendor/bin/phpunit --testsuite integration

$testDsn = getenv('DB_TEST_DSN');

if ($testDsn) {
    ConnectionManager::setConfig('test', [
        'url'      => $testDsn,
        'timezone' => 'UTC',
    ]);
} else {
    // Try to reuse the app's default connection if available.
    try {
        $appConfig = require $appRoot . '/config/app_local.php';
        $dbConfig  = $appConfig['Datasources']['default'] ?? null;

        if ($dbConfig) {
            // Point to a separate test database to avoid polluting production data.
            $dbConfig['database'] = ($dbConfig['database'] ?? 'simpros') . '_test';
            ConnectionManager::setConfig('test', $dbConfig);
        }
    } catch (\Throwable) {
        // No DB available — integration tests will be skipped.
    }
}
