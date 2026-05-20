# Package Extension Points — Bucket A Audit (package side)

**Date:** 2026-05-20
**Context:** Follow-up to the v1.7.0 pluggable `UnsubscribeUrlGenerator` work. The consuming app
(Swingular) has ~30+ integration points and several "worked around hardcoded package behavior"
patterns. This doc audits the **package's** extension surface so the app-side audit can decide,
per integration, whether the workaround exists because the package *lacks a seam* (Bucket A,
upstream) or is *legitimate app policy* (Bucket B, keep app-side).

> Scope note: the app integration code (DebuggableSesProvider, custom mail channels, custom webhook
> controllers) lives in the Swingular repo, not here. This doc only assesses what the package
> currently exposes. Final per-file classification happens in the app-side conversation, which has
> that code in context.

## Reference pattern (now shipped)

`UnsubscribeUrlGenerator` (v1.7.0) is the template for any Bucket A fix:
- A narrow contract in `src/Contracts/`.
- A default implementation in `src/Services/` preserving prior behavior.
- A `bind` (not singleton) in the service provider → Octane-safe.
- A single resolution point in the package; app overrides via its own binding.
- Non-breaking: doing nothing keeps current behavior.

Any upstreamed seam should follow this shape.

## Current package seams (what already exists)

**Contracts (overridable types):** `BatchContract`, `EmailBounceContract`, `EmailComplaintContract`,
`EmailLinkContract`, `EmailOpenContract`, `EmailProviderInterface`, `SentEmailContract`,
`TrackedMailerInterface`, `UnsubscribeUrlGenerator`.

**Events (hook here instead of re-firing):** `EmailSentEvent`, `EmailBounceEvent`,
`EmailComplaintEvent`, `EmailDeliveryEvent`, `EmailOpenEvent`, `EmailLinkClickEvent`,
`EmailUnsubscribeEvent`. SES provider already dispatches bounce/complaint/delivery
(`src/Providers/SesProvider.php:342,401,441`).

**Webhook handling:** `WebhookController::handle()` dispatches to a provider handler resolved by
name (`EmailTracker::getProviderHandler($provider)` — `src/Controllers/WebhookController.php:25`).
Providers do their own signature validation and payload parsing.

## Likely Bucket A items to verify against the app

These are package-side hypotheses. The app-side audit confirms whether the app actually works
around each.

1. **`DebuggableSesProvider` (app) subclassing `SesProvider`.**
   `SesProvider` exposes many `protected` methods (`handleBounce`, `handleComplaint`,
   `handleDelivery`, `findSentEmail`, `validateSignatureFromMessage`, `shouldValidateSignature`,
   etc. — `src/Providers/SesProvider.php`). If the app subclasses purely to add logging or to
   fire/observe events, that is the same "override a protected method" smell as the unsubscribe
   case.
   - **If confirmed Bucket A:** the package already fires the bounce/complaint/delivery events, so
     the app may be able to drop the subclass and just listen to those events. If it needs more
     visibility, the package could add a lightweight logging hook or a `WebhookProcessed` event
     rather than expecting subclassing.
   - **Check:** does the app subclass override a `protected` method, or only listen to events? Only
     the former is a real gap.

2. **Channels firing `EmailBounceEvent` manually.**
   The package already dispatches these events from the SES provider. If the app re-fires them, it
   may be because (a) a non-SES path doesn't emit them, or (b) duplicated work. Identify which
   provider/path the app fires from and whether the package emits there.
   - **If a path is missing event emission:** upstream — emit the event in that path.
   - **If duplicated:** app-side cleanup, not a package change.

3. **Custom webhook controllers (Bucket C — consolidate).**
   If the app reimplements signature verification / payload parsing that
   `SesProvider::validateSignature()` + `parsePayload()` already do, lean on the package's
   `WebhookController` + provider handler and hook domain reactions via the events above. The domain
   reaction (mark our user invalid, suppression keyed on our User model) stays app-side.

## Explicitly NOT package candidates (Bucket B — keep app-side)

Do not upstream these; they couple a generic library to Swingular's domain:
- Suppression keyed on `users.invalid_email` / `email_verified_at` (knows the app User model).
- `UnifiedEmailEventRepository`, `BounceClassifier`/`ReasonCode`, `EmailFailoverService` and the
  failover cascade — app routing policy and traffic-tuned rules.
- Filament admin resources.

The discipline: **package = generic pluggable mechanism; app = specific policy + models.** The
risk is not only "more workarounds" but the inverse temptation — because we own the package,
shoving app-specific logic into it.

## Recommended next step

Run the per-file classification in the **app-side** conversation (it has the integration code),
using this doc as the package-capability reference. Produce a bounded package-update roadmap:
each Bucket A item gets a contract+default+binding following the `UnsubscribeUrlGenerator`
pattern; Bucket B items are explicitly marked "stays app-side"; Bucket C items get a
consolidation note. Hold until v1.7.0 is adopted so the audit reflects post-fix state.
