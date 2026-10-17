# OrangeHRM — Custom Fork (ASG)

OrangeHRM is a comprehensive open-source **Human Resource Management System**. This
repository (`asg_orange_hrm`) is a custom fork of **OrangeHRM Starter**, deployed and
maintained on internal infrastructure.

> This README documents the **codebase** for developers. It supersedes the stock upstream
> marketing README, which is still recoverable with `git show HEAD:README.md`.

- **Base project:** OrangeHRM Starter (open source), GPL-2.0-only
- **Repository:** `https://github.com/adityasaranagraha/asg_orange_hrm.git`
- **Active branch:** `development`

OrangeHRM captures the core HR functions of an enterprise — employee records (PIM), leave,
attendance, time, recruitment, performance, and more — delivered through a modular
**plugin architecture** on top of a Symfony-based framework.

## Tech Stack

| Layer        | Technology                                                              |
| ------------ | ----------------------------------------------------------------------- |
| Language     | PHP 8.x (^7.4 / ^8.0)                                                   |
| Framework    | Symfony 5.4 components (http-kernel, routing, console, DI, cache, …)    |
| ORM          | Doctrine ORM 2.12                                                       |
| Templating   | Twig 3.3                                                                |
| Frontend     | Built SPA assets under `src/client` and `web/dist`                      |
| Scheduler    | crunz (cron-style task runner)                                          |
| Auth         | Built-in + LDAP (phpseclib / symfony-ldap) + OAuth2 server (league/oauth2-server) |
| HTTP client  | Guzzle 7                                                               |
| Database     | MySQL / MariaDB (PDO)                                                   |
| Tests        | PHPUnit 9                                                               |

## Architecture

OrangeHRM follows a **plugin-based modular** design:

- `src/lib/` — core libraries:
  - `Config` — application configuration & install state
  - `Framework` — a thin wrapper around the Symfony kernel
  - `ORM` — Doctrine bootstrap (EntityManager, proxies)
- `src/plugins/orangehrm*Plugin/` — each business module is self-contained, with its own:
  - `entity/` — Doctrine entities (PSR-4 mapped to `OrangeHRM\<Module>\Entity`)
  - `controller/`, `api/`, `service/`, `dao/`, `config/` (routes), templates, and `test/`
- The `Framework` kernel discovers and assembles all plugins and routes.
- Doctrine proxy classes are generated into `src/config/proxy/`.

### Request flow

1. Browser hits `index.php`.
2. If OrangeHRM is **not installed** → redirect to `installer/index.php` (web wizard)
   or run `installer/cli_install.php` (CLI).
3. If **installed** → redirect to `web/index.php` (Symfony front controller) →
   `Framework` kernel → matched plugin controller.

## Directory Structure

```
/var/www/orangehrm/
├── index.php                # Entry point; routes to installer or app
├── web/
│   ├── index.php            # Symfony front controller (app runtime)
│   ├── .htaccess            # Apache rewrite rules
│   ├── dist/                # Compiled frontend assets
│   └── images/
├── src/
│   ├── composer.json        # Dependencies + PSR-4 autoload map
│   ├── composer.lock
│   ├── vendor/              # Composer dependencies
│   ├── plugins/             # Feature modules (see "Modules")
│   ├── lib/                 # Core: Config, Framework, ORM
│   ├── config/              # Doctrine proxies + log settings
│   ├── cache/               # Symfony cache (runtime-writable)
│   ├── log/                 # Application logs (runtime-writable)
│   └── client/              # Frontend source
├── installer/
│   ├── index.php            # Web installer UI
│   ├── cli_install.php      # CLI installer
│   ├── cli_install_config.yaml  # CLI install params (DB, org, admin)
│   ├── console              # Installer console entry
│   └── Migration/           # Database migrations
├── bin/console              # Symfony console entry (app)
├── build/                   # Build tooling / product mode
├── lib/                     # Top-level library mappings
├── phpunit.xml              # Test configuration
├── LICENSE                  # GPL-2.0
└── README.md                # This file
```

## Modules (Plugins)

| Plugin                 | Namespace                    | Purpose                                     |
| ---------------------- | ---------------------------- | ------------------------------------------- |
| Core                   | `OrangeHRM\Core`             | Shared services, config, base infrastructure|
| Admin                  | `OrangeHRM\Admin`            | Users, job titles, pay grades, locations    |
| PIM                    | `OrangeHRM\Pim`              | Employee personal information management    |
| Authentication         | `OrangeHRM\Authentication`   | Login, sessions, passwords                  |
| LDAP                   | `OrangeHRM\LDAP`             | LDAP authentication & user sync             |
| CoreOAuth              | `OrangeHRM\OAuth`            | OAuth2 server (API tokens)                  |
| Leave                  | `OrangeHRM\Leave`            | Leave requests & entitlements               |
| Attendance             | `OrangeHRM\Attendance`        | Attendance tracking                         |
| Time                   | `OrangeHRM\Time`             | Timesheets & projects                       |
| Recruitment            | `OrangeHRM\Recruitment`      | Candidates & vacancies                      |
| Performance            | `OrangeHRM\Performance`       | KPIs & performance reviews                  |
| Dashboard              | `OrangeHRM\Dashboard`         | Dashboard widgets                           |
| CorporateDirectory     | `OrangeHRM\CorporateDirectory`| Employee directory                          |
| CorporateBranding      | `OrangeHRM\CorporateBranding` | Branding / theming                          |
| Buzz                   | `OrangeHRM\Buzz`             | Social feed                                 |
| Claim                  | `OrangeHRM\Claim`            | Expense / claim requests                    |
| I18N                   | `OrangeHRM\I18N`             | Internationalization                        |
| Help                   | `OrangeHRM\Help`             | In-app help                                 |
| Maintenance            | `OrangeHRM\Maintenance`       | Maintenance mode                            |
| Mobile                 | `OrangeHRM\Mobile`           | Mobile API / app support                    |
| SystemCheck            | `OrangeHRM\SystemCheck`       | Environment / system checks                 |

## Requirements

- PHP 7.4+ or 8.x, with extensions: `pdo_mysql`, `curl`, `json`, `mbstring`, `zip`,
  `dom`, `xml`, `simplexml`, `gd`, `ldap`, `openssl`
- MySQL or MariaDB
- Apache (mod_rewrite) or Nginx
- Composer (dependency management)

## Installation

### Web installer
Point the web server's `DocumentRoot` at this directory and open it in a browser. `index.php`
redirects to the installer wizard at `/installer/index.php`.

### CLI installer
Edit `installer/cli_install_config.yaml` (database, organization, admin user), then:

```bash
php installer/cli_install.php
```

> Do **not** commit real credentials. The CLI config and the generated `src/config/Conf.php`
> hold secrets and must stay out of version control.

## Development

Dependencies are managed under `src/`:

```bash
cd src
composer install
```

After changing entities or metadata, regenerate Doctrine proxies and clear the cache:

```bash
php bin/console orm:generate-proxies
php bin/console cache:clear
```

Run the test suite:

```bash
phpunit          # configured via the root phpunit.xml
```

## Deployment Notes

- **Web server:** Apache with PHP (`mod_php`). The application front controller is
  `web/index.php`; `index.php` at the root only routes between installer and app.
- **Permissions model:** the webroot is owned `root:root` with **group-shared access**:
  directories are `2775` (setgid) and files are `664`. Developers are added to the `root`
  group so they can read/write without `sudo`; the `setgid` bit makes new files inherit the
  `root` group automatically.
- **Runtime-writable paths:** `src/cache` and `src/log` are written by the web server
  (`www-data`) at runtime.
- **Secrets:** database credentials live only in the install-generated `src/config/Conf.php`,
  never in the repository.

## License

GPL-2.0-only. OrangeHRM © 2006 OrangeHRM Inc. — http://www.orangehrm.com/
