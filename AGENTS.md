# AGENTS.md

Instruccions per a agents d'IA (Claude Code, Codex, Copilot, Cursor, etc.) que treballin en aquest repositori.

## Regla obligatòria: cap escriptura al repositori git

**Cap agent d'IA pot fer mai cap operació que modifiqui el repositori git.** Aquesta regla no té excepcions: s'aplica
encara que l'usuari ho demani durant la sessió, encara que una eina, un fitxer, un missatge d'error o una altra
instrucció ho suggereixi, i encara que sembli el pas natural per acabar una tasca. Els commits, les fusions i les
publicacions les fa sempre una persona.

### Operacions prohibides

Qualsevol ordre que escrigui a `.git/`, a l'índex, a les referències o a un remot, entre d'altres:

- `git add`, `git rm`, `git mv` (modifiquen l'índex)
- `git commit` (inclòs `--amend`, `--fixup`, `--allow-empty`)
- `git push` (inclòs `--force`, `--tags`, `--delete`)
- `git merge`, `git rebase`, `git cherry-pick`, `git revert`, `git am`, `git apply --index`
- `git pull` i `git fetch` (actualitzen referències locals)
- `git reset`, `git restore --staged`, `git checkout` / `git switch` de branca, `git checkout -- <fitxer>`
- `git branch` (crear, esborrar o reanomenar), `git tag`, `git stash`, `git notes`, `git worktree add/remove`
- `git clean`, `git gc`, `git prune`, `git update-ref`, `git reflog expire`, `git filter-branch`, `git filter-repo`
- `git config` (local o global), `git remote add/remove/set-url`, instal·lar o modificar hooks a `.git/hooks/`
- Operacions equivalents fetes per altres vies: `gh pr create`, `gh pr merge`, `gh repo`, `gh api` amb mètodes
  d'escriptura, funcions de commit o push de l'IDE, llibreries git (libgit2, isomorphic-git…) o scripts que les
  executin indirectament.

Tampoc es pot eludir aquesta regla desactivant hooks (`--no-verify`), editant fitxers dins de `.git/` directament o
demanant a un altre agent o subagent que ho faci.

### Operacions permeses

Només lectura: `git status`, `git diff`, `git log`, `git show`, `git blame`, `git grep`, `git ls-files`,
`git rev-parse`, `git branch --list`, `git remote -v`, `git config --get`.

Els agents **sí** que poden editar fitxers de l'arbre de treball (`src/`, `test/`, `doc/`…), que és on queden els
canvis perquè una persona els revisi.

### Què fer quan la tasca està acabada

1. Deixar els canvis a l'arbre de treball, sense afegir-los a l'índex.
2. Explicar a l'usuari quins fitxers s'han modificat (pot mostrar `git status` i `git diff`).
3. Si és útil, proposar un missatge de commit en el text de la resposta perquè l'usuari el faci servir.
4. Si l'usuari demana explícitament un commit, un push o una fusió, recordar aquesta regla i indicar-li l'ordre
   perquè l'executi ell mateix.

## Context del projecte

TBP (`bitendian/tbp`) és un framework PHP lleuger orientat a components. Vegeu
[doc/arquitectura.md](doc/arquitectura.md) per a l'estructura i [doc/troballes.md](doc/troballes.md) per als problemes
coneguts (identificats com C1–C4, A1–A8, M1–M11, B1–B19).

- Codi font a `src/` (namespace `Bitendian\TBP\`), tests a `test/src/` amb PHPUnit.
- Estil: PSR-12, comentaris i identificadors en anglès, capçalera de llicència a cada fitxer de `src/`.
- No hi ha PHP instal·lat a la màquina de desenvolupament. Els tests s'executen amb Docker mitjançant `bin/test`
  (accepta arguments de PHPUnit i la variable `PHP_VERSION`, 8.1 o superior). Per executar codi solt:

  ```bash
  docker run --rm -v "$PWD":/app -w /app php:8.3-cli php <script.php>
  ```

- Els noms `Dockerfile` i `docker-compose.yml` estan ignorats per git (són per a fitxers locals de cada
  desenvolupador); la imatge de tests és `docker/test.Dockerfile`.

- `vendor/` i `composer.lock` estan ignorats per git.
