# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Mapas Culturais — a PHP/Postgres web platform for cultural mapping and management, used by Brazilian municipal/state/federal government cultural bodies. Server-side app built on Slim 4 + Doctrine ORM 2, with a Vue-based frontend built per-theme via webpack (laravel-mix)/pnpm workspaces. Runs entirely via Docker in development; there is no supported non-Docker local dev path.

## Development environment (Docker)

Everything runs through `dev/docker-compose.yml`. Common entry points:

```bash
dev/start.sh              # build (if -b passed) + docker compose run --service-ports mapas
dev/start.sh -b           # rebuild image first
dev/shell.sh              # docker compose exec mapas sh /var/www/scripts/shell.sh
dev/psql.sh                # psql into the dev db
dev/watch.sh               # pnpm install --recursive && pnpm run watch (asset rebuild on change)
dev/pnpm.sh <args>          # run arbitrary pnpm command inside the container
```

The `mapas` service serves on `:80` via PHP's built-in server (`php -S`, see `docker/development/start.sh` + `docker/development/router.php`), not php-fpm/nginx — that's only used in the production Dockerfile stage.

### Startup sequence (`docker/entrypoint.sh`)

On container start, in order: wait for Postgres → run `scripts/db-update.sh` and `scripts/mc-db-updates.sh` (schema/data migrations, see `src/db-updates.php` / `src/mc-updates.php`) → if `version.txt` changed since last deploy, recompile sass and regenerate Doctrine proxies → **if `BUILD_ASSETS=1`, run `pnpm install --recursive && pnpm run dev` before starting the web server** → start cron-like background scripts (`jobs-cron.sh`, `recreate-pending-pcache-cron.sh`, `cleanup-orphan-assets-cron.sh`) → exec the PHP server.

**Known gotcha — pnpm hangs forever on startup:** `dev/docker-compose.yml` sets `tty: true` + `stdin_open: true` on the `mapas` service. If the lockfile is out of date relative to a workspace's `package.json` (e.g. after adding a theme/plugin submodule), pnpm 10 tries to prompt interactively for confirmation to purge `node_modules` — but nothing is attached to answer, so the process hangs indefinitely with 0% CPU and no error. The service sets `environment: CI=true` specifically to force pnpm into non-interactive mode and avoid this. If you ever see the container stuck after `Scope: all N workspace projects` in the logs with no further output, this is why — check `pnpm-lock.yaml` is in sync (see below) rather than waiting it out.

### Updating the pnpm lockfile

`src/` is a pnpm workspace (`pnpm-workspace.yaml`: `modules/*`, `plugins/*`, `themes/*`, `node_scripts`). Any theme or plugin with its own `package.json` becomes a workspace member automatically. Whenever one is added/changed, update the lockfile **on the host, outside Docker**:

```bash
cd src && pnpm install --no-frozen-lockfile
```

`--no-frozen-lockfile` is required because `CI=true` (see above) is also active in scripts that shell out with that env, forcing frozen-lockfile by default otherwise.

If this fails with `EACCES`/permission errors under `src/**/node_modules`, it's because a previous container run created those files as `root` (the container runs `pnpm install` as root over the bind-mounted `src/` directory). Fix with:

```bash
sudo chown -R $USER:$USER src/
```

Never hand-edit `pnpm-lock.yaml`.

### Tests

Tests run inside a separate docker-compose stack under `tests/`:

```bash
tests/run.sh                                  # runs the full suite: phpunit /var/www/tests
tests/run.sh Tests/SomeSpecificTest.php       # args are passed straight to phpunit, so this scopes to one file/dir
tests/run.sh -b                                # rebuild image first
tests/bash.sh                                  # drop into a shell in the test container instead
```

Under the hood this is `docker compose run --service-ports mapas phpunit /var/www/tests $@`, where `phpunit` inside the container is `vendor/bin/phpunit --display-warnings --process-isolation $@` (see `tests/docker-compose.yml`, `tests/docker/phpunit.sh`). `--process-isolation` matters: tests mutate shared DB/app state, so isolating processes per test avoids leakage between tests. There is a `pu` variant without process isolation (`tests/docker/pu.sh`) for faster local runs when you know isolation isn't needed.

Test PHP classes live in `tests/src/`, autoloaded as `Tests\` (see root `composer.json`). Every test ultimately goes through `tests/src/Abstract/TestCase.php`, which `require_once`s `tests/src/bootstrap.php` at file-load time — this is what boots `MapasCulturais\App` with the test config (`tests/config.d/`) merged over the app defaults. `tests/src/Builders/*` and `tests/src/Directors/*` are test data factories (builder + director pattern) used across most test files instead of raw entity construction — prefer them over creating entities by hand when writing new tests.

There is no `phpunit.xml` in this repo; behavior is driven entirely by the CLI flags in the wrapper scripts plus the bootstrap chain above.

## Configuration system

Config is assembled by `src/conf/config.php`, which:
1. Loads every `.php` file directly under `config/*.php`, alphabetically, merging with `array_merge` (later files win on key collision).
2. Then loads every `.php` file under any `config/*.d/` folder, alphabetically, same merge behavior.

In development, `dev/docker-compose.yml` bind-mounts `dev/config.d/` over `config/config.d/` inside the container, so files there (`0.main.php`, `plugins.php`, `auth.php`, `cache.php`, `log.php`, `middlewares.php`) are what actually take effect in dev, overriding the base `config/*.php` files. When changing dev-only behavior (active theme, enabled plugins, auth providers), edit `dev/config.d/`, not `config/`.

## Extension architecture (themes / plugins / modules)

- `THEMES_PATH`/`PLUGINS_PATH`/`MODULES_PATH` (defined in `src/bootstrap.php`) point at `src/themes`, `src/plugins`, `src/modules`.
- Active theme is set via `themes.active` in config; enabled plugins via the `plugins` array in `config/plugins.php` (overridden in dev by `dev/config.d/plugins.php`). Modules under `src/modules` are the built-in, always-available feature units (Opportunities, Seals, Notifications, Search, etc.) and aren't toggled the same way plugins are.
- `MapasCulturais\Module` (abstract, `src/core/Module.php`) is the base for both modules and plugins — every subclass implements `_init()` and `register()`. `MapasCulturais\Plugin` (`src/core/Plugin.php`) extends `Module`, marks `isPlugin() == true`, and adds a `preInit()` hook.
- Extension points are wired through `MapasCulturais\Hooks` (`src/core/Hooks.php`), a WordPress-style hook/filter registry attached to the `App` singleton — modules/plugins/themes register callbacks against named hooks rather than the core dispatching to them directly. When tracing "who does X", grep for the relevant hook name across `src/core`, `src/modules`, and the active theme/plugins rather than assuming a fixed call path.
- Themes, plugins, and any `src/modules/*` package can each carry their own `package.json`/pnpm workspace member for frontend assets (Vue components, sass) built via `laravel-mix`.
- Themes and plugins that live outside the base app (e.g. `Pnab`, `AldirBlanc`, `MultipleLocalAuth`) are added as **git submodules** under `src/themes/<Name>` or `src/plugins/<Name>`, and separately bind-mounted in `dev/docker-compose.yml` in addition to the general `../src:/var/www/src` mount. See `instrucao.md` for the exact, tested procedure (including the `-f` flag needed for `git submodule add` under `src/plugins/*`, which `.gitignore` excludes by default) and the pnpm/permission gotchas above.

## Core app structure

- `MapasCulturais\App` (`src/core/App.php`, ~5500 lines) is a singleton (`App::i()`) wrapping a Slim 4 app (`$this->slim`) plus the Doctrine `EntityManager` (`$this->em`) and the `Hooks` registry. It's the central object almost everything else reaches through (config, routing, permissions, current user, etc.) — expect to see `App::i()->...` pervasively instead of dependency injection.
- Entities live in `src/core/Entities`, mapped via Doctrine annotations, and extend the base `MapasCulturais\Entity`. Domain concepts (Agent, Space, Event, Project, Opportunity, Registration, Seal, ...) each have a corresponding permission-cache entity (e.g. `AgentPermissionCache`) — permission checks are precomputed/cached rather than evaluated ad hoc on every request; see `documentation/docs/mc_permission_cache.md`.
- Controllers (`src/core/Controllers`) map roughly 1:1 to entity types and register their own routes.
- `public/index.php` is the sole HTTP entry point — it just requires `bootstrap.php` and calls `$app->run()`; all real dispatch happens through Slim + the hooks system.

## Composer / PHP

- PSR-4 autoload: `MapasCulturais\` → `src/core`, `MapasCulturais\Modules\` → `src/modules`, `MapasCulturais\Themes\` → `src/themes`, `Tests\` → `tests`. Plugins are not in root `composer.json` autoload — they're loaded via the module/plugin registration system, not Composer namespaces.
- PHP 8.3, Doctrine ORM `2.16.*` pinned (not upgraded to 3.x), PHPUnit `^10.5`.
- `composer install`/`dump-autoload` happen at Docker image build time (see `docker/Dockerfile`); you generally don't run Composer directly outside the container.
