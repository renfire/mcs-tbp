# TBP

*TBP* is a light framework designed to develop small and medium
websites. Focused in component oriented

## Requirements

PHP 8.5 or newer.

## Installation

```
composer require renfire/tbp
```

`renfire/tbp` is a fork of [`bitendian/tbp`](https://packagist.org/packages/bitendian/tbp) and keeps its
`Bitendian\TBP` namespace, so it is a drop-in replacement: change the package name in your `composer.json`. Both
packages cannot be installed at the same time.

## Running tests

Tests run inside Docker, so no local PHP installation is needed. Only Docker is required:

```
bin/test
```

The script builds the `docker/test.Dockerfile` image (PHP, Composer, PHPUnit 13, pcov, mysqli and pdo_pgsql), starts
PostgreSQL 17 and MariaDB 11.8 servers for the integration tests (`docker/test.compose.yaml`), runs PHPUnit with
`test/phpunit.xml` and removes the containers afterwards. Without those servers (e.g. running the image alone) the
PostgreSQL and MySQL integration tests are skipped; the SQLite ones use a temporary file and always run.

Tests for known problems that are not fixed yet (see `doc/troballes.md`) are in the `known-issue` group, excluded by
default. They are expected to fail until the problem is fixed:

```
bin/test --group known-issue
``` Any argument is passed to PHPUnit, and `PHP_VERSION` selects the `php` Docker image tag
(default: 8.5, the minimum supported):

```
bin/test --filter testEnvVar
bin/test --no-coverage
PHP_VERSION=8.5.10 bin/test
```

## Database interfaces

### SQLite
Connector to SQLite3. Works with prepared statements (mandatory). Support for transactions.

#### Installation
PHP extension ```ext-sqlite3``` required. Check ```phpinfo()```. By default, ```tbp-web-server``` Docker image supports SQLite.

Add to your ```composer.json```, at ```require``` section:
```
"ext-sqlite3": "*"
```


#### Configuration
Create a config file, like ```config/my-sqlite.config```
```
# TBP/SQLite config file.
# ALL PARAMETERS ARE OPTIONAL!

# Database file name (if not defined, will use 'default.db')
filename=resources/test.db

# Access type
# readOnly=yes

# Encryption key (if not defined, no encryption used)
# encryptionKey=
```
#### Implementation

Create a *Domain* extending ```AbstractSqliteDomain``` and pass your config file. Add one method for each needed query.
```php
class MyDomain extends AbstractSqliteDomain
{
    public function __construct()
    {
        $configReader = new Config(__CONFIG_PATH__);            // <-- get a config reader, passing your config folder
        $config = $configReader->getConfig('my-sqlite');        // <-- read your configuration, passing your config base name
        $config->filename =                                     // <-- convert your relative path into absolute path
            __BASE_PATH__ .
            DIRECTORY_SEPARATOR .
            $config->filename;
        parent::__construct($config);
        $this->open();                                          // <-- open a db connection and let's rock!
    }

    public function addRegister($a, $b)
    {
        $sql = "INSERT INTO `MyTableWithAutoInc` (`a`, `b`) VALUES (?, ?)";
        $params = array($a, $b);
        return self::insertWithAutoincrement($this->connection->command($sql, $params));
    }

    public function addAnotherRegister($a, $b)
    {
        $sql = "INSERT INTO `MyOtherTable` (`a`, `b`) VALUES (?, ?)";
        $params = array($a, $b);
        return $this->connection->command($sql, $params);
    }

    public function getRegisters($b)
    {
        $sql = "SELECT * FROM `MyTableWithAutoInc` WHERE `b` <= ?";
        $params = array($b);
        return self::getAll($this->connection->select($sql, $params));
    }

    public function getRegisterById($id)
    {
        $sql = "SELECT * FROM `MyTableWithAutoInc` WHERE `MyId` = ?";
        $params = array($id);
        return self::getSingle($this->connection->select($sql, $params));
    }
}
```
#### Usage
sample straight-forward code:
```php
$domain = new MyDomain();
$results = $domain->getRegisters(9391); // get an array
```
sample code with transactions:
```php
$domain = new MyDomain();
try {
    $domain->begin();
    
    $lastId = $domain->addRegister(1, 2);
    echo "first query: last inserted id: $lastId\n";
    
    $lastId = $domain->addRegister(3, 4);
    echo "second query: last inserted id: $lastId\n";
    
    $domain->commit();
} catch (Exception $e) {
    $domain->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
}
```

### PostgreSQL
Connector to PostgreSQL (developed and tested against PostgreSQL 17). Works with server-side prepared statements
(mandatory), using `?` placeholders like the other connectors, or `:name` placeholders with an associative params
array. Params are bound with their PHP type (`null`, `bool`, `int`, `string`). Support for transactions. Every error
is thrown as `TBPException`.

#### Installation
PHP extension ```ext-pdo_pgsql``` required. Check ```phpinfo()```.

Add to your ```composer.json```, at ```require``` section:
```
"ext-pdo_pgsql": "*"
```

#### Configuration
Create a config file, like ```config/my-postgres.config```
```
# TBP/PostgreSQL config file.

# Host name or socket directory (default: localhost) and port (default: 5432)
server=localhost
port=5432

database=my_database
username=my_user
# Values starting with '$' are read from an environment variable
password=$PGPASSWORD

# Optional: schema used as search_path
# schema=my_schema

# Optional: disable, allow, prefer, require, verify-ca, verify-full
# sslmode=require

# Optional: connection timeout in seconds, and name shown in pg_stat_activity
# connect_timeout=5
# application_name=my_app
```

#### Implementation

Create a *Domain* extending ```AbstractPostgresDomain``` and pass your config. PostgreSQL has no autoincrement:
use ```INSERT ... RETURNING``` to get generated ids, or ```insertWithAutoincrement``` for identity/serial columns.
```php
class MyDomain extends AbstractPostgresDomain
{
    public function __construct()
    {
        $configReader = new Config(__CONFIG_PATH__);
        parent::__construct($configReader->getConfig('my-postgres'));
        $this->open();
    }

    public function addRegister($a, $b)
    {
        $sql = 'INSERT INTO my_table (a, b) VALUES (?, ?) RETURNING id';
        return self::getSingle($this->connection->select($sql, array($a, $b)))->id;
    }

    public function getActiveRegisters($since)
    {
        $sql = 'SELECT * FROM my_table WHERE active = ? AND created_at >= ?';
        return self::getAll($this->connection->select($sql, array(true, $since)));
    }

    public function getMoods()
    {
        return $this->connection->getEnumValues('my_table', 'mood');  // labels of an enum column, in order
    }
}
```

Usage and transactions work as in the SQLite example. Rows are returned as a list (not indexed by primary key, unlike
the MySQL connector).
