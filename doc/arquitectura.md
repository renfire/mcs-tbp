# Arquitectura de TBP

TBP és una llibreria PHP lleugera per a webs petites i mitjanes, orientada a components. No té dependències
obligatòries; `predis/predis` és opcional per a Redis.

```
src/
├── Autoloader.php            Autoloader PSR-4 propi (alternativa a Composer)
├── TBPException.php          Excepció base de tot el framework
├── UI/                       Components, widgets i plantilles
├── Domain/                   Accés a dades (bases de dades i cau)
├── REST/                     Base per a APIs REST
└── Utils/                    Configuració, rutes, capçaleres, missatges, paginació
```

## UI

Jerarquia de classes:

```
RenderInterface ── AbstractRenderizable ── AbstractWidget ── AbstractComponent
                         │                  (+FetchInterface)  (+ActionInterface)
                         └── Templater
```

- **Cicle de vida**: `action(&$params)` (opcional, quan arriba un POST per al component) → `fetch(&$params)`
  (llegeix el model i prepara el model de vista) → `render()` (genera l'HTML). L'aplicació és responsable de cridar
  les fases en ordre i sobre la mateixa instància.
- **`AbstractRenderizable::__toString()`** crida `render()` dins d'un buffer de sortida i en desa el resultat. Així
  qualsevol component es pot fer servir com a cadena dins d'un altre.
- **`AbstractComponent`** desa a `$action` el nom de la seva classe codificat en base64 + `urlencode`, perquè els
  formularis puguin indicar quin component ha de rebre el POST.
- **`Templater`** carrega un fitxer i substitueix marques en tres fases:
  1. `##TEXT##` → `gettext('TEXT')`
  2. `%%PROP.LLISTA%%` → concatenació dels elements d'un array del context
  3. `@@PROP.VALOR@@` → valor d'una propietat (objecte o array, amb notació de punts i sense distingir majúscules)

  El context pot ser un objecte o un array; si és un array, es tracta com una **llista de contextos** que es
  consulten per ordre.

## Domain

```
AbstractDatabaseDomain ─┬─ AbstractMysqlDomain  → MysqlDatabaseConnection  (mysqli)
                        ├─ AbstractMssqlDomain  → MssqlDatabaseConnection  (PDO sqlsrv)
                        └─ AbstractSqliteDomain → SqliteDatabaseConnection (SQLite3)

AbstractCacheDomain ──── AbstractRedisDomain    → RedisCacheConnection     (predis)
```

- Les connexions implementen `DatabaseConnectionInterface` (`open`, `close`, `select`, `command`, `lastInsertId`,
  `getEnumValues`, `begin`, `commit`, `rollback`) o `CacheConnectionInterface` (claus, llistes, conjunts, conjunts
  ordenats i hashos).
- L'aplicació crea un *domain* per agrupar consultes; el constructor rep un objecte de configuració (normalment de
  `Utils\Config`) i crea la connexió. `open()` s'ha de cridar explícitament.
- `AbstractDatabaseDomain` ofereix helpers: `getSingle()` (un sol resultat o `false`), `getAll()` (array d'objectes)
  i `insertWithAutoincrement()`.
- Totes les consultes usen sentències preparades. A MySQL, si la taula té un camp autoincrement, `select()` retorna
  les files indexades per aquest camp.

## REST

`AbstractAPIRest` analitza la petició al constructor:

- Treu el nom de l'API i el camí de `$_SERVER['REDIRECT_URL']`.
- Determina el mètode HTTP (amb suport per a `X-HTTP-Method` sobre POST).
- Llegeix el cos per a POST/PUT/DELETE i els paràmetres de `$_GET` per a GET.
- Registra analitzadors per a JSON, XML i `x-www-form-urlencoded` (`parseBody()`).

`processAPI()` crida el mètode `get`/`post`/`put`/`delete` de la subclasse i desa el resultat; `sendResponse()`
només envia la línia d'estat HTTP. Serialitzar la resposta (`getResponseData()`) és feina de l'aplicació.

## Utils

| Classe | Funció | Estat |
|---|---|---|
| `Config` | Llegeix tots els fitxers `*.config` d'una carpeta (`clau=valor`, `#` per a comentaris, `$VAR:defecte` per a variables d'entorn, `true`/`false` com a booleans) | Cau estàtica per carpeta |
| `Router` | Rutes amb URLs per idioma, variables `{nom}` i rols permesos; resol URI → ruta i ruta → URL | Estàtic |
| `HttpHeaders` | Acumula capçaleres HTTP sense duplicats | Estàtic |
| `HtmlHeaders` | Acumula títol, scripts i fulls d'estil mantenint l'ordre global d'inserció | Estàtic |
| `SystemMessages` | Missatges d'error/avís/informació; es poden desar i recuperar de la sessió | Estàtic |
| `Pagination` | Càlcul de pàgina actual, desplaçament i total de pàgines | Instància |

## Tests

Només hi ha tests per a `Utils\Config` (`test/src/Utils/Config/ConfigTest.php`). La resta del framework no té
cobertura.
