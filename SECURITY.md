# Security policy

Packstub Tenancy sits at the isolation boundary between your tenants: it decides which database a request connects to, which server a new tenant is provisioned on, and which users belong to which tenant. We treat every report in that area with the highest urgency, and we ask that you report vulnerabilities privately so a fix can ship before details become public.

## Supported versions

Security fixes are released for the latest minor version of each supported release line.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | Yes                |

## Reporting a vulnerability

Email **security@packstub.dev**. Do not open a public GitHub issue, discussion, or pull request for a security problem — public reports put every licensed installation at risk before a fix exists.

Include what you can:

- The package version (`composer show packstub/filament-tenancy`) and your identification mode (`subdomain` or `path`).
- A description of the issue and its impact — ideally a minimal reproduction.
- Whether the database pool is enabled, if the report touches tenant placement or connections.

What to expect:

- **Acknowledgment within 48 hours.** You will hear from a human, not an autoresponder.
- We will keep you updated as we validate, develop, and release a fix.
- **Coordinated disclosure:** we ask that you keep the details private until a patched release is available to customers. We will credit you in the release notes unless you prefer otherwise.

## Scope

Reports in these areas are treated as **highest priority**:

- **Tenant isolation** — any way for one tenant to read, write, or infer data belonging to another tenant: connection bleed between requests, a tenant landing on the wrong database, cached state crossing tenant boundaries, or the `EnsureTenantIsReady` middleware exposing a tenant that is not yours.
- **Tenant placement and provisioning** — influencing which pool server a tenant is assigned to, injecting connection details into provisioning, or escalating through the provisioning queue jobs.
- **Membership** — bypassing the `tenant_user` ownership check to access a panel tenant you are not attached to.

Also in scope: the Filament pages, widgets, and Livewire components this package ships (onboarding, provisioning, profile, `DatabasePoolOverview`), the artisan commands, and the published views.

Out of scope: vulnerabilities in Laravel, Filament, Livewire, or stancl/tenancy themselves (report those upstream — we will still ship a mitigation if the exposure is specific to this package), issues requiring an already-compromised admin account or server, and missing hardening in your own application code.

## Hardening guidance for integrators

The Stancl base tenant model — which the shipped `Packstub\Tenancy\Models\Tenant` extends — is **unguarded by design** (`protected $guarded = [];`). That means every attribute is mass-assignable, including the `tenancy_db_*` virtual attributes (`tenancy_db_connection`, `tenancy_db_name`, `tenancy_db_username`, `tenancy_db_password`) that control which server a tenant's database is created on and which credentials it uses.

Never pass unfiltered user input into tenant creation or updates:

```php
// NEVER do this — a crafted request can inject tenancy_db_connection
// and point the tenant at an arbitrary configured database server:
Tenant::create($request->all());

// Do this — pass an explicit, validated attribute list:
Tenant::create([
    'name' => $validated['name'],
    'slug' => $validated['slug'],
]);
```

The onboarding flow that ships with this package already follows this rule: the `TenantOnboarder` service builds tenants from an explicit attribute array, and the database pool assigns `tenancy_db_connection` internally, never from request data. Keep the same discipline in any custom registration flow, admin resource, or API endpoint you build around the tenant model. If you expose the tenant model through a Filament resource, define the form fields explicitly and do not include any `tenancy_db_*` attribute.

If you believe you have found a way to reach a `tenancy_db_*` attribute from user input through code shipped by this package, report it — that is exactly the class of issue we most want to hear about.
