# FewohBee — Property Management System for Guesthouses & Hotels

**The open-source booking and management software for small and medium-sized guesthouses, pensions,
holiday apartments and hotels.**

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000.svg)](https://symfony.com/)

*Deutsche Version: [README.de.md](README.de.md) · Documentation: [Wiki](https://github.com/developeregrem/fewohbee/wiki) · Website: [fewohbee.app](https://fewohbee.app)*

---

Smaller accommodations usually manage their rooms the old way — with a pen and a sheet of paper, or
a spreadsheet. That was the itch this project started out to scratch back in 2014, and it has grown
into a complete property management system (PMS) since: reservations, guest data, invoicing,
accounting, correspondence and calendar sync with the booking portals, all in one place and running
on your own server.

It's built **by hoteliers, for hoteliers** — shaped by everyday practice in a real guesthouse rather
than by a feature checklist. Topics like double-entry bookkeeping or e-invoicing are unavoidable
these days, so the goal is to wrap them in something you can actually operate without professional
training.

- 🔓 **Free and open source** (GPL-3.0) — self-host it, fork it, adapt it
- 🇩🇪 🇬🇧 **Bilingual** — fully available in German and English
- 🐳 **Docker-ready** — up and running in minutes
- 🧾 **E-invoicing built in** — EN 16931, XRechnung, ZUGFeRD
- 🔐 **Modern authentication** — passwords, passkeys (WebAuthn), single sign-on (OIDC), scoped API tokens

Curious what it looks like? There's a feature tour at [fewohbee.app](https://fewohbee.app), and the
[Wiki](https://github.com/developeregrem/fewohbee/wiki) covers everything in depth.

---

## Features

| | |
|---|---|
| 🛏️ **Reservations** | Visual calendar grid, availability and capacity checks, room blocks, configurable origins and statuses |
| 👥 **Guests** | Guest profiles, companies, guest categories for age-based pricing, GDPR export |
| 🌐 **Online booking** | Direct booking from your website — search or calendar mode, embeddable, with abuse protection |
| 🧾 **Invoices** | PDF invoices, configurable number ranges, and e-invoicing via EN 16931 / XRechnung / ZUGFeRD |
| 📒 **Accounting** | Double-entry booking journal with guided chart of accounts, DATEV export, CSV bank import with automatic invoice matching, cash book |
| 📋 **Operations** | Front desk view, housekeeping lists, printable daily reports |
| ⚡ **Automation** | Rules engine with triggers, conditions and actions — send emails, change statuses, create bookings, all without code |
| ✉️ **Correspondence** | Send email from the app, with a visual template editor and live preview |
| 📅 **Calendar sync** | Two-way iCal/ICS sync with Airbnb, Booking.com and others |
| 📊 **Statistics** | Occupancy and utilization, monthly snapshots, tourist tax reporting |
| ⚙️ **Administration** | Multiple branches, granular roles, passkeys, SSO, read-only REST API |

📖 **[Full feature overview in the Wiki](https://github.com/developeregrem/fewohbee/wiki#features)**
 · **[User manual](https://www.fewohbee.app/en/documentation/documentation.html)**

---

## Requirements

- **PHP 8.4 or higher** (the official Docker image runs on PHP 8.5)
  - Extensions: `intl` (with full ICU data), `gd`, `pdo_mysql`, `exif`, `ctype`, `iconv`, `zip`
- A web server — nginx or Apache
- **MySQL 8.0+** or **MariaDB** — set `DB_SERVER_VERSION` in `.env` to match your server
- [Composer](https://getcomposer.org/download/)

Optional:

- **Redis** — for caching and session storage (`USE_REDIS_CACHE=true`). Not required; the
  filesystem cache works fine for a single instance.
- **HTTPS + a configured `RELYING_PARTY_ID`** — required if you want to enable passkey login
  (`PASSKEY_ENABLED=true`).
- **An OpenID Connect provider** — required only for single sign-on (`OIDC_ENABLED=true`), e.g.
  Keycloak, Authentik, Authelia, Entra ID or Google Workspace.
- **S3-compatible storage** — as an alternative to local file storage (`STORAGE_ADAPTER`).

See the [Symfony technical requirements](https://symfony.com/doc/current/setup.html#technical-requirements)
for the general baseline.

---

## Quick Start

### Option A: Docker (recommended)

A pre-configured Docker Compose setup is maintained separately:

👉 **[fewohbee-dockerized](https://github.com/developeregrem/fewohbee-dockerized)**

It ships the application, web server and database, and is the fastest path to a running instance.
See the [Docker Setup guide](https://github.com/developeregrem/fewohbee/wiki/Docker-Setup) in the
Wiki.

### Option B: Manual installation

**1. Create the database**

```sql
CREATE DATABASE fewohbee CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**2. Configure the application**

Copy `.env.dist` to `.env` and adjust:

- `DATABASE_URL` — your database credentials
- `APP_SECRET` — a random secret, e.g. `php -r 'echo bin2hex(random_bytes(16));'`
- `APP_ENV=prod` for production installations

**3. Install dependencies**

```bash
composer install --no-dev --optimize-autoloader
```

**4. Initialize database and application**

```bash
php bin/console doctrine:migrations:migrate
php bin/console asset-map:compile
php bin/console app:first-run
```

`app:first-run` guides you through creating the first admin user and your accommodation.
Add `--load-sample-data` if you want example data to explore.

**5. Log in**

Point your web server's document root at `public/` and open the application in a browser, then log
in with the user you just created.

### Don't forget: scheduled tasks

Calendar sync and time-based automations only work if their commands run regularly. The Docker
setup brings its own cron container; for a manual installation, add this to your crontab:

```bash
# Calendar synchronization with booking portals (both directions)
*/15 * * * * cd /path/to/fewohbee && php bin/console calendar:import:sync --force
*/15 * * * * cd /path/to/fewohbee && php bin/console calendars:sync

# Time-based workflows (reminders, follow-ups, scheduled emails)
*/15 * * * * cd /path/to/fewohbee && php bin/console workflow:process-scheduled

# Log retention
0 3 * * * cd /path/to/fewohbee && php bin/console app:purge-logs --days=90
```

The reference schedule lives in [`docker/app/crontab`](docker/app/crontab) — worth a look if you
want to stay in sync with the defaults.

The statistics module can also keep monthly snapshots for year-over-year comparison. If you use it,
schedule it once a month:

```bash
5 0 1 * * cd /path/to/fewohbee && php bin/console stats:snapshot:month
```

---

## Upgrading

**Back up your database before every upgrade**, and skim the
[release notes](https://github.com/developeregrem/fewohbee/releases) for version-specific steps.

### Docker

The [fewohbee-dockerized](https://github.com/developeregrem/fewohbee-dockerized) setup ships an
update script that does the whole job:

```bash
./update-docker.sh
```

It pulls the new images, restarts the stack, and syncs newly introduced environment variables into
your `.env` and both compose files. Give those new variables a quick review afterwards — some may
need a value from you.

### Manual installation

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate
php bin/console asset-map:compile
php bin/console cache:clear
```

---

## REST API

FewohBee exposes a **read-only REST API** for reservations, calendar data, invoices and statistics.

- Authentication uses **personal access tokens** created in the user profile (prefix `fwb_`), sent
  as `Authorization: Bearer <token>` or as the password in HTTP Basic auth for clients that only
  support username/password (e.g. calendar applications).
- Every token carries **scopes**, and a scope only takes effect if the token owner also holds the
  matching application role — a token can never grant more than its owner has.
- The full specification lives in [`docs/openapi.yaml`](docs/openapi.yaml).

📖 **Guide and examples:
[REST API documentation in the Wiki](https://github.com/developeregrem/fewohbee/wiki/REST-API)**

---

## Hosted version

Not everyone wants to run their own server, keep it patched and worry about backups. If that's you,
a managed version is available at **[fewohbee.app](https://fewohbee.app)** — same application,
hosted and maintained, with the revenue funding the development of this open-source project.

Self-hosting stays free and fully supported. Both paths use the same codebase.

---

## Internationalization

FewohBee is multilingual by design and ships **complete German and English** translations. Both
languages are maintained together — no feature ships in one language only.

Want another language? [Open an issue](https://github.com/developeregrem/fewohbee/issues) — 
contributions are very welcome.

---

## Contributing

Contributions are welcome, whether it's a bug report, a translation, documentation or a feature.

- 📋 **Read [AGENTS.md](AGENTS.md) first.** It documents the architecture, security requirements,
  internationalization rules, testing expectations and coding standards for this project. It is
  written for AI coding agents, but it is the definitive contributor guide for humans too.
- 🐛 **Bugs and ideas:** [open an issue](https://github.com/developeregrem/fewohbee/issues)
- 🔀 **Pull requests:** keep them focused, include tests, and make sure German *and* English
  translations are complete

**Running tests:**

```bash
php bin/phpunit tests/Unit    # unit tests — fast, no database
bin/run-tests.sh              # full suite — resets the test database
vendor/bin/phpstan analyse    # static analysis, level 6
```

---

## Security

If you discover a security vulnerability, please **do not open a public issue**. Report it
privately to **info (at) fewohbee.app** so it can be fixed before disclosure.

Security is a first-class concern in this project — guest records are personal data under GDPR and
invoices are financial records. See the security section of [AGENTS.md](AGENTS.md) for the
standards applied to every contribution.

---

## License

FewohBee is licensed under the **[GNU General Public License v3.0](LICENSE)**.

You are free to use, study, modify and distribute it. If you distribute a modified version, it must
remain under the GPL-3.0 and the source must be made available.

---

## Author & Support

Developed by **Alexander Elchlepp** since 2014.

This is largely a one-person project built in free time. Questions, feedback and contributions are
always appreciated — [open an issue](https://github.com/developeregrem/fewohbee/issues) or write to
info (at) fewohbee.app.

If FewohBee is useful to you and you'd like to support its development:

[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.com/donate?hosted_button_id=ZQPG864PB4TBE)

⭐ Starring the repository also helps others discover the project.
