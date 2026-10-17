# AGENTS.md

Guidance for AI coding agents (e.g. opencode) working in this repository.

## Project

`asg_orange_hrm` — a custom fork of **OrangeHRM Starter** (open-source HRM), deployed on
internal infrastructure at `/var/www/orangehrm`. Active branch: `development`.

- Upstream base: OrangeHRM Starter (GPL-2.0-only)
- Repo: https://github.com/adityasaranagraha/asg_orange_hrm.git

## Stack

- **Backend:** PHP 8.2, Symfony 5.4 components, Doctrine ORM 2.12, Twig 3.3, MySQL/MariaDB (PDO).
- **HTTP client:** Guzzle 7. **Auth:** built-in + LDAP + OAuth2 server (league/oauth2-server).
- **Frontend:** Vue 3 + OXD (`@ohrm/oxd`), Vue CLI, Yarn 3.
- **Tests:** PHPUnit (backend, root `phpunit.xml`), Jest (frontend).
- **Scheduler:** crunz.

## Layout

- `index.php` — entry point; routes to installer or app.
- `web/index.php` — Symfony front controller (app runtime). Built assets live in `web/dist`.
- `src/` — application code:
  - `src/plugins/orangehrm*Plugin/` — feature modules. Each has `entity/`, `controller/`,
    `api/`, `service/`, `dao/`, `config/routes.yaml`, templates, and `test/`.
  - `src/lib/` — core: `Config`, `Framework` (Symfony kernel wrapper), `ORM`.
  - `src/config/` — Doctrine proxies + install-generated `Conf.php` (secrets).
  - `src/client/` — frontend source (Vue). Builds to `web/dist`.
  - `src/vendor/` — Composer dependencies.
- `installer/` — web + CLI installer; DB migrations under `installer/Migration/V*`.
- `bin/console` — Symfony console entry.
- `phpunit.xml` — backend test config.

## Common commands

### Backend (run from `src/`)
```bash
composer install                          # install PHP deps
php ../bin/console orm:generate-proxies   # regen Doctrine proxies after entity changes
php ../bin/console cache:clear            # clear Symfony cache
```

### Tests
```bash
phpunit                                   # backend (config in root phpunit.xml)
```

### Frontend (run from `src/client/`)
```bash
yarn install        # install JS deps
yarn serve          # dev server
yarn dev            # build --watch -> web/dist
yarn build          # production build -> web/dist
yarn test:unit      # Jest unit tests
yarn lint           # ESLint (--max-warnings=0)
```

### Installer
```bash
php installer/cli_install.php    # CLI install using installer/cli_install_config.yaml
```

## Conventions

- **Branch:** `development` is active. There is a single `Initial commit`; most work is
  currently uncommitted in the working tree — commit before deploying.
- **Permissions:** webroot is `root:root` with **group-shared setgid** access: directories
  `2775`, files `664`. Developers are in the `root` group so they can edit without `sudo`;
  new files inherit the `root` group automatically. Match these modes on new files.
- **Secrets:** DB credentials live **only** in install-generated `src/config/Conf.php`.
  Never commit `Conf.php`, `cli_install_config.yaml` with real credentials, or any secrets.
- **Plugin pattern:** add features as self-contained plugins under `src/plugins/`; declare
  routes in the plugin's `config/routes.yaml`. Entities map to `OrangeHRM\<Module>\Entity`.
- **Code style:** follow existing repo style; do not add superfluous comments.

## Deployment

- Apache + mod_php; front controller is `web/index.php`. Root `index.php` only routes
  between installer and app.
- Runtime-writable (by `www-data`): `src/cache`, `src/log`.
- After entity/metadata changes: regenerate proxies and clear cache (see commands above).

## See also

- `README.md` — full codebase / architecture reference.
- `CHANGELOG.md` — notable changes (e.g. the External API Select custom field, 2026-07-14).
