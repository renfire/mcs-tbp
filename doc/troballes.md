# Troballes

Cada troballa indica el fitxer i la línia, què falla, en quin escenari i com es pot corregir.

- **Confirmada**: reproduïda executant el codi amb PHP 8.5.12 (`php:8.5-cli`), la versió mínima del projecte, amb
  l'script de reproducció o, per a MySQL, amb els tests del grup `known-issue` contra MariaDB 11.8. Vegeu
  [Reproducció](#reproducció).
- **Per lectura**: detectada llegint el codi; no s'ha pogut executar (normalment perquè cal un servidor de base de
  dades o una extensió que la imatge oficial no inclou).

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
  `secret` del context: `Hola ... DB_PASSWORD`. També passa entre fases: un valor de `%%...%%` que contingui `@@x@@`
  es resol a la fase 3.
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

### A1. `MssqlDatabaseConnection::close()` sempre provoca un error fatal — *confirmada*

[`src/Domain/Connection/Database/MssqlDatabaseConnection.php:78-83`](../src/Domain/Connection/Database/MssqlDatabaseConnection.php#L78)

`PDO` no té cap mètode `close()`. Cridar `$domain->close()` dona `Error: Call to undefined method PDO::close()`.

- **Correcció**: `$this->connection = null;`.

### A2. Les excepcions de MSSQL no arriben mai com a `TBPException` — *confirmada*

[`src/Domain/Connection/Database/MssqlDatabaseConnection.php:70`](../src/Domain/Connection/Database/MssqlDatabaseConnection.php#L70)
i totes les crides `new TBPException($this->connection->errorInfo(), $this->connection->errorCode())`.

`open()` activa `PDO::ERRMODE_EXCEPTION` (línia 56), així que qualsevol error SQL surt com a `PDOException` i les
branques que llancen `TBPException` no s'executen mai. Si s'hi arribés (per exemple amb `ERRMODE_SILENT`), tampoc
funcionarien: `errorInfo()` retorna un array i `errorCode()` una cadena SQLSTATE (`'HY000'`), però `Exception`
exigeix un missatge `string` i un codi `int`, i es llança un `TypeError`.

- **Escenari** (PHP 8.5): una consulta a una taula inexistent llança
  `PDOException: SQLSTATE[HY000]: General error: 1 no such table`; amb `ERRMODE_SILENT`, llança
  `TypeError: Exception::__construct(): Argument #1 ($message) must be of type string, array given`.
- **Correcció**: capturar `PDOException` i reenviar-la com a `TBPException($e->getMessage(), (int) $e->getCode(), $e)`.

### A3. `redirect()` fa servir una constant que no existeix — *confirmada*

[`src/REST/AbstractAPIRest.php:198`](../src/REST/AbstractAPIRest.php#L198)

`HTTP_REDIRECT_POST` només la defineix l'extensió PECL `pecl_http`. Sense aquesta extensió, cridar `redirect()` sense
segon argument dona `Error: Undefined constant "Bitendian\TBP\REST\HTTP_REDIRECT_POST"`.

- **Correcció**: `$status = 303`.

### A4. MySQL fa servir `READ UNCOMMITTED` per a totes les connexions — *confirmada*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:77`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L77)

Cada `open()` executa `SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED`. Totes les lectures de l'aplicació
poden veure dades no confirmades d'altres transaccions (lectures brutes), que després poden desfer-se amb un
rollback.

- **Escenari**: després d'`open()`, `@@SESSION.transaction_isolation` val `READ-UNCOMMITTED` en lloc del
  `REPEATABLE-READ` per defecte (test `a4SessionKeepsDefaultIsolationLevel`).

- **Correcció**: no canviar el nivell d'aïllament per defecte (InnoDB fa servir `REPEATABLE READ`) o fer-lo
  configurable.

### A5. Els errors de connexió de MySQL no surten com a `TBPException` — *confirmada*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:70-74`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L70)

`new mysqli()` no retorna mai `false`, així que la comprovació `!($this->connection = ...)` no salta mai. Des de
PHP 8.1, mysqli llança excepcions per defecte: amb PHP 8.5, una connexió fallida surt com a `mysqli_sql_exception`
(per exemple `Connection refused`), no com la `TBPException` que documenta el mètode. Qui capturi `TBPException` no
la veurà. El mateix passa amb els errors de `prepare()`/`execute()` de `select()` i `command()`.

- **Escenari** (MariaDB 11.8): una contrasenya incorrecta llança `mysqli_sql_exception: Access denied for user`, i
  una consulta a una taula inexistent, `mysqli_sql_exception: Table 'tbp.missing_table' doesn't exist` (tests
  `a5WrongPasswordThrowsTbpExceptionOnOpen` i `a5SqlErrorsAreThrownAsTbpException`).

- **Correcció**: capturar `mysqli_sql_exception` i reenviar-la com a `TBPException`, i treure les comprovacions de
  retorn que ja no poden fallar.

### A6. MSSQL fa servir una constant de l'extensió `sqlsrv` amb PDO — *confirmada*

[`src/Domain/Connection/Database/MssqlDatabaseConnection.php:103`](../src/Domain/Connection/Database/MssqlDatabaseConnection.php#L103)

`$statement->fetch(SQLSRV_FETCH_ASSOC)` funciona per casualitat (val 2, igual que `PDO::FETCH_ASSOC`) només si
l'extensió `sqlsrv` està carregada. Si només hi ha `pdo_sqlsrv`, qualsevol `select()` dona
`Error: Undefined constant "...\SQLSRV_FETCH_ASSOC"`.

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

### M2. Les URLs de les rutes s'interpreten com a regex — *confirmada*

[`src/Utils/Router.php:40-50`](../src/Utils/Router.php#L40)

El patró de la ruta es posa dins de `'*^' . $pregUrl . '$*i'` sense `preg_quote`. Un `.` coincideix amb qualsevol
caràcter, `+`, `?`, `(` canvien el significat i un `*` trenca el delimitador.

- **Escenari**: la ruta `/feed.xml` també coincideix amb `/feedXxml`.
- **Correcció**: aplicar `preg_quote($url, '#')` abans de substituir les variables `{nom}` i fer servir un delimitador
  que no aparegui a les URLs.

### M3. El `Router` no codifica els paràmetres de les URLs — *confirmada*

[`src/Utils/Router.php:99-101`](../src/Utils/Router.php#L99), [`:142-147`](../src/Utils/Router.php#L142)

`getRoute()` i `getRouteWithParams()` insereixen els valors directament. Un paràmetre amb `/`, `?`, `#` o espais
genera una URL trencada o que apunta a una altra ruta.

- **Escenari**: la ruta `/items/{id}` amb `id = '1/../../admin?x=1'` genera `/items/1/../../admin?x=1`.
- **Correcció**: `rawurlencode()` de cada valor.

### M4. MySQL fa servir `utf8` en lloc d'`utf8mb4` — *confirmada*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:76`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L76)

`utf8` és `utf8mb3` (màxim 3 bytes per caràcter). Els emojis i altres caràcters fora del pla bàsic no es poden
desar, encara que la taula sigui `utf8mb4`.

- **Escenari** (MariaDB 11.8): inserir `😀` en una columna `utf8mb4` falla amb
  `mysqli_sql_exception: Incorrect string value: '\xF0\x9F\x98\x80'` (test `m4Utf8mb4RoundTrip`).

- **Correcció**: `set_charset('utf8mb4')`, o fer-ho configurable.

### M5. Els errors de sentència de MySQL es llegeixen de la connexió — *per lectura*

[`src/Domain/Connection/Database/MysqlDatabaseConnection.php:149-151`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L149),
[`:215-217`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L215)

Quan `$statement->execute()` falla, l'error és a `$statement->error`, no a `$this->connection->error`. El missatge
de l'excepció pot quedar buit. Amb PHP 8.5 aquestes branques només s'executen si s'ha desactivat el mode
d'excepcions de mysqli (vegeu A5).

- **Correcció**: `throw new TBPException($statement->error, $statement->errno)`.

### M6. `__toString()` deixa el buffer de sortida obert si `render()` falla — *confirmada*

[`src/UI/AbstractRenderizable.php:30-41`](../src/UI/AbstractRenderizable.php#L30)

Si `render()` llança una excepció, `ob_end_clean()` no s'executa i el buffer queda obert, cosa que barreja sortida
parcial amb la pàgina d'error. Si `ob_start()` falla, `__toString()` retorna `null`, que és un `TypeError`.

- **Escenari**: un widget que fa `echo 'partial'` i després llança una excepció deixa el nivell de buffer de 0 a 1,
  amb `'partial'` a dins.
- **Correcció**: `try { ... } finally { ob_end_clean(); }` i retornar sempre una cadena.

### M7. L'API REST depèn de `REDIRECT_URL` (només Apache) — *confirmada*

[`src/REST/AbstractAPIRest.php:109`](../src/REST/AbstractAPIRest.php#L109)

`$_SERVER['REDIRECT_URL']` només el defineix `mod_rewrite` d'Apache. Amb nginx + PHP-FPM o el servidor integrat de
PHP no existeix: PHP 8.5 emet `Warning: Undefined array key "REDIRECT_URL"` i
`Deprecated: trim(): Passing null to parameter #1`, i el nom de l'API queda buit.

- **Correcció**: `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)`.

### M8. `strip_tags` només als paràmetres GET — *confirmada*

[`src/REST/AbstractAPIRest.php:226-239`](../src/REST/AbstractAPIRest.php#L226)

`cleanInputs()` altera dades legítimes i dona una falsa sensació de seguretat, però no s'aplica al cos de
POST/PUT/DELETE. La protecció contra XSS correspon a la sortida, no a l'entrada.

- **Escenari**: `?q=a<b and c>d` arriba a `get()` com a `ad`.
- **Correcció**: no modificar l'entrada; escapar a la sortida (vegeu C1).

### M9. El missatge d'estat HTTP és global i pot incloure dades de la petició — *confirmada*

[`src/REST/AbstractAPIRest.php:133`](../src/REST/AbstractAPIRest.php#L133), [`:344-359`](../src/REST/AbstractAPIRest.php#L344)

`setResponseStatus(405, 'invalid method: ' . $this->method)` modifica l'array estàtic `$requestStatus`. El mètode el
controla el client, i el canvi afecta totes les instàncies posteriors del mateix procés. `header()` bloqueja salts
de línia, però el text arriba a la línia d'estat.

- **Escenari**: després d'una petició `PATCH`, `requestStatus[405]` val `'invalid method: PATCH'` per a tothom.
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
| B1 | [`composer.json`](../composer.json) | ~~Exigeix `php >=5.3.2`, però el codi fa servir sintaxi de 5.4+ i `require-dev` demana `^7.3`.~~ *Resolt:* el requisit és ara `php >=8.5` i s'ha tret el `php` de `require-dev`. |
| B2 | [`composer.json`](../composer.json), [`LICENSE`](../LICENSE) | ~~`composer.json` diu MIT; `LICENSE` i els comentaris d'`Autoloader.php` diuen Apache 2.0.~~ *Resolt:* `composer.json` declara `Apache-2.0`. |
| B3 | [`composer.json`](../composer.json) | ~~El namespace dels tests apunta a `tests/src/` (la carpeta és `test/src/`) i és a `autoload` en lloc d'`autoload-dev`.~~ *Resolt:* mogut a `autoload-dev` amb la ruta `test/src/`. |
| B4 | [`composer.json`](../composer.json) | ~~Les paraules clau esmenten Postgres i Memcached, que no estan implementats. `minimum-stability` no té efecte en una llibreria.~~ *Resolt:* paraules clau corregides i `minimum-stability` eliminat. |
| B5 | [`test/phpunit.xml`](../test/phpunit.xml) | ~~Esquema antic (`blacklist`/`whitelist`, `<log>`), incompatible amb PHPUnit 9.3+.~~ *Resolt:* PHPUnit actualitzat a la 13 i configuració migrada al seu esquema. |
| B6 | [`ConfigTest.php:78-93`](../test/src/Utils/Config/ConfigTest.php#L78) | ~~`testEnvVar` necessita `TEST_ENV_VAR` i `TEST_ENV_VAR2` definides a l'entorn i no es defineixen enlloc.~~ *Resolt:* definides a `<php><env>` de `phpunit.xml`; confirmat que el test fallava sense elles. |
| B7 | [`AbstractAPIRest.php`](../src/REST/AbstractAPIRest.php) | ~~`libxml_disable_entity_loader` està obsoleta des de PHP 8.0.~~ *Resolt:* eliminada (libxml ≥ 2.9 ja bloqueja les entitats externes; verificat amb PHP 8.5). Els dos analitzadors XML continuen sent idèntics. |
| B8 | [`SqliteDatabaseConnection.php:126-136`](../src/Domain/Connection/Database/SqliteDatabaseConnection.php#L126) | `convertQuestionMarksIntoIds` no es fa servir. `getEnumValues` no està implementat a MSSQL ni a SQLite. |
| B9 | [`RedisCacheConnection.php:141-144`](../src/Domain/Connection/Cache/RedisCacheConnection.php#L141) | `setCardinality()` no retorna res. `close()` no desconnecta. `AbstractCacheDomain` no té `open()`/`close()` com el domini de base de dades. |
| B10 | [`Router.php`](../src/Utils/Router.php) | *Confirmada:* si no s'ha cridat `load()`, `getRouteData()` emet `Warning: foreach() argument must be of type array\|object, null given`. Fa servir `_()` (requereix l'extensió gettext) de manera inconsistent. No distingeix entre ruta inexistent i rol no permès. |
| B11 | [`SqliteDatabaseConnection.php`](../src/Domain/Connection/Database/SqliteDatabaseConnection.php) | *Confirmada:* tots els paràmetres es vinculen com a `SQLITE3_TEXT` (`typeof(?)` amb un enter dona `text`); les comparacions amb columnes numèriques funcionen per l'afinitat de tipus de SQLite, però no les que no tenen afinitat. No s'estableix `busyTimeout` (errors "database is locked" amb concurrència). `encryptionKey` només funciona amb compilacions SEE/SQLCipher. La comprovació d'error d'`open()` no s'executa mai. |
| B12 | `Utils/*` | Estat global estàtic a `Router`, `HttpHeaders`, `HtmlHeaders`, `SystemMessages` i `Config`: problemes en entorns de llarga durada (Swoole, RoadRunner) i en tests. |
| B13 | [`SystemMessages.php:98-116`](../src/Utils/SystemMessages.php#L98) | `unserialize` sense `allowed_classes`; si es passa un array que no sigui la sessió, permet injecció d'objectes. El comentari de la classe parla de claus amb microsegons que no existeixen. |
| B14 | [`Config.php:66-77`](../src/Utils/Config.php#L66), [`:184-188`](../src/Utils/Config.php#L184) | Falta `closedir`. Un fitxer anomenat `0` atura `readdir`. `setConfigFileExtension` recarrega la cau compartida per a totes les instàncies. |
| B15 | [`MysqlDatabaseConnection.php:237-259`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L237) | `autocommit(false)` + `begin_transaction()` és redundant. `commit()`/`rollback()` no comproven el resultat. |
| B16 | [`MysqlDatabaseConnection.php:139-147`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php#L139) | *Confirmada:* els paràmetres han de ser una llista indexada des de 0 i tots es vinculen com a `s`, de manera que `false` arriba com a `''` i MariaDB el rebutja en una columna `TINYINT(1)` (`Incorrect integer value: ''`, test `b16BooleanParamsAreStored`). Els resultats s'indexen per l'autoincrement, de manera que `$rows[0]` no funciona. |
| B17 | [`AbstractAPIRest.php:113-121`](../src/REST/AbstractAPIRest.php#L113), [`:401-416`](../src/REST/AbstractAPIRest.php#L401) | Els quatre mètodes són abstractes, així que la comprovació `method_exists` de `processAPI()` és inútil. Fa servir `X-HTTP-Method` en lloc de l'habitual `X-HTTP-Method-Override`. |
| B18 | [`Templater.php:64-75`](../src/UI/Templater.php#L64) | *Confirmada:* un array associatiu com a context es tracta com una llista de contextos: `new Templater($f, ['name' => 'x'])` amb `Hola @@name@@` dona `Hola`. La comprovació `$this->context !== null` de `replace()` sempre és certa. |
| B19 | [`Pagination.php:59`](../src/Utils/Pagination.php#L59) | *Confirmada:* `ceil()` retorna `float`; `getTotalPages()` retorna `float(3)` en lloc de `int(3)`. |
| B20 | [`MysqlDatabaseConnection.php`](../src/Domain/Connection/Database/MysqlDatabaseConnection.php), [`MssqlDatabaseConnection.php`](../src/Domain/Connection/Database/MssqlDatabaseConnection.php), [`SqliteDatabaseConnection.php`](../src/Domain/Connection/Database/SqliteDatabaseConnection.php) | *Confirmada a MySQL:* fer servir la connexió abans d'`open()` dona `Error: Call to a member function prepare() on null` en lloc d'una `TBPException` (test `b20UsingAClosedConnectionThrowsTbpException`). MSSQL i SQLite fan el mateix, per lectura. La connexió PostgreSQL ja ho controla. |

## Reproducció

L'script [`repro/run.php`](repro/run.php) reprodueix totes les troballes marcades com a confirmades. Fa servir
[`repro/cfg/a.config`](repro/cfg/a.config) i [`repro/tpl.html`](repro/tpl.html), i munta el projecte en només
lectura. Des de l'arrel del projecte:

```bash
docker run --rm -v "$PWD":/app:ro -w /app php:8.5-cli sh -c 'docker-php-ext-install mysqli >/dev/null 2>&1; php doc/repro/run.php'
```

S'instal·la `mysqli` dins del contenidor només per a A5. Les connexions MSSQL (A1, A2, A6) es proven injectant un
`PDO` de SQLite, perquè la imatge no inclou `pdo_sqlsrv`; les fallades no depenen del controlador. A8, M5, M10 i M11
necessiten un servidor SQL Server o Redis, o depenen de com l'aplicació faci servir el framework, i queden com a
*per lectura*.

Les troballes de MySQL (A4, A5, A7, M4, B16, B20) també es reprodueixen contra un servidor MariaDB 11.8 real amb els
tests del grup `known-issue` de
[`MysqlDatabaseConnectionTest.php`](../test/src/Domain/Connection/Database/MysqlDatabaseConnectionTest.php). Aquests
tests comproven el comportament correcte, així que fallen mentre el problema no estigui resolt; `test/phpunit.xml`
els exclou de l'execució per defecte:

```bash
bin/test --no-coverage --group known-issue
```

Resultat amb PHP 8.5.12 i MariaDB 11.8 (2026-10-25): els 7 tests fallen (5 errors d'asserció i 2 excepcions).

Sortida amb PHP 8.5.12 (2026-10-25, abreujada):

```
PHP 8.5.12
== B10 Router::getRouteData() without load()
    [Warning] foreach() argument must be of type array|object, null given
== C4 / M1 / A7 Config
  ["inline"]=>   string(13) "bar # comment"                         <- M1
  ["password"]=> string(0) ""                                      <- C4
  ["quoted"]=>   string(3) "abc"                                   <- M1
    use_associative_array still enabled: bool(true)               <- A7
== C1 / C2 Templater XSS and template injection
    Hola <script>alert(1)</script> DB_PASSWORD
== B18 Templater with an associative array as context
    [Hola]
== M2 Router treats "." as a regex wildcard
    /feedXxml matches /feed.xml: bool(true)
== M3 Router does not encode params
    /items/1/../../admin?x=1
== M6 output buffer left open when render() throws
    ob level before=0 after=1, leaked buffer: 'partial'
== B19 Pagination::getTotalPages() type
float(3)
== A6 select()
    Error: Undefined constant "Bitendian\TBP\Domain\Connection\Database\SQLSRV_FETCH_ASSOC"
== A2 SQL error with ERRMODE_EXCEPTION (as set by open())
    PDOException: SQLSTATE[HY000]: General error: 1 no such table: missing
== A2 SQL error with ERRMODE_SILENT (TBPException branch)
    TypeError: Exception::__construct(): Argument #1 ($message) must be of type string, array given
== A1 close()
    Error: Call to undefined method PDO::close()
== A3 redirect() without status
    Error: Undefined constant "Bitendian\TBP\REST\HTTP_REDIRECT_POST"
== M8 strip_tags on GET params
  ["q"]=>    string(2) "ad"
  ["html"]=> string(4) "hola"
== M9 405 reason phrase changed globally
    requestStatus[405] = 'invalid method: PATCH'
== M7 request without REDIRECT_URL
    [Warning] Undefined array key "REDIRECT_URL"
    [Deprecated] trim(): Passing null to parameter #1 ($string) of type string is deprecated
== B11 SQLite binds every param as TEXT
  ["type"]=>       string(4) "text"
  ["numeric_ok"]=> int(1)
== A5 MySQL connection error
    mysqli_sql_exception: Connection refused
== C3 Templater infinite loop (fatal, 3 s limit)
Fatal error: Maximum execution time of 3 seconds exceeded in /app/src/UI/Templater.php on line 162
```
