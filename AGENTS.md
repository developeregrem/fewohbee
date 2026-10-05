# AGENTS.md — Working on FewohBee

Project conventions for AI coding agents and human contributors. **Read this file before your
first change**, then follow the surrounding code. Security, data integrity, translations and the
frontend toolchain are firm requirements. Architecture and style guidance allow proportionate,
well-reasoned choices; explain meaningful departures without turning routine work into an approval
process (§15).

Keep machine-specific setup in untracked local notes such as `AGENTS.local.md` or `CLAUDE.md`,
not here.

## 1. What this project is

FewohBee is an open-source property management system for small and medium-sized guesthouses,
pensions and hotels: reservations, guest data, invoicing, correspondence, statistics, cash book
and calendar sync.

Build for hoteliers, not developers or accountants. Use plain-language labels, sensible defaults
and existing UI patterns. Derive or hide internal details where possible. Self-hosted operators
may have no ops team to recover from a failed update.

## 2. Tech stack

| Area | Choice |
|---|---|
| Language / framework | PHP 8.4+, Symfony 8.1 |
| Persistence | Doctrine ORM 3.x, MySQL / MariaDB, Doctrine Migrations |
| UI | Twig, AssetMapper, Stimulus, Turbo, Bootstrap 5.3 (Materia) |
| PDF / e-invoicing | mPDF, `horstoeko/zugferd` |
| Storage | Flysystem, local + S3 via `oneup/flysystem-bundle` |
| Auth | Symfony Security, WebAuthn, API tokens |
| Quality | PHPUnit 13, PHPStan level 6, Rector |

Use `composer.json` and `importmap.php` for dependency details. **Do not introduce npm, a Node
build toolchain, `package.json`, webpack or another bundler.**

## 3. Repository layout

```text
src/Controller/      HTTP entry points
src/Service/         Business logic, grouped by domain
src/Repository/      DQL and QueryBuilder queries
src/Entity/          Doctrine entities; src/GeoEntity/ uses a separate connection (§11)
src/Dto/             Structured data across boundaries
src/Form/            Symfony form types
src/Workflow/        Automation triggers, conditions and actions
src/Notification/    Notification providers
src/Event/           Domain events; subscribers/listeners in their matching directories
src/Security/        Authentication and authorization
src/Mcp/             MCP server for AI assistants: tools, prompts and their security layer
assets/controllers/  Stimulus behaviour; shared JS in assets/js/, CSS in assets/styles/
templates/           Twig templates, mirroring controllers
translations/        Grouped by product area, not translation domain (§8)
migrations/          VersionYYYYMMDDHHMMSS.php
tests/               Unit/, Functional/, Fixtures/
config/              Symfony configuration
docs/openapi.yaml    Public API specification
bin/run-tests.sh     Test database reset, migrations, sample data and test execution
```

## 4. Running commands

Commands are written plainly; run them natively or through the environment's container/DDEV
wrapper. Check local notes first. If the execution method is still unclear, ask once and record
the answer in those untracked notes.

```bash
composer install
php bin/console debug:router
php bin/console app:first-run                 # initial setup
php bin/console app:first-run --load-sample-data
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
php bin/console asset-map:compile             # required for production deploys
```

## 5. Core principles

- Inspect comparable code before adding a class or pattern. Extend existing behaviour rather
  than duplicating it. Keep changes focused; propose unrelated large refactors separately.
- Features should serve multiple operators or be optional. Avoid assumptions tied to one property,
  country, tax regime or channel. Use `AppSettingsService` or per-`Subsidiary` settings where
  behaviour varies by installation.
- Prefer the simplest design that fits the existing architecture. Do not introduce a registry,
  interface or configuration option for a variation the feature does not actually need.

Search `src/Service/` before building. Common starting points:

| Need | Existing implementation |
|---|---|
| Availability / pricing | `AvailabilityService`, `PriceService`, `PublicPricingService` |
| Reservations / online booking | `ReservationService`, `OnlineBooking/` |
| Invoices / e-invoicing | `InvoiceService`, `EInvoice/`, `En16931Service`, `XRechnungService` |
| Mail / PDF | `MailService`, `MpdfService` |
| Templates | `TemplatesService`, `TemplateSchemaService`, `TemplatePreview/` |
| Storage | `Storage/`, `FileUploader` |
| Accounting | `BookingJournal/` |

## 6. Architecture

### 6.1 Responsibilities

The usual flow is **Controller → Service → Repository → Doctrine**, with entities and DTOs
carrying state and results.

- **Controllers** resolve input, enforce authorization, delegate and render responses. Business
  rules, pricing and multi-step domain workflows belong in services. Length is a signal to review
  responsibilities, not a line limit; straightforward form/response handling can stay together.
  Shared logic belongs in a service, not a helper on another controller.
- **Services** own business logic. Keep domain services stateless and inject dependencies, normally
  through constructor promotion with `private readonly`.
- **Repositories** own application DQL and QueryBuilder queries. Add a named method when a query
  needs reuse or business meaning. Migration SQL belongs in migrations.
- **Entities** hold state and invariants, without service dependencies or orchestration.
- **DTOs** are useful for structured contracts across boundaries. Prefer readonly DTOs when the
  shape matters; a small local array does not automatically need a new class.

### 6.2 Events and extension points

Use domain events in `src/Event/` for independent reactions to an operation. Direct service calls
are appropriate for steps that belong to that operation. Consider whether new events should also
be available as workflow triggers.

For pluggable behaviour, follow the existing **interface + tagged implementations + registry**
pattern. The workflow engine in `src/Workflow/` is the reference; notification and template preview
providers use the same approach. A fixed choice within one implementation can use ordinary
conditionals or an enum; it does not need a plugin architecture.

When extending workflows, mirror the nearest trigger, condition or action, including its config
schema, translations and tests. Symfony autoconfiguration registers implementations. Keep workflow
seeding idempotent and use `WorkflowLogService::hasBeenProcessed()` to deduplicate time-based work.
Consult the implementations for current field types and registration details.

For in-app messages, consider the existing `create_in_app_notification` workflow action before
adding a provider. Derived notifications reflect live work and disappear when resolved; stored
notifications track read state per user. Badge queries run on every page: use counts and cheap
severity checks, and load items only when the panel opens.

### 6.3 User-authored templates

The editor uses `[[ ]]` for variables, `[% %]` for tags, `data-repeat` / `data-repeat-as` for loops
and `data-if` for conditions. **This syntax is not a security boundary:** `TemplatesService`
converts it to Twig before rendering. Do not assume delimiter replacement prevents arbitrary
Twig execution; review allowed capabilities and input handling when changing rendering.

For new template data, check `TemplateSchemaService`, the matching provider in
`src/Service/TemplatePreview/`, and autocomplete in `assets/js/template-autocomplete.js`.
The editor lives in `assets/controllers/template_editor_controller.js`. Keep schema, preview and
autocomplete consistent.

## 7. Security

Guest records contain personal data and invoices contain financial records. Security checks are
part of the implementation, not follow-up work.

### 7.1 Authorization and public endpoints

- Declare protected controllers/actions with `#[IsGranted]`. Path rules in
  `config/packages/security.yaml` are an additional safeguard, not a replacement.
- Everything under `/settings` is admin-only. Respect read-only roles; they must never reach
  mutating actions. Use the role hierarchy in `security.yaml` as the source of truth.
- API endpoints additionally require the appropriate scope through `ApiScopeVoter`; extend the
  scope model when an existing scope does not cover the new capability.
- MCP tools (`src/Mcp/Tool/`) are entry points like controllers: validate input, delegate to a
  service, return data. Every tool declares `#[McpRequiresScope]` (enforced centrally by
  `ScopedReferenceHandler`, fail closed) and reports expected failures as `McpToolException`.
  Tool output leaves the installation: pass guest names, contact data and free text through
  `McpDataFilter`. Do not add tools that delete data, send email or other outbound messages,
  change settings or edit templates. Tool names, descriptions and error messages are English
  protocol text for the model (like `docs/openapi.yaml`); MCP UI in the application follows §8.
- Verify that entities loaded by ID are accessible in the current tenant/subsidiary/user context.
- Public booking, availability and iCal endpoints need input validation, rate limits and abuse
  protection (`PublicBookingAbuseProtectionService`). Use unguessable identifiers such as UUIDs
  and check publication flags. Never expose other guests, internal notes or unpublished prices.

### 7.2 Input, output and CSRF

- Bind query parameters; never concatenate user input into SQL or DQL.
- Keep Twig auto-escaping enabled. `|raw` requires server-side sanitization and a comment explaining
  why raw output is necessary.
- Bind input through Symfony forms or explicit DTOs/field mappings; never hydrate an entity from
  `$request->request->all()`.
- Forms use stateless CSRF protection (`config/packages/csrf.yaml`, token ID `submit`). Normal and
  Turbo submissions use `assets/js/csrf_protection.js`. For `fetch()`, call
  `generateCsrfToken(form)` and add `generateCsrfHeaders(form)` to the request headers; see
  `assets/controllers/mail_settings_controller.js`.
- Hand-built forms/AJAX mutations without a Symfony form must validate an action-specific token
  with `$this->isCsrfTokenValid(...)`. Do not use the legacy `CSRFProtectionService` in new code.
- Uploads go through `FileUploader` / storage services. Validate MIME type and size; never trust
  the client filename.

### 7.3 Credentials, privacy and errors

- Never commit credentials or personal data. Use environment variables / `.env.local`; encrypt
  database-stored secrets using the existing facilities, such as `SmtpPasswordCrypto`.
- Use Symfony Security and `web-auth/webauthn-lib` for authentication. Do not implement custom
  password hashing or authentication; use `hash_equals()` for unavoidable secret comparisons.
- Include new personal-data fields in the GDPR export and keep them out of logs.
- Production responses must not expose stack traces, SQL or internal file paths. Log technical
  details server-side without secrets or guest data; see `ProductionErrorRenderingTest`.
- Consider `EntityChangeLogListener` audit coverage for new security-relevant entities.

## 8. Internationalization

**Application strings, validation messages and enum labels must be complete in German and English
in the same commit**, with matching key sets. Release notes use the separate workflow in §11a.

- Directories under `translations/` group product areas. The **filename** defines the domain:
  `messages.de.yaml` / `messages.de.xlf` use `messages`; `Housekeeping.de.xlf` uses `Housekeeping`.
  Match the area's existing format and pass an explicit domain only when its filename requires it.
- Use lowercase, dot-separated keys describing meaning, such as `workflow.flash.created`.
- Use placeholders instead of concatenated fragments. Handle plural forms, e.g.
  `"{1}eine Nacht|]1,Inf[%count% Nächte"`; render variants server-side for JavaScript consumers.
- Format dates, numbers and currencies through Twig/Intl helpers.
- German is the primary language. New German text uses informal **du**; do not rewrite unrelated
  existing text merely to change its tone.

## 9. Testing and static analysis

Choose tests for the behaviour and risk of the change. Business logic needs unit coverage; HTTP,
authorization and persistence changes need functional coverage. Use both when both are affected.
Documentation-only edits and simple presentational changes do not require artificial tests.
Bug fixes need a regression test at the layer that would have caught the bug.

### 9.1 Unit tests

Use `tests/Unit/`, without a database and normally without booting the kernel. Prefer `createStub()`;
use `createMock()` when verifying interactions with `expects()`.

```bash
php bin/phpunit tests/Unit
```

### 9.2 Functional tests

**Always use the wrapper** for `tests/Functional/`; it resets the database, runs migrations and
seeds sample data before PHPUnit. Do not run functional tests directly against an unprepared database.

```bash
APP_ENV=test bin/run-tests.sh                                  # full suite
APP_ENV=test bin/run-tests.sh tests/Functional/InvoiceTest.php  # one file
```

Agents may run these commands without asking again. Verify `APP_ENV=test`: the wrapper accepts an
environment override and **drops the selected database**. Never use development or production, and
never run two wrappers concurrently. Allow enough time for setup and execution to finish.

### 9.3 Test conventions and checks

- Use final test classes with `TestCase`, `WebTestCase` or `KernelTestCase` as appropriate.
- Name tests by behaviour, follow arrange–act–assert and cover meaningful failure paths,
  especially denied access and invalid input.
- Run affected checks; broaden the suite when shared behaviour or migration changes warrant it.
  Report skipped or unavailable checks and remaining limitations.
- PHPStan runs at level 6. Analyse changed PHP files and add no new findings:

  ```bash
  vendor/bin/phpstan analyse --memory-limit=1G src/Service/FooService.php tests/Unit/FooServiceTest.php
  ```

  Existing findings on the target branch are not introduced by your change. Do not suppress real
  problems with `@phpstan-ignore`; keep unrelated cleanup separate.

## 10. Code style and documentation

- Use `declare(strict_types=1);`, PSR-12 formatting and PSR-4 autoloading (`App\` → `src/`).
- Type parameters, returns and properties. Use generics/array shapes where needed for PHPStan;
  reserve `mixed` for genuinely open contracts.
- Prefer constructor promotion and `private readonly` dependencies, `final` classes unless
  extension is intended, and enums for closed value sets.
- Preserve existing license headers and follow neighbouring files for new ones.
- Write comments and docblocks in English. Explain a class's responsibility when its name and
  interface do not make it clear; self-explanatory classes do not need boilerplate comments.
- Document non-obvious method contracts: business/legal rules, hidden side effects, date
  boundaries, units, meaningful null/empty results and relevant exceptions. Add type annotations
  for structured results. Trivial accessors and obvious framework methods need no prose docblock.
- Comment the reasoning behind complex logic or workarounds, not each instruction. Keep comments
  accurate when behaviour changes.

## 11. Database and migrations

- Ship schema changes through Doctrine migrations in `migrations/`. Generate with
  `doctrine:migrations:diff` where appropriate, then review and edit the SQL.
- **Never modify or delete a released migration.** Fix released behaviour with a new migration.
- During development of an unreleased version, consolidate related changes into existing
  migration files instead of adding a file for every small adjustment. Check the last release tag,
  dependencies and execution order first. Keep an existing filename and remove redundant files.
  Separate substantial data conversions or migrations with distinct rollback guards when useful.
- Consolidation must preserve the resulting schema and data from the last release. Check both
  fresh installation and upgrade paths, including rollback. Already-migrated development databases
  need explicit reconciliation; editing a migration does not execute it again. Never silently reset
  a development database to accommodate a rewritten migration.
- Protect live data: use defaults/nullability that keep existing rows valid, consider table locks,
  and document deliberate destructive changes. Provide a working `down()` where safe; explicitly
  refuse a rollback that would discard data the old schema cannot represent.
- Geo entities live in `src/GeoEntity/` on the separate `geo` connection. Their namespace must not
  start with `App\Entity`, because Doctrine matches mapping prefixes. The connection falls back
  to `DATABASE_URL` when `GEO_DATABASE_URL` is unset.

## 11a. Release notes

- Write concise German notes in `docs/release-notes/<version>.de.md`, preserving upgrade steps
  and user-visible changes. Do not repeat the version in the body. Optional YAML front matter
  can set `date: YYYY-MM-DD`.
- English is generated by the release-notes translation workflow on release pull requests.
  **Do not manually create or update the English file unless explicitly requested.** Both locales
  must be present and reviewed before release; the application has a locale fallback.
- Update `version` in `config/services.yaml` with the release notes. It is exposed as `app_version`;
  the version, both note filenames and release tag must agree.
- `.github/workflows/release-notes-translate.yml` defines translation triggers;
  `.github/workflows/release.yml` builds and validates the GitHub release from these files.
  Do not maintain a separate release body in the GitHub editor.
- New versions appear in the notification bell, without an automatic popup. Opening and closing
  the notes records `users.last_seen_version`.

## 12. Frontend

- Add JS dependencies through `php bin/console importmap:require <package>` and AssetMapper.
- Put behaviour in Stimulus controllers, not inline scripts or global jQuery-style handlers.
  Account for Turbo navigation: initialise through `connect()`, not only `DOMContentLoaded`.
- Reuse existing form macros, tables, filters, cards and modals. Extract repeated Twig markup
  into partials/macros. Use the existing delete popover rather than `confirm()`.
- Bootstrap/Materia is vendored under `public/resources/` and loaded by `templates/base.html.twig`.
  Project CSS lives in `assets/styles/app.css`. Production deploys need `asset-map:compile`.

## 13. Before reporting completion

Check the parts relevant to the change and report any gaps:

- Existing architecture and services reused; scope stays focused and useful across installations.
- Applicable security, privacy and translation requirements met.
- Affected behaviour verified at the appropriate test layer; no new PHPStan findings.
- Non-obvious behaviour documented; migrations and upgrade/rollback paths reviewed when affected.
- User/developer documentation and `docs/openapi.yaml` updated when behaviour or contracts change.

## 14. Scope boundaries

Do not add features to the registration book module; it is slated for removal. Do not commit
runtime-generated artifacts, environment files containing secrets, dumps, personal data or local
setup notes. Avoid unrelated refactors and cleanup in a feature change.

## 15. When you are unsure

For routine implementation choices, follow existing patterns and use judgment. State consequential
assumptions and explain why a departure from a guideline fits the task.

Ask before proceeding when an unresolved choice materially changes the data model, security model,
public interface or risks existing data beyond the agreed scope. **Do not ask again for a change
already explicitly requested or approved.** If the requested outcome is clear and the remaining
choice is ordinary implementation detail, proceed and make the result reviewable.
