<?php

/*
 * Reproduces the findings documented in doc/troballes.md. Each section prints its finding id.
 *
 * Run from the project root (needs the mysqli extension for A5):
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.5-cli \
 *       sh -c 'docker-php-ext-install mysqli >/dev/null 2>&1; php doc/repro/run.php'
 *
 * C3 (infinite loop) runs last because it ends the script with a fatal error.
 */

require __DIR__ . '/../../src/Autoloader.php';
Bitendian\TBP\Autoloader::register();

use Bitendian\TBP\Domain\AbstractSqliteDomain;
use Bitendian\TBP\Domain\Connection\Database\MssqlDatabaseConnection;
use Bitendian\TBP\Domain\Connection\Database\MysqlDatabaseConnection;
use Bitendian\TBP\REST\AbstractAPIRest;
use Bitendian\TBP\UI\AbstractWidget;
use Bitendian\TBP\UI\Templater;
use Bitendian\TBP\Utils\Config;
use Bitendian\TBP\Utils\Pagination;
use Bitendian\TBP\Utils\Router;

// print warnings and deprecations inline; header() warnings are a CLI artefact and are skipped
set_error_handler(function ($level, $message) {
    if (str_starts_with($message, 'Cannot modify header information')) {
        return true;
    }
    $name = [E_WARNING => 'Warning', E_DEPRECATED => 'Deprecated', E_NOTICE => 'Notice'][$level] ?? "E$level";
    echo "    [$name] $message\n";
    return true;
});

function section($id, callable $fn)
{
    echo "== $id\n";
    try {
        $fn();
    } catch (Throwable $e) {
        echo '    ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

class Api extends AbstractAPIRest
{
    protected function get(&$params)
    {
        return $params;
    }

    protected function put(&$params)
    {
    }

    protected function delete(&$params)
    {
    }

    protected function post(&$params)
    {
    }

    public function doRedirect()
    {
        $this->redirect('/x');
    }
}

echo 'PHP ', PHP_VERSION, "\n";

section('B10 Router::getRouteData() without load()', function () {
    var_dump(Router::getRouteData('/x', null));
});

section('C4 / M1 / A7 Config', function () {
    $config = (new Config(__DIR__ . '/cfg'))->getConfig('a');
    var_dump($config);
    $mysql = new MysqlDatabaseConnection((object)['use_associative_array' => $config->use_associative_array]);
    echo '    use_associative_array still enabled: ';
    var_dump((new ReflectionProperty($mysql, 'useAssociativeArrayWhenPossible'))->getValue($mysql));
});

section('C1 / C2 Templater XSS and template injection', function () {
    $context = new stdClass();
    $context->name = '<script>alert(1)</script> @@secret@@';
    $context->secret = 'DB_PASSWORD';
    echo '    ', new Templater(__DIR__ . '/tpl.html', $context);
});

section('B18 Templater with an associative array as context', function () {
    echo '    [', trim(new Templater(__DIR__ . '/tpl.html', ['name' => 'x'])), "]\n";
});

section('M2 Router treats "." as a regex wildcard', function () {
    Router::load([
        'feed' => (object)[
            'urls' => ['ca' => '/feed.xml', 'en' => '/items/{id}'],
            'layout' => 'layout',
            'module' => 'module',
        ],
    ]);
    echo '    /feedXxml matches /feed.xml: ';
    var_dump(Router::getRouteData('/feedXxml', null) !== false);
});

section('M3 Router does not encode params', function () {
    $params = ['id' => '1/../../admin?x=1'];
    echo '    ', Router::getRouteWithParams('feed', 'en', $params), "\n";
});

section('M6 output buffer left open when render() throws', function () {
    $widget = new class extends AbstractWidget {
        public function fetch(&$params)
        {
        }

        public function render()
        {
            echo 'partial';
            throw new RuntimeException('boom');
        }
    };
    $before = ob_get_level();
    try {
        (string)$widget;
    } catch (RuntimeException $e) {
    }
    $after = ob_get_level();
    $leaked = $after > $before ? ob_get_clean() : '';
    echo "    ob level before=$before after=$after, leaked buffer: '$leaked'\n";
});

section('B19 Pagination::getTotalPages() type', function () {
    $pagination = new Pagination(1, 10);
    $pagination->setTotalItems(25);
    var_dump($pagination->getTotalPages());
});

section('A1 / A2 / A6 MSSQL connection (SQLite PDO injected)', function () {
    $mssql = new MssqlDatabaseConnection(new stdClass());
    $pdo = new PDO('sqlite::memory:');
    (new ReflectionProperty($mssql, 'connection'))->setValue($mssql, $pdo);

    section('A6 select()', fn() => $mssql->select('SELECT 1 AS a'));
    section('A2 SQL error with ERRMODE_EXCEPTION (as set by open())', fn() => $mssql->command('SELECT * FROM missing'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    section('A2 SQL error with ERRMODE_SILENT (TBPException branch)', fn() => $mssql->command('SELECT * FROM missing'));
    section('A1 close()', fn() => $mssql->close());
});

section('A3 redirect() without status', function () {
    $_SERVER['REDIRECT_URL'] = '/api/items';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    (new Api())->doRedirect();
});

section('M8 strip_tags on GET params', function () {
    $_SERVER['REDIRECT_URL'] = '/api/items';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['q' => 'a<b and c>d', 'html' => '<b>hola</b>'];
    $api = new Api();
    $api->processAPI();
    var_dump($api->getResponseData());
});

section('M9 405 reason phrase changed globally', function () {
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    new Api();
    $status = (new ReflectionProperty(AbstractAPIRest::class, 'requestStatus'))->getValue();
    echo "    requestStatus[405] = '{$status[405]}'\n";
});

section('M7 request without REDIRECT_URL', function () {
    unset($_SERVER['REDIRECT_URL']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    new Api();
});

section('B11 SQLite binds every param as TEXT', function () {
    $domain = new class ((object)['filename' => ':memory:']) extends AbstractSqliteDomain {
        public function probe()
        {
            return self::getSingle($this->connection->select('SELECT typeof(?) AS type, ? + 0 = 1 AS numeric_ok', [1, 1]));
        }
    };
    $domain->open();
    var_dump($domain->probe());
});

section('A5 MySQL connection error', function () {
    if (!class_exists('mysqli')) {
        echo "    skipped: mysqli extension not available\n";
        return;
    }
    $mysql = new MysqlDatabaseConnection((object)[
        'server' => '127.0.0.1:1',
        'username' => 'user',
        'password' => 'password',
        'database' => 'db',
    ]);
    $mysql->open();
});

section('C3 Templater infinite loop (fatal, 3 s limit)', function () {
    $context = new stdClass();
    $context->name = '@@name@@';
    set_time_limit(3);
    echo new Templater(__DIR__ . '/tpl.html', $context);
});
