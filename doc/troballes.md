# Troballes

Cada troballa indica el fitxer i la línia, què falla, en quin escenari i com es pot corregir.

- **Confirmada**: reproduïda executant el codi amb `php:8.3-cli` (vegeu [Reproducció](#reproducció)).
- **Per lectura**: detectada llegint el codi; no s'ha executat.

## Crítica

### C1. El `Templater` no escapa els valors (XSS) — *confirmada*

[`src/UI/Templater.php:159-174`](../src/UI/Templater.php#L159)

Els valors del context s'insereixen a l'HTML tal qual. Qualsevol dada d'usuari que es mostri amb `@@...@@` permet
injectar HTML i JavaScript.

- **Escenari**: `$o->name = '<script>alert(1)</script>'` amb la plantilla `Hola @@name@@` genera
  `Hola <script>alert(1)</script>`.
- **Correcció**: escapar per defecte amb `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` i oferir
  una sintaxi explícita per a HTML sense escapar (per exemple per a subcomponents, que ja retornen HTML via
  `__toString`). Cal decidir com es distingeixen els dos casos, perquè els components niats depenen del comportament
  actual.

### C2. El `Templater` torna a processar el text substituït (injecció de plantilles) — *confirmada*

[`src/UI/Templater.php:95-102`](../src/UI/Templater.php#L95), [`:159-189`](../src/UI/Templater.php#L159)

Les tres fases fan `while (preg_match(...)) str_replace(...)` sobre el resultat ja substituït. Si un valor conté una
marca, aquesta també es resol.

- **Escenari**: un usuari posa `@@secret@@` com a nom. La plantilla `Hola @@name@@` mostra el valor de la propietat
  `secret` del context. Ho he provat i surt `Hola ... DB_PASSWORD`. També passa entre fases: un valor de `%%...%%`
  que contingui `@@x@@` es resol a la fase 3.
- **Correcció**: fer una sola passada amb `preg_replace_callback` sobre el text original de cada fase, de manera que
  el text substituït no es torni a analitzar.

### C3. Bucle infinit al `Templater` — *confirmada*

Mateixa causa que C2. Si un valor conté la seva pròpia marca (`name = "@@name@@"`), el `while` no acaba mai i la
petició esgota `max_execution_time`. Passa el mateix si una traducció de `gettext` conté `##...##`.

- **Correcció**: la mateixa que C2.

### C4. Els valors de `Config` que comencen per `$` es perden — *confirmada*

[`src/Utils/Config.php:108-110`](../src/Utils/Config.php#L108), [`:124-131`](../src/Utils/Config.php#L124)

Qualsevol valor que comenci per `$` es tracta com a variable d'entorn. Si la variable no existeix, el valor passa a
ser una cadena buida, sense cap avís.

- **Escenari**: `password = $ecret123` → `$config->password === ''`. La connexió a la base de dades falla amb un
  error d'autenticació difícil de relacionar amb el fitxer de configuració.
- **Correcció**: fer servir una sintaxi menys ambigua (`${VAR}` o `${VAR:-defecte}`), o permetre escapar el `$`
  (`$$`), i llançar una excepció o un avís si la variable no existeix i no té valor per defecte.

## Alta

### A1. `MssqlDatabaseConnection::close()` sempre provoca un error fatal — *per lectura*

[`src/Domain/Connection/Database/MssqlDatabaseConnection.php:78-83`](../src/Domain/Connection/Database/MssqlDatabaseConnection.php#L78)

`PDO` no té cap mètode `close()`. Cridar `$domain->close()` dona `Error: Call to undefined method PDO::close()`.

- **Correcció**: `$this->connection = null;`.

### A2. Les excepcions de MSSQL provoquen `TypeError` — *per lectura*

[`src/Domain/Connection/Database/MssqlDatabaseConnection.php:70`](../src/Domain/Connection/Database/MssqlDatabaseConnection.php#L70)
i totes les crides `new TBPException($this->connection->errorInfo(), $this->connection->errorCode())`.

`errorInfo()` retorna un array i `errorCode()` una cadena SQLSTATE (`'HY000'`). `Exception` exigeix un missatge
`string` i un codi `int`, així que a PHP 8 es llança un `TypeError` en lloc de la `TBPException` esperada. A més,
amb `PDO::ERRMODE_EXCEPTION` activat (línia 56), aquestes branques no s'executen mai: PDO ja llança `PDOException`.

- **Correcció**: capturar `PDOException` i reenviar-la com a `TBPException($e->getMessage(), (int) $e->getCode(), $e)`.

### A3. `redirect()` fa servir una constant que no existeix — *per lectura*

[`src/REST/AbstractAPIRest.php:201`](../src/REST/AbstractAPIRest.php#L201)

`HTTP_REDIRECT_POST` només la defineix l'extensió PECL `pecl_http`. Sense aquesta extensió, cridar `redirect()` sense
segon argument dona `Error: Undefined constant`.

- **Correcció**: `$status = 303`.

### A4. MySQL fa servir `READ UNCOMMITTED` per a totes les connexions — *per lectura*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:77`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L77)

Cada `open()` executa `SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED`. Totes les lectures de l'aplicació
poden veure dades no confirmades d'altres transaccions (lectures brutes), que després poden desfer-se amb un
rollback.

- **Correcció**: no canviar el nivell d'aïllament per defecte (InnoDB fa servir `REPEATABLE READ`) o fer-lo
  configurable.

### A5. MySQL no detecta errors de connexió — *per lectura*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:70-74`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L70)

`new mysqli()` no retorna mai `false`, així que la comprovació `!($this->connection = ...)` no salta mai. Abans de
PHP 8.1, una connexió fallida retorna un objecte amb `connect_error` i el codi continua fins que falla més endavant
amb un error confús. Des de PHP 8.1, mysqli llança `mysqli_sql_exception` per defecte, que no és `TBPException`.

- **Correcció**: comprovar `$this->connection->connect_errno` i/o capturar `mysqli_sql_exception`.

### A6. MSSQL fa servir una constant de l'extensió `sqlsrv` amb PDO — *per lectura*

[`src/Domain/Connection/Database/MssqlDatabaseConnection.php:103`](../src/Domain/Connection/Database/MssqlDatabaseConnection.php#L103)

`$statement->fetch(SQLSRV_FETCH_ASSOC)` funciona per casualitat (val 2, igual que `PDO::FETCH_ASSOC`) només si
l'extensió `sqlsrv` està carregada. Si només hi ha `pdo_sqlsrv`, dona `Error: Undefined constant`.

- **Correcció**: `PDO::FETCH_ASSOC` (o `fetchAll(PDO::FETCH_ASSOC)`).

### A7. `use_associative_array=false` no desactiva l'opció — *confirmada*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:48-52`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L48)

`Config` converteix `false` en un booleà. `strtolower(false)` dona `''`, i `'' != 'no'` és cert, així que l'opció
queda activada. Només funciona si s'escriu `no`.

- **Correcció**: acceptar booleans (`$value === false || strtolower($value) === 'no'`). Unificar també el criteri
  amb SQLite, que espera `readOnly=yes`.

### A8. `lastInsertId` de MSSQL: injecció SQL i valor incorrecte amb triggers — *per lectura*

[`src/Domain/Connection/Database/MssqlDatabaseConnection.php:143-155`](../src/Domain/Connection/Database/MssqlDatabaseConnection.php#L143)

`$table` s'interpola directament a l'SQL (`IDENT_CURRENT('$table')`). A més, `@@IDENTITY` retorna l'última identitat
de la sessió, incloses les generades per triggers en altres taules. `IDENT_CURRENT` no està limitat a la sessió i
pot retornar l'ID d'una altra connexió.

- **Correcció**: `PDO::lastInsertId()`, o afegir `OUTPUT INSERTED.id` / `SELECT SCOPE_IDENTITY()` a la mateixa
  sentència de l'insert.

## Mitjana

### M1. `Config` no elimina comentaris al final de línia i treu les cometes — *confirmada*

[`src/Utils/Config.php:24`](../src/Utils/Config.php#L24), [`:98-122`](../src/Utils/Config.php#L98)

El comentari de la classe diu que els comentaris al final de línia estan permesos, però només s'ignoren les línies
que comencen per `#`. A més, `fgetcsv` interpreta `"` com a delimitador i `\` com a escapament.

- **Escenari**: `foo = bar # comment` → `"bar # comment"`. `quoted = "abc"` → `abc`.
- **Correcció**: llegir línia a línia amb `fgets`, separar pel primer `=` i decidir explícitament com es tracten `#`
  i les cometes (i actualitzar el comentari de la classe).

### M2. Les URLs de les rutes s'interpreten com a regex — *per lectura*

[`src/Utils/Router.php:40-50`](../src/Utils/Router.php#L40)

El patró de la ruta es posa dins de `'*^' . $pregUrl . '$*i'` sense `preg_quote`. Un `.` coincideix amb qualsevol
caràcter, `+`, `?`, `(` canvien el significat i un `*` trenca el delimitador.

- **Escenari**: la ruta `/feed.xml` també coincideix amb `/feedXxml`.
- **Correcció**: aplicar `preg_quote($url, '#')` abans de substituir les variables `{nom}` i fer servir un delimitador
  que no aparegui a les URLs.

### M3. El `Router` no codifica els paràmetres de les URLs — *per lectura*

[`src/Utils/Router.php:99-101`](../src/Utils/Router.php#L99), [`:142-147`](../src/Utils/Router.php#L142)

`getRoute()` i `getRouteWithParams()` insereixen els valors directament. Un paràmetre amb `/`, `?`, `#` o espais
genera una URL trencada o que apunta a una altra ruta.

- **Correcció**: `rawurlencode()` de cada valor.

### M4. MySQL fa servir `utf8` en lloc d'`utf8mb4` — *per lectura*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:76`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L76)

`utf8` és `utf8mb3` (màxim 3 bytes per caràcter). Els emojis i altres caràcters fora del pla bàsic no es poden
desar.

- **Correcció**: `set_charset('utf8mb4')`, o fer-ho configurable.

### M5. Els errors de sentència de MySQL es llegeixen de la connexió — *per lectura*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:149-151`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L149),
[`:215-217`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L215)

Quan `$statement->execute()` falla, l'error és a `$statement->error`, no a `$this->connection->error`. El missatge
de l'excepció pot quedar buit.

- **Correcció**: `throw new TBPException($statement->error, $statement->errno)`.

### M6. `__toString()` deixa el buffer de sortida obert si `render()` falla — *per lectura*

[`src/UI/AbstractRenderizable.php:30-41`](../src/UI/AbstractRenderizable.php#L30)

Si `render()` llança una excepció, `ob_end_clean()` no s'executa i el buffer queda obert, cosa que barreja sortida
parcial amb la pàgina d'error. Si `ob_start()` falla, `__toString()` retorna `null`, que a PHP 8 és un `TypeError`.

- **Correcció**: `try { ... } finally { ob_end_clean(); }` i retornar sempre una cadena.

### M7. L'API REST depèn de `REDIRECT_URL` (només Apache) — *per lectura*

[`src/REST/AbstractAPIRest.php:109`](../src/REST/AbstractAPIRest.php#L109)

`$_SERVER['REDIRECT_URL']` només el defineix `mod_rewrite` d'Apache. Amb nginx + PHP-FPM o el servidor integrat de
PHP no existeix: avís d'índex no definit i, a PHP 8.1+, `trim(null)` obsolet.

- **Correcció**: `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)`.

### M8. `strip_tags` només als paràmetres GET — *per lectura*

[`src/REST/AbstractAPIRest.php:229-242`](../src/REST/AbstractAPIRest.php#L229)

`cleanInputs()` altera dades legítimes (`a<b` es trunca) i dona una falsa sensació de seguretat, però no s'aplica al
cos de POST/PUT/DELETE. La protecció contra XSS correspon a la sortida, no a l'entrada.

- **Correcció**: no modificar l'entrada; escapar a la sortida (vegeu C1).

### M9. El missatge d'estat HTTP és global i pot incloure dades de la petició — *per lectura*

[`src/REST/AbstractAPIRest.php:133`](../src/REST/AbstractAPIRest.php#L133), [`:347-362`](../src/REST/AbstractAPIRest.php#L347)

`setResponseStatus(405, 'invalid method: ' . $this->method)` modifica l'array estàtic `$requestStatus`. El mètode el
controla el client, i el canvi afecta totes les instàncies posteriors del mateix procés. `header()` bloqueja salts
de línia, però el text arriba a la línia d'estat.

- **Correcció**: no modificar el mapa estàtic per a missatges puntuals; fer servir `http_response_code()`.

### M10. `store()` de Redis amb TTL no és atòmic — *per lectura*

[`src/Domain/Connection/Cache/RedisCacheConnection.php:93-100`](../src/Domain/Connection/Cache/RedisCacheConnection.php#L93)

`SET` i `EXPIRE` són dues ordres. Si el procés cau entre totes dues, la clau queda sense caducitat.

- **Correcció**: `$this->connection->set($key, $value, 'EX', $ttl)`.

### M11. L'acció d'`AbstractComponent` exposa el nom de la classe — *per lectura*

[`src/UI/AbstractComponent.php:42-55`](../src/UI/AbstractComponent.php#L42)

El formulari envia el nom de la classe en base64. Si l'aplicació fa `new (AbstractComponent::actionDecode($post))`,
un atacant pot instanciar qualsevol classe carregable. El framework no ho fa, però el disseny ho afavoreix.

- **Correcció**: documentar que cal validar l'acció contra una llista de components permesos, o fer servir
  identificadors registrats en lloc del nom de la classe.

## Baixa i deute tècnic

| # | On | Problema |
|---|---|---|
| B1 | [`composer.json`](../composer.json) | Exigeix `php >=5.3.2`, però el codi fa servir sintaxi de 5.4+ (`[]`, `Closure::bindTo`). *Parcialment resolt:* s'ha tret el `"php": "^7.3"` de `require-dev`, que impedia instal·lar les dependències amb PHP 8. |
| B2 | [`composer.json`](../composer.json), [`LICENSE`](../LICENSE) | `composer.json` diu MIT; `LICENSE` i els comentaris d'`Autoloader.php` diuen Apache 2.0. |
| B3 | [`composer.json`](../composer.json) | El namespace dels tests apunta a `tests/src/` (la carpeta és `test/src/`) i és a `autoload` en lloc d'`autoload-dev`. |
| B4 | [`composer.json`](../composer.json) | Les paraules clau esmenten Postgres i Memcached, que no estan implementats. `minimum-stability` no té efecte en una llibreria. |
| B5 | [`test/phpunit.xml`](../test/phpunit.xml) | ~~Esquema antic (`blacklist`/`whitelist`, `<log>`), incompatible amb PHPUnit 9.3+.~~ *Resolt:* migrat a l'esquema de PHPUnit 9.6. |
| B6 | [`ConfigTest.php:78-93`](../test/src/Utils/Config/ConfigTest.php#L78) | ~~`testEnvVar` necessita `TEST_ENV_VAR` i `TEST_ENV_VAR2` definides a l'entorn i no es defineixen enlloc.~~ *Resolt:* definides a `<php><env>` de `phpunit.xml`; confirmat que el test fallava sense elles. |
| B7 | [`AbstractAPIRest.php:146`](../src/REST/AbstractAPIRest.php#L146), [`:159`](../src/REST/AbstractAPIRest.php#L159) | `libxml_disable_entity_loader` està obsoleta des de PHP 8.0. Els dos analitzadors XML són idèntics. |
| B8 | [`SqliteDatabaseConnection.php:126-136`](../src/Domain/Connection/Database/SqliteDatabaseConnection.php#L126) | `convertQuestionMarksIntoIds` no es fa servir. `getEnumValues` no està implementat a MSSQL ni a SQLite. |
| B9 | [`RedisCacheConnection.php:141-144`](../src/Domain/Connection/Cache/RedisCacheConnection.php#L141) | `setCardinality()` no retorna res. `close()` no desconnecta. `AbstractCacheDomain` no té `open()`/`close()` com el domini de base de dades. |
| B10 | [`Router.php`](../src/Utils/Router.php) | Si no s'ha cridat `load()`, `getRouteData()` recorre `null`. Fa servir `_()` (requereix l'extensió gettext) de manera inconsistent. No distingeix entre ruta inexistent i rol no permès. |
| B11 | [`SqliteDatabaseConnection.php`](../src/Domain/Connection/Database/SqliteDatabaseConnection.php) | Tots els paràmetres es vinculen com a `SQLITE3_TEXT`. No s'estableix `busyTimeout` (errors "database is locked" amb concurrència). `encryptionKey` només funciona amb compilacions SEE/SQLCipher. La comprovació d'error d'`open()` no s'executa mai. |
| B12 | `Utils/*` | Estat global estàtic a `Router`, `HttpHeaders`, `HtmlHeaders`, `SystemMessages` i `Config`: problemes en entorns de llarga durada (Swoole, RoadRunner) i en tests. |
| B13 | [`SystemMessages.php:98-116`](../src/Utils/SystemMessages.php#L98) | `unserialize` sense `allowed_classes`; si es passa un array que no sigui la sessió, permet injecció d'objectes. El comentari de la classe parla de claus amb microsegons que no existeixen. |
| B14 | [`Config.php:66-77`](../src/Utils/Config.php#L66), [`:184-188`](../src/Utils/Config.php#L184) | Falta `closedir`. Un fitxer anomenat `0` atura `readdir`. `setConfigFileExtension` recarrega la cau compartida per a totes les instàncies. |
| B15 | [`MysqlDatabaseConnection.php:237-259`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L237) | `autocommit(false)` + `begin_transaction()` és redundant. `commit()`/`rollback()` no comproven el resultat. |
| B16 | [`MysqlDatabaseConnection.php:139-147`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L139) | Els paràmetres han de ser una llista indexada des de 0 i tots es vinculen com a `s`. Els resultats s'indexen per l'autoincrement, de manera que `$rows[0]` no funciona. |
| B17 | [`AbstractAPIRest.php:113-121`](../src/REST/AbstractAPIRest.php#L113), [`:404-419`](../src/REST/AbstractAPIRest.php#L404) | Els quatre mètodes són abstractes, així que la comprovació `method_exists` de `processAPI()` és inútil. Fa servir `X-HTTP-Method` en lloc de l'habitual `X-HTTP-Method-Override`. |
| B18 | [`Templater.php:64-75`](../src/UI/Templater.php#L64) | Un array associatiu com a context es tracta com una llista de contextos: `new Templater($f, ['name' => 'x'])` no substitueix `@@name@@`. La comprovació `$this->context !== null` de `replace()` sempre és certa. |
| B19 | [`Pagination.php:59`](../src/Utils/Pagination.php#L59) | `ceil()` retorna `float`; `getTotalPages()` retorna `3.0` en lloc de `3`. |

## Reproducció

Proves executades amb `php:8.3-cli` des d'una carpeta temporal, muntant `src/` en només lectura.

`cfg/a.config`:

```
inline = bar # comment
password = $ecret123
quoted = "abc"
use_associative_array = false
```

`tpl.html`:

```
Hola @@name@@
```

`run.php`:

```php
<?php
require '/src/Autoloader.php';
Bitendian\TBP\Autoloader::register();

use Bitendian\TBP\Utils\Config;
use Bitendian\TBP\UI\Templater;

$c = (new Config(__DIR__ . '/cfg'))->getConfig('a');
var_dump($c);
var_dump(!isset($c->use_associative_array) || strtolower($c->use_associative_array) != 'no');

$o = new stdClass;
$o->name = '<script>alert(1)</script> @@secret@@';
$o->secret = 'DB_PASSWORD';
echo new Templater(__DIR__ . '/tpl.html', $o), "\n";

$o2 = new stdClass;
$o2->name = '@@name@@';
set_time_limit(3);
echo new Templater(__DIR__ . '/tpl.html', $o2), "\n";
```

```bash
docker run --rm -v "$PWD":/w -v /ruta/a/mcs-tbp/src:/src:ro -w /w php:8.3-cli php run.php
```

Sortida (abreujada i anotada amb la troballa corresponent):

```
object(stdClass)#3 (4) {
  ["inline"]=>   string(13) "bar # comment"     <- M1
  ["password"]=> string(0) ""                   <- C4
  ["quoted"]=>   string(3) "abc"               <- M1
  ["use_associative_array"]=> bool(false)
}
bool(true)                                      <- A7
Hola <script>alert(1)</script> DB_PASSWORD      <- C1, C2

Fatal error: Maximum execution time of 3 seconds exceeded in /src/UI/Templater.php on line 125   <- C3
```
