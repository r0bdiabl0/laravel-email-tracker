# Pluggable Unsubscribe URL Generation

**Date:** 2026-05-20
**Package:** `r0bdiabl0/laravel-email-tracker`
**Target release:** v1.7.0 (non-breaking minor)

## Problem

The package hardcodes unsubscribe-URL generation in `TrackedMailer::generateUnsubscribeUrl()`
(a `protected` method, no override hook). It always produces a Laravel signed URL via
`URL::signedRoute()` / `URL::temporarySignedRoute()`.

This is a design gap, not just a bug. Because the package offered no way to customize URL
generation, the consuming app (Swingular) bolted its own `addPersistentUnsubscribeHeaders()`
onto each mail channel and tried to switch the package's headers off via an env flag
(`EMAIL_TRACKER_UNSUBSCRIBE_ENABLED`). The result is **two unsubscribe systems running side by
side**, producing duplicate `List-Unsubscribe` headers, a fragile env-flag dependency, and
suppression guards (SWING-327) layered on top.

The underlying functional defect with signed URLs: a `temporarySignedRoute` link **expires**, so
a recipient who opens the email after the window can no longer unsubscribe — a deliverability and
RFC-8058 compliance problem. (Signed URLs also depend on a stable `APP_KEY`, which is a reasonable
baseline Laravel assumption; the package does not try to solve key rotation — apps that need to can
override the URL.)

## Goal & Scope

Make unsubscribe-URL generation **pluggable** via a container-bound contract, with a **safe,
non-expiring signed URL** as the out-of-the-box default. The package retains sole ownership of
RFC 8058 header assembly, so there is exactly one code path emitting exactly one
`List-Unsubscribe` header. This gives consuming apps (including Swingular) an official extension
point and lets them delete their app-side dual-injection.

**In scope (this package):**
- New `UnsubscribeUrlGenerator` contract.
- Default `SignedUnsubscribeUrlGenerator` implementation (today's logic, non-expiring by default).
- Service-provider binding; `TrackedMailer` resolves the generator from the container.
- Tests, README, CHANGELOG, UPGRADE notes.

**Out of scope:**
- Any database token scheme or Optimus dependency in the package (those are app-specific).
- Changes to the unsubscribe route/controller or `EmailUnsubscribeEvent` behavior.
- App-side changes in the Swingular repo (done separately against this new contract).

## Design

### 1. Contract

`src/Contracts/UnsubscribeUrlGenerator.php`:

```php
namespace R0bdiabl0\EmailTracker\Contracts;

interface UnsubscribeUrlGenerator
{
    public function generate(SentEmailContract $email): string;
}
```

One method. Receives the existing `SentEmailContract` (exposes `getEmail()` and `getMessageId()`,
which is all the default needs). Returns a URL string. Matches the package's existing `Contracts/`
convention.

> Confirm the actual root namespace from `composer.json` autoload during implementation and use it
> consistently.

### 2. Default implementation

`src/Services/SignedUnsubscribeUrlGenerator.php` — today's logic, moved verbatim out of
`TrackedMailer::generateUnsubscribeUrl()`:

```php
public function generate(SentEmailContract $email): string
{
    $expiration = (int) config('email-tracker.unsubscribe.signature_expiration', 0);

    $params = [
        'email' => $email->getEmail(),
        'message_id' => $email->getMessageId(),
    ];

    if ($expiration > 0) {
        return URL::temporarySignedRoute(
            'email-tracker.unsubscribe',
            now()->addHours($expiration),
            $params,
        );
    }

    return URL::signedRoute('email-tracker.unsubscribe', $params); // default: never expires
}
```

Behavior is identical to today for existing consumers → non-breaking. `signature_expiration`
remains in config, default `0` (no expiry, the recommended safe default); `> 0` is an explicit
opt-in for time-limited links.

### 3. Wiring & resolution

**Service provider** (`EmailTrackerServiceProvider::register()`):

```php
$this->app->bind(UnsubscribeUrlGenerator::class, SignedUnsubscribeUrlGenerator::class);
```

A `bind` (not `singleton`) so it resolves fresh — Octane-safe, no mutable global state.

**TrackedMailer**: `addUnsubscribeHeaders()` changes its URL line to:

```php
$unsubscribeUrl = app(UnsubscribeUrlGenerator::class)->generate($email);
```

The protected `generateUnsubscribeUrl()` method is deleted (its logic now lives in the default
generator). `addUnsubscribeHeaders()` continues to own assembly of `List-Unsubscribe` (with the
optional `mailto:` fallback) and `List-Unsubscribe-Post` — unchanged. `shouldAddUnsubscribeHeaders()`
and the `enabled` config gate are unchanged.

**App override (Swingular, separate repo — documented, not done here):** bind a custom
`UnsubscribeUrlGenerator` (Optimus+authCode) in `AppServiceProvider`; set
`email-tracker.unsubscribe.enabled = true`; delete the app-side `addPersistentUnsubscribeHeaders()`
in all three channels, the `EMAIL_TRACKER_UNSUBSCRIBE_ENABLED` flag handling, and the SWING-327
suppression guards. Two systems collapse into one.

## Testing

- **Unit** — default generator returns a signed, non-expiring URL when `signature_expiration = 0`;
  returns a temporary signed URL when `> 0`.
- **Feature (regression guard)** — bind a fake `UnsubscribeUrlGenerator` returning a sentinel URL;
  send a tracked email with unsubscribe enabled; assert the message has **exactly one**
  `List-Unsubscribe` header and that it contains the sentinel URL. This structurally guards against
  the duplicate-header class of bug.
- **Feature** — with the default binding and `enabled = true`, assert one `List-Unsubscribe` header
  plus `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, and the `mailto:` fallback when
  `unsubscribe.mailto` is set.

## Docs & versioning

- **README** — add a "Customizing the unsubscribe URL" section documenting the contract and a bind
  example.
- **CHANGELOG** — v1.7.0 entry: new pluggable `UnsubscribeUrlGenerator`; default behavior unchanged
  (non-breaking).
- **UPGRADE.md** — note for consumers who want to adopt the override hook.
- **Packagist** — Packagist has no separate comment field; it auto-syncs from the GitHub repo on
  each tagged release. Publishing this update therefore means:
  - Ensure `composer.json` `description`/`keywords` still describe the package accurately (mention
    customizable/pluggable unsubscribe URLs if it adds clarity to the summary shown on Packagist).
  - README and CHANGELOG (above) render on the Packagist page and release listing.
  - Tag `v1.7.0` and push the tag; create a matching GitHub release whose notes mirror the
    CHANGELOG entry. Per repo policy (CLAUDE.md), commit messages, the tag, and the GitHub release
    notes must contain no Claude/AI attribution.

## Non-breaking guarantee

Existing consumers who do nothing get the same signed-URL behavior they have today, now served
through the default generator. The only new surface is an opt-in contract + binding.
