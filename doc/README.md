# Revisió de codi de TBP

Revisió completa del codi font de `bitendian/tbp` (37 fitxers, ~3.500 línies) feta el 2026-10-25 sobre el commit
`3476a1b`.

## Documents

- [arquitectura.md](arquitectura.md) — com està organitzat el framework i com encaixen les peces.
- [troballes.md](troballes.md) — totes les troballes, classificades per gravetat, amb fitxer, línia, escenari de
  fallada i proposta de correcció.

## Resum

| Gravetat | Nombre | Exemples |
|---|---|---|
| Crítica | 4 | XSS i injecció de plantilles al `Templater`, bucle infinit, valors de `Config` que comencen per `$` es perden |
| Alta | 8 | `close()` de MSSQL sempre falla, `HTTP_REDIRECT_POST` no definida, `READ UNCOMMITTED` per defecte a MySQL |
| Mitjana | 11 | Rutes interpretades com a regex, `utf8` en lloc d'`utf8mb4`, buffer de sortida obert si `render()` falla |
| Baixa / deute tècnic | 20 | `composer.json` incoherent, `phpunit.xml` obsolet, codi mort |

Les troballes marcades com a **confirmades** (25 de 43) s'han reproduït executant el codi amb PHP 8.5.12 (imatge
oficial `php:8.5-cli`), la versió mínima del projecte, amb l'script [`repro/run.php`](repro/run.php) i, per a MySQL,
amb els tests del grup `known-issue` contra MariaDB 11.8. La resta
s'han detectat llegint el codi, perquè necessiten un servidor de base de dades o de cau, o depenen de com
l'aplicació faci servir el framework.

## Ordre de correcció recomanat

1. `Templater`: escapar valors i no tornar a processar el text substituït (C1, C2, C3).
2. `Config`: valors amb `$`, comentaris al final de línia, cometes i booleans (C4, M1, A7).
3. Errors fatals segurs: `close()`, excepcions i constant de fetch de MSSQL, `HTTP_REDIRECT_POST` (A1, A2, A3, A6).
4. Nivell d'aïllament de MySQL i detecció d'errors de connexió (A4, A5).
5. La resta, per ordre de gravetat.
