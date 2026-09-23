# Local PrestaShop 9 test environment

A fully self-contained, throwaway PrestaShop 9.1.5 + MySQL 8 stack for
manually testing `prestashop-product-import/` against a real PrestaShop
instance. Everything lives in Docker containers and named volumes — nothing
is installed system-wide, and nothing outside this directory is touched
except `../prestashop-product-import/` (bind-mounted read-write so edits on
the host show up immediately).

## Requirements

[Colima](https://github.com/abiosoft/colima) + the `docker` CLI (installed
via `brew install colima docker docker-compose`, see the repo's main
history for the exact setup). Nothing else — no local PHP/MySQL needed for
this environment (the module's own `composer install`/`phpunit`/
`php-cs-fixer` gates still run directly on the host from
`prestashop-product-import/`, separately from this container stack).

## Start

```sh
colima start   # if not already running
cd local-dev
docker compose up -d
```

First boot auto-installs PrestaShop (a few minutes). Watch progress with
`docker compose logs -f prestashop`. Once up:

- Shop front: <http://localhost:8080/>
- Admin back office: <http://localhost:8080/admin-dev/> — `admin@example.com`
  / the password in `.env` (`ADMIN_PASSWD`)
- Product Import admin pages: Catalog → Product Import / Product Import runs

## The module's `vendor/`

The container gets its **own** production (`--no-dev`), PHP-8.3-matched
`vendor/` via a separate named volume (`module_vendor`) that shadows the
module directory's `vendor/` subpath — it does NOT reuse the host's
dev-dependency `vendor/` (which includes PHPUnit/PHP-CS-Fixer and is built
against whatever PHP happens to be on the host). Rebuild it after a
`composer.lock` change:

```sh
docker run --rm \
  -v "$(pwd)/../prestashop-product-import:/app" \
  -v productimport-dev_module_vendor:/app/vendor \
  -w /app \
  composer:2 install --no-dev --optimize-autoloader --no-interaction
docker compose restart prestashop   # opcache doesn't always pick up bind-mount changes on its own
```

## Editing the module while it's running

Edits under `../prestashop-product-import/src/`, `controllers/`,
`views/`, etc. are live immediately (bind mount) — but PHP's opcache
(`opcache.validate_timestamps=On`) doesn't always notice a changed mtime
through Colima's virtiofs mount promptly. If a code change doesn't seem to
take effect, `docker compose restart prestashop`.

## Useful commands

```sh
docker compose logs -f prestashop              # tail the PrestaShop container's log
docker compose exec prestashop bash             # shell inside the container
docker compose exec prestashop php bin/console prestashop:module install productimport
docker compose exec prestashop php bin/console prestashop:module uninstall productimport
docker compose exec db mysql -uroot -pprestashop prestashop   # DB shell
```

## Tear down

```sh
docker compose down -v   # stops containers AND deletes the named volumes (db + PS files + module vendor)
```

Nothing persists outside Docker's own volume store — deleting them (or
just never running this stack again) leaves zero trace on the host beyond
the `colima`/`docker`/`docker-compose` Homebrew formulae themselves
(`brew uninstall colima docker docker-compose && colima delete` removes
those too, if desired).
