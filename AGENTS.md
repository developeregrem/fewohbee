# AGENTS.md — Working on FewohBee

Instructions for AI coding agents (Claude Code, Codex, Cursor, Aider, …) contributing to this
repository. Human contributors are welcome to read it too — it is simply the house style, written
down.

**Read this file completely before your first change.** It describes *how* things are built here,
not just *what* exists. When in doubt, look at how the surrounding code solves the same problem and
follow it.

> **Scope note:** This file is intentionally environment-agnostic. It contains no machine-specific
> paths or container commands, because every contributor runs this project differently (Docker,
> DDEV, native PHP, …). Keep personal setup notes in an untracked local file
> (e.g. `AGENTS.local.md` or `CLAUDE.md`) instead of here.

---

## 1. What this project is

FewohBee is an open-source **property management system (PMS)** for small and medium-sized
guesthouses, pensions and hotels. It replaces pen-and-paper or spreadsheet room management with
reservations, guest data, invoicing, correspondence, statistics, cash book and calendar sync.

**Who uses it:** hoteliers and guesthouse owners — *not* accountants, not developers, not
enterprise operators. This single fact drives most product decisions:

- Features must be understandable without domain training. Accounting, tax and e-invoicing UIs in
  particular must be usable by a layperson, with sane defaults and plain-language labels.
- Anything that can be derived, defaulted or hidden should be. Do not expose internal mechanics in
  the UI just because they exist in the model.
- Self-hosted installations are the norm. Assume no ops team, no monitoring, no one to recover a
  broken migration at 2am.

---

## 2. Tech stack

| Area | Choice |
|---|---|
| Language | PHP **8.4+** (`declare(strict_types=1);` everywhere) |
| Framework | **Symfony 8.1** components / FrameworkBundle |
| Persistence | Doctrine ORM 3.x on MySQL / MariaDB, Doctrine Migrations |
| Templating | Twig (`templates/`) |
| Frontend | Symfony **AssetMapper** (`importmap.php`) + **Stimulus** + **Turbo** |
| PDF | mPDF |
| E-invoicing | `horstoeko/zugferd` (ZUGFeRD / XRechnung / EN 16931) |
| Files | Flysystem (local + S3 via `oneup/flysystem-bundle`) |
| Auth | Symfony Security, WebAuthn (`web-auth/webauthn-lib`), API tokens |
| Tests | PHPUnit 13 |
| Static analysis | PHPStan (level 6), Rector |

**There is no npm/webpack build.** Frontend dependencies are declared in `importmap.php` and served
by AssetMapper. Never introduce a Node toolchain, `package.json`, or a bundler.

---

## 3. Repository layout

```
src/
  Controller/      HTTP entry points — thin, no business logic
  Entity/          Doctrine entities (the domain model)
  GeoEntity/       Entities on the separate "geo" connection — see §11
  Repository/      Doctrine repositories — all DQL/QueryBuilder lives here
  Service/         Business logic, grouped in subdirectories per domain
  Dto/             Data transfer objects (API payloads, structured results)
  Form/            Symfony form types
  Workflow/        Automation rules engine (Triggers / Conditions / Actions)
  Event/           Domain events
  EventSubscriber/ Symfony event subscribers
  EventListener/   Doctrine + kernel listeners
  Security/        Authenticators, voters, API token handling
  Validator/       Custom constraints + validators
  Twig/            Twig extensions
  Command/         Console commands
  Interfaces/      Cross-cutting contracts
  Exception/       Domain exceptions
  DataFixtures/    Sample data
assets/
  controllers/     Stimulus controllers
  js/              Shared JS utilities
  styles/          CSS
templates/         Twig templates, mirroring the controller structure
translations/      One directory per translation domain — see §8
migrations/        Doctrine migrations (VersionYYYYMMDDHHMMSS.php)
tests/
  Unit/            No database, no kernel booting where avoidable
  Functional/      WebTestCase / KernelTestCase — requires a prepared database
  Fixtures/        Test fixture files
config/            Symfony configuration
docs/openapi.yaml  Public API specification
bin/run-tests.sh   Resets the test database and runs the suite
```

---

## 4. Running commands

Commands in this document are written **plainly**, e.g.:

```bash
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
php bin/console debug:router
composer install
```

Execute them the way *your* environment requires — inside your PHP container, via DDEV, or
natively. If you are an agent and do not know how, **ask once** and record the answer in your local
(untracked) notes file rather than in this file.

Useful project-specific commands:

```bash
php bin/console app:first-run                # initial setup wizard (users, accommodation, seed data)
php bin/console app:first-run --load-sample-data
php bin/console asset-map:compile            # required for production deploys
php bin/console doctrine:migrations:diff     # generate a migration from entity changes
```

---

## 5. Core principles for every change

These are the acceptance criteria for new features. A change that fails any of them is not done.

### 5.1 Fit the existing architecture

Build on the structure that is already there. Before writing a new class, find the two or three
places that solve the closest existing problem and mirror their shape. New top-level concepts,
parallel abstractions, or a second way of doing something that already has a way are rejected by
default. If the existing architecture genuinely does not fit, say so explicitly and propose the
change *before* implementing it.

### 5.2 Reuse — do not reinvent

There are ~60 services in `src/Service/`. Search before you build. Notably:

| Need | Use |
|---|---|
| Room availability / conflicts | `AvailabilityService` |
| Pricing | `PriceService`, `PublicPricingService` |
| Reservations | `ReservationService` |
| Invoices | `InvoiceService`, `EInvoice/`, `En16931Service`, `XRechnungService` |
| Sending mail | `MailService` |
| PDF rendering | `MpdfService` |
| Template rendering / preview | `TemplatesService`, `TemplateSchemaService`, `TemplatePreview/` |
| File storage | `Storage/`, `FileUploader` |
| App settings | `AppSettingsService` |
| Booking journal / accounting | `Service/BookingJournal/` |

Duplicating logic that already exists in a service is the most common review rejection. If a service
*almost* fits, extend it (with tests) rather than forking its logic.

### 5.3 Build generic features, not island solutions

This is shared open-source software. Every feature should be useful to a broad set of operators, or
be cleanly optional:

- No hardcoded assumptions about one property, one country, one tax regime, one channel partner.
- Configurable via existing settings mechanisms (`AppSettingsService`, per-`Subsidiary` config)
  rather than constants in code.
- Where behaviour varies, prefer an **extension point** (interface + registry, see §6.3) over an
  `if` chain that grows forever.
- Features that only make sense for a single installation belong in a fork, not here.

### 5.4 Multilingual from the start

**German and English must both be complete in the same commit.** See §8.

### 5.5 Security is a first-class requirement

Not an afterthought, not a follow-up ticket. See §7.

### 5.6 Tested

Unit tests for logic, functional tests for HTTP behaviour and persistence. See §9.

### 5.7 Maintainable

**Every method that isn't self-explanatory gets a short docblock describing what it does**, and
complex logic gets inline comments explaining *why*. Comments in English. See §10.

---

## 6. Architecture guidelines

### 6.1 Layering

```
Controller  →  Service  →  Repository  →  Doctrine
     ↓            ↓
   Twig         Entity / DTO
```

**Controllers** are thin. They:
- resolve input (route params, `Request`, forms),
- enforce authorization,
- delegate to a service,
- render a template or return a `Response` / `JsonResponse`.

They do **not** contain business rules, DQL, price math, or multi-step domain workflows. If a
controller method exceeds roughly 30 lines, the excess almost certainly belongs in a service.

**Services** own the business logic and are stateless. Inject dependencies via constructor
promotion with `private readonly`:

```php
class AvailabilityService
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly RoomBlockRepository $roomBlockRepository,
    ) {
    }
}
```

**Repositories** own all query building. No DQL or `QueryBuilder` in controllers or services —
add a named, documented method to the repository instead.

**Entities** hold state and invariants, not orchestration. Keep them free of service dependencies.

**DTOs** (`src/Dto/`) carry structured data across boundaries — API responses, computed results,
form models. Prefer a readonly DTO over an associative array whenever the shape matters.

### 6.2 Events over coupling

Domain events live in `src/Event/` and are dispatched when something meaningful happens
(`ReservationCreatedEvent`, `InvoiceStatusChangedEvent`, …). If your feature needs to *react* to
something rather than *cause* it, subscribe to an event instead of editing the originating service.
When adding a new domain event, consider whether it should also become a workflow trigger (§6.3).

### 6.3 Extension points: interface + registry

The established pattern for pluggable behaviour is **an interface, implementations tagged by
autoconfiguration, and a registry that looks them up**. No base classes, no `switch` statements.

The **Workflow engine** (`src/Workflow/`) is the reference implementation and the primary way to
make features automatable by users:

- `WorkflowTriggerInterface` — when does it fire? (event-driven or time-based)
- `WorkflowConditionInterface` — should it run? (evaluated with short-circuit)
- `WorkflowActionInterface` — what happens?
- `WorkflowActionRegistry` / `WorkflowConditionRegistry` / `WorkflowTriggerRegistry` — typed lookup
- `WorkflowEngine` — evaluates conditions, executes the action, logs the result
- `WorkflowEventSubscriber` — maps Symfony events to trigger types
- `WorkflowSeeder` — creates/updates system and example workflows, **idempotently**
- `WorkflowLogService::hasBeenProcessed()` — deduplication; time-based actions must use it

Config schemas are declared by the trigger/condition/action itself, using the field types `text`,
`number`, `email`, `select` (with `options`), `template_select`, `accounting_account_select`,
`reservation_status_select`, with conditional visibility via `showIf: {key, value}`.

**Adding a trigger, condition or action:**

1. Create a class implementing the interface in the matching subdirectory.
2. Symfony autoconfiguration registers it — no manual service wiring.
3. Add `de` + `en` keys in `translations/Workflow/messages.{de,en}.yaml`.
4. Add a unit test in `tests/Unit/Workflow/`.
5. Optionally add an example workflow to `WorkflowSeeder`.

The **template preview** system follows the same shape: implement `ITemplatePreviewProvider` in
`src/Service/TemplatePreview/` and it is picked up automatically.

Use this pattern for any new pluggable dimension (export formats, import sources, payment
adapters, channel connectors).

### 6.4 The custom template system

The user-facing template editor (letters, invoices, emails) uses a **pseudo-Twig syntax** so that
user-authored templates can never execute arbitrary Twig:

- `[[ ]]` instead of `{{ }}` for variables
- `[% %]` instead of `{% %}` for tags
- `data-repeat` / `data-repeat-as` for loops
- `data-if` for conditionals

Key files:

- `assets/controllers/template_editor_controller.js` — Tiptap visual editor + CodeMirror code mode
- `assets/js/template-autocomplete.js` — CodeMirror autocomplete
- `src/Service/TemplateSchemaService.php` — builds the variable schema via PHP Reflection and
  Doctrine ORM attributes; schema types are `scalar`, `date`, `entity`, `collection`, `array`
- `src/Service/TemplatePreview/` — preview providers per template type
- Schema endpoint: `GET /settings/templates/schema/{templateTypeId}`

When you expose a new entity or field to templates, extend the schema service *and* the matching
preview provider, and confirm the autocomplete picks it up.

---

## 7. Security requirements

Security is the highest-priority non-functional requirement in this project. Guest data is
personal data (GDPR); invoices are financial records.

### 7.1 Authorization on every entry point

Every controller or action must declare its access requirement. Role hierarchy is defined in
`config/packages/security.yaml`:

```
ROLE_ADMIN → ROLE_RESERVATIONS, ROLE_CUSTOMERS, ROLE_INVOICES,
             ROLE_REGISTRATIONBOOK, ROLE_STATISTICS, ROLE_CASHJOURNAL, ROLE_OPERATIONS
ROLE_RESERVATIONS → ROLE_RESERVATIONS_RO
```

```php
#[Route('/settings/workflows')]
#[IsGranted('ROLE_ADMIN')]
class WorkflowController extends AbstractController { … }
```

- Path-level rules in `security.yaml` `access_control` are a safety net, **not** a substitute for
  `#[IsGranted]` on the controller.
- Anything under `/settings` is admin-only.
- API endpoints are additionally scope-gated via `ApiScopeVoter`
  (`#[IsGranted('API_SCOPE_RESERVATIONS_READ')]` etc.). A new API endpoint needs a scope.
- Read-only variants exist (`ROLE_RESERVATIONS_RO`) — respect them; do not let a read-only role
  reach a mutating action.

### 7.2 Public / unauthenticated surfaces

Online booking, public availability and iCal feeds are reachable without a login. For these:

- Rate-limit and abuse-protect (`symfony/rate-limiter`,
  `PublicBookingAbuseProtectionService`).
- Use unguessable identifiers (UUIDs) — never sequential IDs — and check the "is public" flag
  before returning anything.
- Never leak internal data (other guests, prices you did not intend to publish, internal notes)
  through a public response.
- Validate *everything*. Treat all input as hostile.

### 7.3 Standard hygiene

- **SQL injection:** always parameter binding via `QueryBuilder`/DQL. Never string-concatenate user
  input into a query.
- **XSS:** Twig auto-escaping stays on. `|raw` requires a written justification in a comment and
  server-side sanitization of the value.
- **CSRF:** Symfony forms handle this automatically. For hand-built forms and AJAX endpoints use
  `$this->isCsrfTokenValid('some-action-' . $id, $request->request->get('_token'))`.
  *(A legacy `CSRFProtectionService` still exists in older controllers — do not use it in new code.)*
- **Mass assignment:** bind through Symfony Form types or explicit DTOs; never hydrate an entity
  straight from `$request->request->all()`.
- **IDOR:** when loading an entity by id, verify it belongs to the current tenant/subsidiary/user
  context before acting on it.
- **Secrets:** never commit credentials. Use `.env.local` / environment variables. Secrets stored in
  the database (e.g. SMTP passwords) are encrypted — see `SmtpPasswordCrypto`.
- **File uploads:** go through `FileUploader` / the Flysystem storage services. Validate MIME type
  and size; never trust the client-supplied filename.
- **Errors:** no stack traces, SQL, or file paths in production responses (there is a functional
  test guarding this — `ProductionErrorRenderingTest`). Log details server-side instead.
- **Auth:** password hashing and WebAuthn are handled by Symfony Security and
  `web-auth/webauthn-lib`. Do not hand-roll authentication or token comparison; use
  `hash_equals()` for any secret comparison you cannot avoid.
- **Audit:** `EntityChangeLogListener` records entity changes. Consider whether a new
  security-relevant entity should be covered.

### 7.4 Personal data

Guest records are GDPR-relevant. New personal-data fields must be included in the existing GDPR
export, and must not be written to logs.

---

## 8. Internationalization

**Every user-facing string is translated, in both `de` and `en`, in the same commit.** No exceptions,
no "English only for now".

- Translations live in `translations/<Domain>/messages.{de,en}.yaml` — one directory per domain
  (`Reservations`, `Invoices`, `Workflow`, `Housekeeping`, …). Some legacy domains use `.xlf`;
  match whatever the domain already uses.
- Reference the domain explicitly in Twig: `{{ 'workflow.page_title'|trans({}, 'Workflow') }}`
- Keys are lowercase, dot-separated, and describe *meaning* rather than the English text:
  `workflow.flash.created`, not `workflow.automation_was_created`.
- Both files must contain the **same set of keys**. A key present in `de` but missing in `en` is a
  bug.
- Never concatenate translated fragments — use placeholders (`%count%`, `%name%`).
- Dates, numbers and currency are formatted through Twig/Intl helpers, never hand-formatted.
- German is the primary product language and uses informal address ("du"), matching the existing
  strings. Keep the tone consistent with neighbouring keys.
- Validation messages and enum labels need translations too.

---

## 9. Testing

Both layers are required for a feature to be considered complete.

### 9.1 Unit tests — `tests/Unit/`

- Cover business logic, calculations, conditions, actions, mappers, DTOs, edge cases.
- No database. Mock repositories and collaborators.
- **Agents may run these themselves**, they are fast and side-effect free:

```bash
php bin/phpunit tests/Unit
```

### 9.2 Functional tests — `tests/Functional/`

- Cover controllers, HTTP status codes, authorization, persistence, API contracts.
- They **require a prepared database**. Always run them through the wrapper script, never by
  pointing PHPUnit at `tests/Functional` directly:

```bash
bin/run-tests.sh                                  # full suite
bin/run-tests.sh tests/Functional/InvoiceTest.php # a single file
```

- The script drops and recreates the test database, runs all migrations, seeds sample data via
  `app:first-run --load-sample-data`, and only then invokes PHPUnit. Running PHPUnit directly
  against `tests/Functional` will fail or produce misleading results, because the database state it
  expects has not been built.
- **Agents may run this**, but be aware it takes a while (database reset + migrations + suite).
  Set a generous command timeout rather than letting it be killed halfway through — an aborted run
  leaves the test database in a partial state.
- It targets `APP_ENV=test`, so it only ever touches the test database — but it *is* destructive to
  that database. Never point it at a development or production environment.
- While it runs, don't start a second test run in parallel; both share the same database.

### 9.3 Conventions

- `final class SomethingTest extends TestCase` (unit) or `WebTestCase` / `KernelTestCase`
  (functional).
- Descriptive method names: `testGetCalendarReturnsNotFoundForPrivateSync()`.
- One behaviour per test; arrange–act–assert.
- Test the failure paths, especially authorization denials and invalid input.
- New bug fix ⇒ add the regression test that would have caught it.

### 9.4 Static analysis

```bash
vendor/bin/phpstan analyse    # level 6, must stay green
```

Do not add `@phpstan-ignore` or baseline entries to silence a real problem.

---

## 10. Code style & documentation

- `declare(strict_types=1);` at the top of every PHP file.
- PSR-12 formatting, PSR-4 autoloading (`App\` → `src/`).
- Constructor property promotion with `private readonly` for dependencies.
- Type-hint everything: parameters, return types, properties. Avoid `mixed`; use generics
  annotations (`@param list<Reservation> $reservations`) where PHPStan needs them.
- Prefer `final` for new classes that are not designed for extension.
- Enums (backed) instead of class constants for closed value sets.
- Some files carry a license header comment — preserve it when editing such a file; follow the
  surrounding file for new ones.

### Documentation rules (non-negotiable)

**All comments and docblocks are written in English.**

**Every method whose purpose is not obvious from its signature gets a docblock describing what it
does.** The test is simple: *can a reader understand what this method does, and what it is for,
from its name, parameters and return type alone?* If not, write the docblock. One or two sentences
is usually enough — the point is that nobody has to reverse-engineer intent from the body.

```php
/**
 * Full room-level check. A room block always makes the room unavailable,
 * even for multipleOccupancy rooms (blocks act on the physical room).
 */
public function isRoomAvailable(
    Appartment $room,
    \DateTimeInterface $start,
    \DateTimeInterface $end,
    int $numberOfPersons = 0,
): bool {
```

**No docblock needed** — a comment that only restates the signature is noise, and trains readers to
skip comments that *do* matter:

- Entity getters and setters, and other trivial accessors
- Short, self-explanatory methods where the name says everything (`isActive()`, `getFullName()`)
- Framework boilerplate whose contract is defined by the interface it implements
- Controller actions whose route, name and single service call already tell the whole story

```php
// Noise — adds nothing over the signature:
/**
 * Returns the name.
 */
public function getName(): string
```

**Always write one, regardless of length,** when any of these apply:

- The method has side effects that the name does not reveal (writes to the database, sends mail,
  mutates a passed-in object, clears a cache)
- It encodes a business or legal rule (tax, pricing, tourist tax, occupancy, e-invoicing)
- Parameters have non-obvious semantics — units, boundary handling, what `null` means, which
  parameter combinations are valid
- It can throw, or returns `null` / an empty result in a meaningful special case
- The return value needs a shape annotation for PHPStan (`@return list<Reservation>`,
  `@return array<string, Invoice>`)
- The implementation is short but the *reasoning* behind it is not

Additionally:

- Add a class-level docblock explaining the class's responsibility. This one has no exceptions —
  it is the entry point for anyone meeting the class for the first time.
- Add **inline comments for non-obvious logic** — business rules, tax and date arithmetic, boundary
  handling (e.g. "reservation periods are half-open intervals: departure day is free"), workarounds,
  and anything a future reader would otherwise have to guess.
- Comment the *why*, not the *what*. `// increment counter` is noise; `// guests below
  minFullPayers always pay the full rate` is valuable.
- Keep comments truthful — update them when you change the code. A stale comment is worse than none.

When in doubt, write it. The exceptions above are for genuinely trivial code, not an invitation to
leave complex methods undocumented.

---

## 11. Database & migrations

- Schema changes always ship as a Doctrine migration in `migrations/`
  (`VersionYYYYMMDDHHMMSS.php`). Generate with `doctrine:migrations:diff`, then **review and edit**
  the generated SQL — never commit it unread.
- Migrations must be safe on live self-hosted data: no destructive operation without a deliberate,
  documented decision; provide a working `down()`.
- Never modify an already-released migration. Add a new one.
- Give new columns sensible defaults so existing rows stay valid.
- Watch for large-table locks; hosts run on modest hardware.
- **Geo data** lives on a separate `geo` Doctrine connection. Its entities belong in
  `src/GeoEntity/` — *not* under `src/Entity/`, and the namespace must not start with `App\Entity`
  (the mapping configuration matches on the string prefix). The connection falls back to
  `DATABASE_URL` when `GEO_DATABASE_URL` is not set.

---

## 11a. Release notes

Every release ships its notes with the application, so hosted customers see what changed without
visiting GitHub.

- Notes live in `docs/release-notes/` as `<version>.<locale>.md`, e.g. `4.12.0.de.md`. The version
  comes from the filename — do not repeat it inside the document.
- **German is authored by hand and has priority**; English is generated from it. Both locales ship.
  A version with only one locale still works: `ReleaseNotesService` falls back.
- An optional YAML front matter block carries the release date:

  ```markdown
  ---
  date: 2026-09-15
  ---
  ```

- The version itself is the `version` parameter in `config/services.yaml`, exposed to Twig as the
  `app_version` global. **Bump it in the same commit that adds the release notes file** — the two
  must agree, or the "what's new" announcement never fires.
- A new version announces itself **in the notification bell**, not as a popup, and stays there until
  the user opens the notes and closes them again — that writes `users.last_seen_version`. There is
  deliberately no auto-opening modal: a popup on page load gets dismissed by reflex before it is
  read, and it would have to be suppressed on every subsequent page view anyway.

---

## 12. Frontend

- **AssetMapper only.** Add JS dependencies with `php bin/console importmap:require <package>`.
  No npm, no bundler, no `package.json`.
- Behaviour lives in **Stimulus controllers** under `assets/controllers/`, wired via
  `data-controller` attributes. No inline `<script>` blocks with logic, no global jQuery-style
  soup.
- **Turbo** is active — assume partial page updates. Code that must run after navigation belongs in
  a Stimulus `connect()`, not in a `DOMContentLoaded` handler.
- Twig templates in `templates/` mirror the controller structure. Extract reusable markup into
  partials/macros rather than copy-pasting.
- For delete confirmations use the existing **delete popover** component — not `confirm()`.
- Styling is **Bootstrap 5.3** (Materia theme), vendored under `public/resources/` and loaded in
  `templates/base.html.twig` — not via the importmap. Project CSS lives in `assets/styles/app.css`.
- Keep the UI consistent with existing screens: existing form macros, existing table/filter
  patterns, existing card and modal markup.
- Production deploys need `php bin/console asset-map:compile`.

---

## 13. Definition of Done

Before you report a feature as complete, verify every line:

- [ ] Fits the existing architecture; no parallel abstraction introduced
- [ ] Existing services reused where applicable; no duplicated logic
- [ ] Generic and configurable — useful beyond a single installation
- [ ] Controller thin, logic in a service, queries in a repository
- [ ] Authorization declared (`#[IsGranted]` / scope), input validated, CSRF handled
- [ ] No new XSS / SQLi / IDOR surface; secrets not committed or logged
- [ ] `de` **and** `en` translations complete, same key set
- [ ] Unit tests written and passing
- [ ] Functional tests written and passing via `bin/run-tests.sh`
- [ ] PHPStan level 6 clean
- [ ] Class docblock present; docblocks on all non-trivial methods; non-obvious logic commented,
      in English
- [ ] Migration added and reviewed, if the schema changed
- [ ] Documentation updated if behaviour or configuration changed

---

## 14. Things to avoid

- Introducing npm, webpack, Vite or any Node build step.
- Business logic in controllers or Twig templates.
- Raw SQL string concatenation, or DQL outside repositories.
- New features in the **registration book** module — it is slated for removal.
- Shipping English-only strings "for now".
- Running the functional test suite (it resets the maintainer's database) without being asked.
- Editing released migrations.
- Silencing PHPStan instead of fixing the finding.
- Large opportunistic refactors bundled into a feature commit — propose them separately.
- Committing generated artifacts, `.env.local`, dumps, or personal data files.

---

## 15. When you are unsure

State the ambiguity, pick the interpretation that is most consistent with the existing code, and
say which assumption you made. For anything that changes the data model, the security model, or a
public interface, ask **before** implementing.
