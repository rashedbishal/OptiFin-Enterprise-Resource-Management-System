# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

A multi-tenant SaaS ERP built on the Laravel Livewire starter kit (Laravel 13, PHP 8.3, Livewire 4, Flux UI 2, Tailwind v4). It has a Platform side (`/admin/*`, run by the vendor) and a Tenant side (`/*`, run by each client), plus a Lead Management module ported from a sibling project.

**Before editing or planning anything under `app/`, `resources/views/livewire/`, read `.ai/rules/index.md` and every rule file whose glob matches the path in scope, and grep `.ai/rules` for related keywords.** These are settled decisions and traps, not optional background — see summaries below but treat the files themselves as authoritative.

## Commands

```bash
composer run dev          # artisan serve + queue + pail + vite, all at once (primary local dev loop)
npm run dev                # vite only
npm run build

vendor/bin/pint --dirty --format agent   # format PHP after any change (run before finishing, per Boost rules)
phpstan analyse                          # composer run types:check
php artisan test --compact               # full suite
php artisan test --compact --filter=testName
vendor/bin/pest path/to/FileTest.php     # single file
composer run test                        # config:clear + lint:check + types:check + full test suite (CI gate)

php artisan route:list
php artisan leads:fetch-emails           # poll IMAP lead sources, create Lead + ProcessEmailLeadSource job
php artisan leads:cleanup-attachments    # delete orphaned lead attachment files
php artisan leads:send-alert-notifications  # daily sweep: bell notifications for active meeting/follow-up alerts (scheduled 08:00)
```

`README.md` covers only a runbook for testing Facebook Lead Ads (Instant Forms) ingestion — no general project docs there.

## Architecture

### Dual-guard auth — two people tables, never a shared `User`
- `App\Models\PlatformUser` (table `platform_users`, guard `platform`, routes under `/admin`, login `admin/login`) — exactly one `mother_admin` (enforced by a DB partial unique index, bypasses all authorization via `Gate::before`) plus Platform Admins.
- `App\Models\TenantUser` (table `tenant_users`, guard `web`, default Fortify `/login`) — Client Admins/Users, always scoped to `tenant_id`.
- Both share one Role → Module → Permission engine (`roles`/`modules`/`permissions`/`role_modules`/`role_permissions`/`tenant_modules` — see `App\Services\RoleService` and `App\Concerns\HasModulePermissions`). `modules.scope` (platform|tenant) gates which catalog a role can draw from.
- All IDs are ULIDs. Extend `PlatformUser`/`TenantUser` and their existing policies/observers — don't reintroduce a generic `User` model.
- `PlatformUser`/`TenantUser` use a `#[Fillable([...])]` PHP attribute instead of `protected $fillable`. Adding a mutable column requires adding it there too, or `create()`/`update()` silently drops it with no error.

### Route structure
- `routes/web.php` — tenant area, behind `auth`, `verified`, `tenant.identify`, `tenant.active`; module-gated routes (e.g. Lead Management) additionally behind `module:{slug}` (see `EnsureModuleAccess` middleware).
- `routes/admin.php` — platform area, prefixed `/admin`, guard `platform`.
- `routes/api.php` — public webhook intake for lead capture (Facebook Messenger, WhatsApp, Instagram, website form), keyed by `{tenant_code}` (not a slug column) and protected by `VerifyMetaWebhookSignature`.
- `routes/channels.php` — Reverb broadcast auth channels (`tenant.{tenantId}`, `lead.{lead}`).
- `App\Events\LeadReceived` (new lead — also drives auto-assignment and the "new lead" notification, so it must fire exactly once, on creation) vs `App\Events\LeadUpdated` (broadcast-only, for follow-up changes to an existing lead) are deliberately separate events despite both broadcasting to the same channels — don't collapse them.

### Livewire conventions (see `.ai/rules/livewire.md`, `.ai/rules/views-livewire.md`)
- A class-based full-page component named `Index` inside a subfolder resolves its view with the trailing `index` segment stripped: `App\Livewire\Tenant\Users\Index` → `resources/views/livewire/tenant/users.blade.php`, **not** `tenant/users/index.blade.php`. Non-`Index` class names keep their full path.
- `Route::livewire()` page components are auto-wrapped in a layout by Livewire — the component's Blade view must be only its single root element's inner content, with no `<x-layouts::*>` wrapper, or Livewire throws "Multiple root elements detected". Page `<title>` comes from `#[Title('...')]` on the class. Platform components use `#[Layout('layouts.admin')]`; tenant components use the default `layouts::app`; guest/auth pages use `#[Layout('layouts.auth')]`.

### Lead Management module
Ported from a sibling project (`OpticoreLeadManagementHub`, Inertia/React) onto this app's Blade/Livewire + Tenant/TenantUser/Role/Module/Permission stack. Models: `Lead`, `LeadSource`, `LeadAssignment`, `LeadStatus` (Platform-Admin-managed catalog, not an enum — see Multi-currency below), `LeadStatusLog`, `LeadNote`, `LeadAttachment`, `LeadDetailUpdateLog`. Enums: `LeadSourceType`, `LeadPriority`, `LeadDistributionMode`, `AutoDistributionMethod`. Key pieces: `App\Services\LeadAssignmentService`, webhook capture in `App\Http\Controllers\Api\LeadWebhookController`, IMAP polling via `leads:fetch-emails` → `App\Jobs\ProcessEmailLeadSource`. Lead sources are configured by the Platform Admin at `/admin/clients/{tenant}/lead-sources` (`App\Livewire\Platform\Clients\LeadSources`); the tenant-side `/lead-sources` view is read-only. If extending this module or building another "external capture + per-client config" feature, check the reference project's approach first.

Facebook Lead Ads (Instant Forms) submissions land on the same webhook URL as Messenger (`/api/webhook/facebook/{tenant_code}`) as a `leadgen` change event rather than a `messaging` event, and — unlike attachment downloads — are processed synchronously inside the webhook request, not queued. See `README.md` for the full setup/debugging runbook (`meta:subscribe-leadgen`, `leads:fetch-test`, and the `/admin/clients/{tenant}/lead-sources/{leadSource}/meta-permissions` scope-check route).

`App\Livewire\Tenant\Leads\Index` serves two different views from one component depending on role, not two separate Livewire components: `render()` returns `livewire.tenant.leads` (table) for Client Admins and `livewire.tenant.leads-kanban` (board, via `boardLeads()`/`boardLeadCounts()`) for Client Users, both built on the same `filteredQuery()`. Facebook Lead Ads leads are excluded from the admin table (aggregated into a "View All" row pointing at `SourceLeads` instead) but appear as normal cards on a Client User's board.

### Real-time notifications
The notification bell (`App\Livewire\Tenant\NotificationBell`, embedded once in `layouts/app.blade.php` and once in `layouts/app/sidebar.blade.php` — never per-page, and never on the Platform side) surfaces Laravel's standard "database" notification channel and updates live via Reverb rather than polling: `App\Events\UserNotified` broadcasts to a user's own private channel (`tenant-user.{id}`) the instant any notification is created, fired by `App\Listeners\BroadcastUserNotification` listening on Laravel's internal `NotificationSent` event — not dispatched manually from each call site — so any future notification type gets real-time delivery for free. The broadcast carries no payload; the bell just re-queries on receipt. Clicking a notification routes through `NotificationRedirectController` (`App\Services\NotificationUrlResolver` resolves the target) rather than a raw link, so "mark read" and "redirect" happen as one atomic request instead of a `wire:navigate`/`wire:click.renderless` race. `App\Services\LeadAdminNotifier` fans a notification out to every *other* active Client Admin in a lead's tenant (never the actor). The daily `leads:send-alert-notifications` command is the bell's counterpart to the Kanban card's live meeting/follow-up shimmer (`Lead::meetingAlertActive()`/`followUpAlertActive()`, recomputed on read, nothing persisted) — it persists one notification per lead per recipient per day for as long as that shimmer is active.

### Multi-currency
`App\Models\Currency` is a Platform-Admin-managed catalog (same shape as `LeadStatus`): not tenant-scoped itself, shared across tenants that opt in via the `tenant_currencies` pivot (`Tenant::currencies()`). Tenants pick which currencies they support at `/admin/clients/{tenant}/lead-sources` (`LeadSources::toggleCurrency()`); those choices populate the per-field currency dropdowns on `estimated_budget`/`deal_value`. A currency in use (enabled by any tenant, or referenced by a Lead's `estimated_budget_currency_id`/`deal_value_currency_id`) can't be deleted — enforced in `App\Livewire\Platform\LeadManagement\Settings::deleteCurrency()`. The currencies-catalog migration unconditionally seeds a "USD" row, so it's a permanent baseline fixture in every test (same trap as `LeadStatus`'s legacy-seeded statuses) — see `.ai/rules/migrations-models.md`.

### Skills
Domain skills exist under `**/skills/**` (Livewire, Flux UI, Fortify, Echo/broadcasting, Tailwind, testing, Laravel best practices). Activate the matching skill proactively when working in that domain rather than waiting to get stuck.

## Laravel Boost Guidelines

The block below is maintained by `composer post-update-cmd` (`artisan boost:update`) — don't hand-edit it; if it drifts, regenerate it rather than patching it manually.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.3. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
