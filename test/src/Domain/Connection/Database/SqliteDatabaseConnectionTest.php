<?php

namespace Bitendian\TBP\Tests\Domain\Connection\Database;

use Bitendian\TBP\Domain\AbstractSqliteDomain;
use Bitendian\TBP\Domain\Connection\Database\SqliteDatabaseConnection;
use Bitendian\TBP\TBPException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SQLite3;
use stdClass;

/**
 * Integration tests against SQLite3. No server is needed: each test uses its own database file in the temporary
 * directory, deleted afterwards. Skipped when the sqlite3 extension is not loaded.
 *
 * Tests in the 'known-issue' group assert the expected behaviour for problems documented in doc/troballes.md (the
 * finding id is in the test name). They fail with the current code, so test/phpunit.xml excludes them by default:
 * run them with 'bin/test --group known-issue'. Once a problem is fixed, remove the Group attribute.
 */
class SqliteDatabaseConnectionTest extends TestCase
{
    private string $filename;

    private SqliteDatabaseConnection $connection;

    protected function setUp(): void
    {
        if (!class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }

        $this->filename = tempnam(sys_get_temp_dir(), 'tbp-sqlite-');
        $this->connection = new SqliteDatabaseConnection(self::config($this->filename));
        $this->connection->open();
        $this->connection->command('
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                qty INTEGER NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                note TEXT
            )');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
        if (isset($this->filename) && is_file($this->filename)) {
            unlink($this->filename);
        }
    }

    private static function config(string $filename, array $overrides = []): stdClass
    {
        return (object)array_merge(['filename' => $filename], $overrides);
    }

    private function insertItems(string ...$names): void
    {
        foreach ($names as $name) {
            $this->connection->command('INSERT INTO items (name, qty) VALUES (?, ?)', [$name, 0]);
        }
    }

    /**
     * Runs $fn collecting the PHP warnings it emits instead of letting them reach PHPUnit (see b21).
     * @return string[] warning messages
     */
    private static function collectWarnings(callable $fn): array
    {
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        }, E_WARNING);

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    #[Test]
    public function selectReturnsListOfAssociativeRows(): void
    {
        $rows = $this->connection->select('SELECT 1 AS one, ? AS txt', ['a']);
        $this->assertSame([['one' => 1, 'txt' => 'a']], $rows);
    }

    #[Test]
    public function paramsAreConvertedByColumnAffinity(): void
    {
        $this->connection->command(
            'INSERT INTO items (name, qty, note) VALUES (?, ?, ?), (?, ?, ?)',
            ['first', 3, null, 'second', 1, 'n']
        );

        $rows = $this->connection->select('SELECT name, qty, typeof(qty) AS type, note FROM items WHERE qty > ?', [2]);
        $this->assertSame([['name' => 'first', 'qty' => 3, 'type' => 'integer', 'note' => null]], $rows);
    }

    #[Test]
    public function utf8RoundTrip(): void
    {
        $text = 'àéïõ ñ ç 😀';
        $this->connection->command('INSERT INTO items (name, qty) VALUES (?, 0)', [$text]);
        $this->assertSame($text, $this->connection->select('SELECT name FROM items')[0]['name']);
    }

    #[Test]
    public function lastInsertIdReturnsRowId(): void
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
    public function dataPersistsInTheDatabaseFile(): void
    {
        $this->insertItems('a');
        $this->connection->close();

        $other = new SqliteDatabaseConnection(self::config($this->filename));
        $other->open();
        $this->assertSame([['name' => 'a']], $other->select('SELECT name FROM items'));
        $other->close();
    }

    #[Test]
    public function readOnlyConnectionRejectsWrites(): void
    {
        $readOnly = new SqliteDatabaseConnection(self::config($this->filename, ['readOnly' => 'yes']));
        $readOnly->open();

        try {
            self::collectWarnings(fn() => $readOnly->command("INSERT INTO items (name, qty) VALUES ('x', 0)"));
            $this->fail('expected TBPException not thrown');
        } catch (TBPException $e) {
            $this->assertStringContainsString('readonly database', $e->getMessage());
        } finally {
            $readOnly->close();
        }
    }

    #[Test]
    public function sqlErrorsAreThrownAsTbpException(): void
    {
        try {
            self::collectWarnings(fn() => $this->connection->select('SELECT * FROM missing_table'));
            $this->fail('expected TBPException not thrown');
        } catch (TBPException $e) {
            $this->assertSame('no such table: missing_table', $e->getMessage());
            $this->assertSame(1, $e->getCode());
        }
    }

    #[Test]
    public function getEnumValuesIsNotImplemented(): void
    {
        $this->expectException(TBPException::class);
        $this->expectExceptionMessage('not implemented');
        $this->connection->getEnumValues('items', 'name');
    }

    #[Test]
    public function closingAConnectionThatWasNeverOpenedDoesNothing(): void
    {
        (new SqliteDatabaseConnection(self::config($this->filename)))->close();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function domainHelpersWorkWithSqlite(): void
    {
        $domain = new class (self::config($this->filename)) extends AbstractSqliteDomain {
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
        $this->assertEquals([(object)['id' => 1, 'name' => 'a'], (object)['id' => 2, 'name' => 'b']], $domain->all());
        $this->assertEquals((object)['name' => 'b'], $domain->one(2));

        $domain->close();
    }

    #[Test]
    #[Group('known-issue')]
    public function b11IntegerParamsAreBoundAsIntegers(): void
    {
        $rows = $this->connection->select('SELECT ? = 1 AS equal, typeof(?) AS type', [1, 1]);
        $this->assertSame([['equal' => 1, 'type' => 'integer']], $rows);
    }

    #[Test]
    #[Group('known-issue')]
    public function b11BooleanParamsAreStoredAsIntegers(): void
    {
        $this->connection->command('INSERT INTO items (name, qty, active) VALUES (?, ?, ?)', ['x', 0, false]);

        $rows = $this->connection->select('SELECT active, typeof(active) AS type FROM items');
        $this->assertSame([['active' => 0, 'type' => 'integer']], $rows);
    }

    #[Test]
    #[Group('known-issue')]
    public function b11BusyTimeoutIsSet(): void
    {
        $timeout = $this->connection->select('PRAGMA busy_timeout');
        $this->assertGreaterThan(0, $timeout[0]['timeout']);
    }

    #[Test]
    #[Group('known-issue')]
    public function b11OpenErrorsAreThrownAsTbpException(): void
    {
        $connection = new SqliteDatabaseConnection(self::config('/nonexistent/directory/tbp.db'));

        $this->expectException(TBPException::class);
        $connection->open();
    }

    #[Test]
    #[Group('known-issue')]
    public function b20UsingAClosedConnectionThrowsTbpException(): void
    {
        $connection = new SqliteDatabaseConnection(self::config($this->filename));

        $this->expectException(TBPException::class);
        $connection->select('SELECT 1');
    }

    #[Test]
    #[Group('known-issue')]
    public function b21SqlErrorsDoNotEmitPhpWarnings(): void
    {
        $warnings = self::collectWarnings(function () {
            try {
                $this->connection->select('SELECT * FROM missing_table');
            } catch (TBPException) {
            }
        });

        $this->assertSame([], $warnings);
    }
}
