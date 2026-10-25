<?php

namespace Bitendian\TBP\Tests\Domain\Connection\Database;

use Bitendian\TBP\Domain\AbstractMysqlDomain;
use Bitendian\TBP\Domain\Connection\Database\MysqlDatabaseConnection;
use Bitendian\TBP\TBPException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Integration tests against a real MySQL-compatible server (MariaDB 11.8 in docker/test.compose.yaml).
 *
 * The server is read from TBP_TEST_MYSQL_* environment variables; without them every test is skipped.
 * Each test creates its tables and drops them afterwards.
 *
 * Tests in the 'known-issue' group assert the expected behaviour for problems documented in doc/troballes.md (the
 * finding id is in the test name). They fail with the current code, so test/phpunit.xml excludes them by default:
 * run them with 'bin/test --group known-issue'. Once a problem is fixed, remove the Group attribute.
 */
class MysqlDatabaseConnectionTest extends TestCase
{
    private MysqlDatabaseConnection $connection;

    protected function setUp(): void
    {
        if (getenv('TBP_TEST_MYSQL_SERVER') === false) {
            $this->markTestSkipped('TBP_TEST_MYSQL_SERVER not set: no MySQL/MariaDB server available');
        }

        $this->connection = new MysqlDatabaseConnection(self::config());
        $this->connection->open();
        $this->connection->command('DROP TABLE IF EXISTS items, people');
        $this->connection->command('
            CREATE TABLE items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                qty INT NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                note TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->command('DROP TABLE IF EXISTS items, people');
            $this->connection->close();
        }
    }

    private static function config(array $overrides = []): stdClass
    {
        return (object)array_merge([
            'server' => getenv('TBP_TEST_MYSQL_SERVER'),
            'database' => getenv('TBP_TEST_MYSQL_DATABASE'),
            'username' => getenv('TBP_TEST_MYSQL_USERNAME'),
            'password' => getenv('TBP_TEST_MYSQL_PASSWORD'),
        ], $overrides);
    }

    private function insertItems(string ...$names): void
    {
        foreach ($names as $name) {
            $this->connection->command('INSERT INTO items (name, qty) VALUES (?, ?)', [$name, 0]);
        }
    }

    #[Test]
    public function serverIsMariaDb(): void
    {
        $version = $this->connection->select('SELECT VERSION() AS version');
        $this->assertStringContainsString('MariaDB', $version[0]['version']);
    }

    #[Test]
    public function selectWithoutAutoincrementReturnsList(): void
    {
        $rows = $this->connection->select('SELECT 1 AS one, ? AS txt', ['a']);
        $this->assertSame([['one' => 1, 'txt' => 'a']], $rows);
    }

    #[Test]
    public function selectIndexesRowsByAutoincrementField(): void
    {
        $this->insertItems('a', 'b', 'c');

        $rows = $this->connection->select('SELECT id, name FROM items WHERE id > ?', [1]);
        $this->assertSame([2 => ['id' => 2, 'name' => 'b'], 3 => ['id' => 3, 'name' => 'c']], $rows);
    }

    #[Test]
    public function selectFallsBackToListWhenAutoincrementValuesRepeat(): void
    {
        $this->insertItems('a', 'b');

        $rows = $this->connection->select('SELECT i.id FROM items i CROSS JOIN items j ORDER BY i.id');
        $this->assertSame([['id' => 1], ['id' => 1], ['id' => 2], ['id' => 2]], $rows);
    }

    #[Test]
    public function useAssociativeArrayNoReturnsList(): void
    {
        $connection = new MysqlDatabaseConnection(self::config(['use_associative_array' => 'no']));
        $connection->open();
        $this->insertItems('a', 'b');

        $this->assertSame([['id' => 1], ['id' => 2]], $connection->select('SELECT id FROM items ORDER BY id'));
        $connection->close();
    }

    #[Test]
    public function stringIntAndNullParamsAreStored(): void
    {
        $this->connection->command(
            'INSERT INTO items (name, qty, note) VALUES (?, ?, ?), (?, ?, ?)',
            ['first', 3, null, 'second', 1, 'n']
        );

        $rows = $this->connection->select('SELECT name, qty, note FROM items WHERE qty > ?', [2]);
        $this->assertSame([['name' => 'first', 'qty' => 3, 'note' => null]], $rows);
    }

    #[Test]
    public function lastInsertIdReturnsAutoincrementValue(): void
    {
        $this->insertItems('a', 'b');
        $this->assertSame(2, $this->connection->lastInsertId());
    }

    #[Test]
    public function rollbackDiscardsAndCommitKeepsChanges(): void
    {
        $this->connection->begin();
        $this->insertItems('rolled back');
        $this->connection->rollback();

        $this->connection->begin();
        $this->insertItems('committed');
        $this->connection->commit();

        $this->assertSame([['name' => 'committed']], $this->connection->select('SELECT name FROM items'));
    }

    #[Test]
    public function getEnumValuesReturnsLabelsInOrder(): void
    {
        $this->connection->command("CREATE TABLE people (name VARCHAR(10), mood ENUM('sad', 'ok', 'happy'))");

        $this->assertSame(['sad', 'ok', 'happy'], $this->connection->getEnumValues('people', 'mood'));
    }

    #[Test]
    public function domainHelpersWorkWithMysql(): void
    {
        $domain = new class (self::config()) extends AbstractMysqlDomain {
            public function add(string $name): int|bool
            {
                return $this->insertWithAutoincrement(
                    $this->connection->command('INSERT INTO items (name, qty) VALUES (?, 0)', [$name])
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
        $this->assertEquals([1 => (object)['id' => 1, 'name' => 'a'], 2 => (object)['id' => 2, 'name' => 'b']], $domain->all());
        $this->assertEquals((object)['name' => 'b'], $domain->one(2));

        $domain->close();
    }

    #[Test]
    #[Group('known-issue')]
    public function a4SessionKeepsDefaultIsolationLevel(): void
    {
        $level = $this->connection->select('SELECT @@SESSION.transaction_isolation AS level');
        $this->assertSame('REPEATABLE-READ', $level[0]['level']);
    }

    #[Test]
    #[Group('known-issue')]
    public function a5SqlErrorsAreThrownAsTbpException(): void
    {
        $this->expectException(TBPException::class);
        $this->connection->select('SELECT * FROM missing_table');
    }

    #[Test]
    #[Group('known-issue')]
    public function a5WrongPasswordThrowsTbpExceptionOnOpen(): void
    {
        $connection = new MysqlDatabaseConnection(self::config(['password' => 'wrong']));

        $this->expectException(TBPException::class);
        $connection->open();
    }

    #[Test]
    #[Group('known-issue')]
    public function a7UseAssociativeArrayFalseReturnsList(): void
    {
        $connection = new MysqlDatabaseConnection(self::config(['use_associative_array' => false]));
        $connection->open();
        $this->insertItems('a', 'b');

        $this->assertSame([['id' => 1], ['id' => 2]], $connection->select('SELECT id FROM items ORDER BY id'));
        $connection->close();
    }

    #[Test]
    #[Group('known-issue')]
    public function m4Utf8mb4RoundTrip(): void
    {
        $text = 'àéïõ ñ ç 😀';
        $this->connection->command('INSERT INTO items (name, qty) VALUES (?, 0)', [$text]);
        $this->assertSame($text, $this->connection->select('SELECT name FROM items')[0]['name']);
    }

    #[Test]
    #[Group('known-issue')]
    public function b16BooleanParamsAreStored(): void
    {
        $this->connection->command('INSERT INTO items (name, qty, active) VALUES (?, ?, ?)', ['x', 0, false]);
        $this->assertSame(0, $this->connection->select('SELECT active FROM items')[0]['active']);
    }

    #[Test]
    #[Group('known-issue')]
    public function b20UsingAClosedConnectionThrowsTbpException(): void
    {
        $connection = new MysqlDatabaseConnection(self::config());

        $this->expectException(TBPException::class);
        $connection->select('SELECT 1');
    }
}
