<?php

namespace Bitendian\TBP\Tests\Domain\Connection\Database;

use Bitendian\TBP\Domain\AbstractPostgresDomain;
use Bitendian\TBP\Domain\Connection\Database\PostgresDatabaseConnection;
use Bitendian\TBP\TBPException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Integration tests against a real PostgreSQL server (17 in docker/test.compose.yaml).
 *
 * The server is read from TBP_TEST_PGSQL_* environment variables; without them every test is skipped.
 * Each test runs in its own schema, dropped afterwards.
 */
class PostgresDatabaseConnectionTest extends TestCase
{
    private const SCHEMA = 'tbp_test';

    private PostgresDatabaseConnection $connection;

    protected function setUp(): void
    {
        if (getenv('TBP_TEST_PGSQL_SERVER') === false) {
            $this->markTestSkipped('TBP_TEST_PGSQL_SERVER not set: no PostgreSQL server available');
        }

        $admin = new PostgresDatabaseConnection(self::config());
        $admin->open();
        $admin->command('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $admin->command('CREATE SCHEMA ' . self::SCHEMA);
        $admin->close();

        $this->connection = new PostgresDatabaseConnection(self::config(['schema' => self::SCHEMA]));
        $this->connection->open();
        $this->connection->command('
            CREATE TABLE items (
                id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name text NOT NULL,
                qty integer NOT NULL,
                active boolean NOT NULL,
                note text
            )');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->command('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
            $this->connection->close();
        }
    }

    private static function config(array $overrides = []): stdClass
    {
        return (object)array_merge([
            'server' => getenv('TBP_TEST_PGSQL_SERVER'),
            'port' => getenv('TBP_TEST_PGSQL_PORT') ?: 5432,
            'database' => getenv('TBP_TEST_PGSQL_DATABASE'),
            'username' => getenv('TBP_TEST_PGSQL_USERNAME'),
            'password' => getenv('TBP_TEST_PGSQL_PASSWORD'),
        ], $overrides);
    }

    #[Test]
    public function serverIsPostgres17OrNewer(): void
    {
        $version = $this->connection->select('SHOW server_version_num');
        $this->assertGreaterThanOrEqual(170000, (int)$version[0]['server_version_num']);
    }

    #[Test]
    public function selectReturnsListOfAssociativeRows(): void
    {
        $rows = $this->connection->select('SELECT 1 AS one, ? AS txt', ['a']);
        $this->assertSame([['one' => 1, 'txt' => 'a']], $rows);
    }

    #[Test]
    public function paramsAreBoundWithTheirPhpType(): void
    {
        $this->connection->command(
            'INSERT INTO items (name, qty, active, note) VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
            ['first', 3, true, null, 'second', 1, false, 'n']
        );

        $active = $this->connection->select('SELECT name, note FROM items WHERE active = ? AND qty > ?', [true, 2]);
        $this->assertSame([['name' => 'first', 'note' => null]], $active);

        $inactive = $this->connection->select('SELECT name, active FROM items WHERE active = ?', [false]);
        $this->assertSame([['name' => 'second', 'active' => false]], $inactive);
    }

    #[Test]
    public function namedParamsAreAccepted(): void
    {
        $rows = $this->connection->select('SELECT :b::int - :a::int AS diff', ['a' => 1, 'b' => 5]);
        $this->assertSame(4, $rows[0]['diff']);
    }

    #[Test]
    public function utf8RoundTrip(): void
    {
        $text = 'àéïõ ñ ç 😀';
        $this->connection->command('INSERT INTO items (name, qty, active) VALUES (?, 0, true)', [$text]);
        $this->assertSame($text, $this->connection->select('SELECT name FROM items')[0]['name']);
    }

    #[Test]
    public function lastInsertIdReturnsIdentityValue(): void
    {
        $this->connection->command("INSERT INTO items (name, qty, active) VALUES ('a', 0, true)");
        $this->connection->command("INSERT INTO items (name, qty, active) VALUES ('b', 0, true)");

        $this->assertSame(2, $this->connection->lastInsertId('items', 'id'));
        $this->assertSame(2, $this->connection->lastInsertId());
    }

    #[Test]
    public function insertReturningWorksWithSelect(): void
    {
        $rows = $this->connection->select(
            'INSERT INTO items (name, qty, active) VALUES (?, ?, ?) RETURNING id, name',
            ['x', 1, true]
        );
        $this->assertSame([['id' => 1, 'name' => 'x']], $rows);
    }

    #[Test]
    public function rollbackDiscardsAndCommitKeepsChanges(): void
    {
        $this->connection->begin();
        $this->connection->command("INSERT INTO items (name, qty, active) VALUES ('rolled back', 0, true)");
        $this->connection->rollback();

        $this->connection->begin();
        $this->connection->command("INSERT INTO items (name, qty, active) VALUES ('committed', 0, true)");
        $this->connection->commit();

        $this->assertSame([['name' => 'committed']], $this->connection->select('SELECT name FROM items'));
    }

    #[Test]
    public function getEnumValuesReturnsLabelsInOrder(): void
    {
        $this->connection->command("CREATE TYPE mood AS ENUM ('sad', 'ok', 'happy')");
        $this->connection->command('CREATE TABLE people (name text, mood mood)');

        $this->assertSame(['sad', 'ok', 'happy'], $this->connection->getEnumValues('people', 'mood'));
    }

    #[Test]
    public function sqlErrorsAreThrownAsTbpException(): void
    {
        try {
            $this->connection->select('SELECT * FROM missing_table');
            $this->fail('expected TBPException not thrown');
        } catch (TBPException $e) {
            $this->assertStringContainsString('42P01', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function dsnValuesWithSpacesAndQuotesAreQuoted(): void
    {
        $connection = new PostgresDatabaseConnection(self::config(['application_name' => "tbp 'test' app"]));
        $connection->open();

        $rows = $connection->select("SELECT current_setting('application_name') AS name");
        $this->assertSame("tbp 'test' app", $rows[0]['name']);
        $connection->close();
    }

    #[Test]
    public function wrongPasswordThrowsTbpExceptionOnOpen(): void
    {
        $connection = new PostgresDatabaseConnection(self::config(['password' => 'wrong']));

        $this->expectException(TBPException::class);
        $connection->open();
    }

    #[Test]
    public function usingAClosedConnectionThrowsTbpException(): void
    {
        $connection = new PostgresDatabaseConnection(self::config());

        $this->expectException(TBPException::class);
        $this->expectExceptionMessage('connection is not open');
        $connection->select('SELECT 1');
    }

    #[Test]
    public function domainHelpersWorkWithPostgres(): void
    {
        $domain = new class (self::config(['schema' => self::SCHEMA])) extends AbstractPostgresDomain {
            public function add(string $name): int|bool
            {
                return $this->insertWithAutoincrement(
                    $this->connection->command('INSERT INTO items (name, qty, active) VALUES (?, 0, true)', [$name])
                );
            }

            public function all(): array
            {
                return self::getAll($this->connection->select('SELECT id, name FROM items ORDER BY id'));
            }

            public function one(int $id): object|bool
            {
                return self::getSingle($this->connection->select('SELECT name FROM items WHERE id = ?', [$id]));
            }
        };
        $domain->open();

        $this->assertSame(1, $domain->add('a'));
        $this->assertSame(2, $domain->add('b'));
        $this->assertEquals([(object)['id' => 1, 'name' => 'a'], (object)['id' => 2, 'name' => 'b']], $domain->all());
        $this->assertEquals((object)['name' => 'b'], $domain->one(2));

        $domain->close();
    }
}
