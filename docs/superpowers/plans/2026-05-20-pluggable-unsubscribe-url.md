# Pluggable Unsubscribe URL Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the package's unsubscribe-URL generation pluggable via a container-bound contract, with a non-expiring signed URL as the default, so consuming apps can override the URL without running a second header-injection system.

**Architecture:** Introduce an `UnsubscribeUrlGenerator` contract with one method `generate(SentEmailContract $email): string`. Ship a default `SignedUnsubscribeUrlGenerator` holding today's signed-URL logic. Bind the default in the service provider (`bind`, not `singleton`, for Octane safety). `TrackedMailer::addUnsubscribeHeaders()` resolves the generator from the container instead of calling its own protected method; the package keeps sole ownership of RFC 8058 header assembly. Non-breaking: apps that do nothing keep current behavior.

**Tech Stack:** PHP 8.2+, Laravel 11/12/13, PHPUnit 11 + Orchestra Testbench, namespace `R0bdiabl0\EmailTracker\`, run tests with `composer test` (alias for `phpunit`), lint with `composer lint` (Pint).

---

## File Structure

- Create: `src/Contracts/UnsubscribeUrlGenerator.php` — the contract (one method).
- Create: `src/Services/SignedUnsubscribeUrlGenerator.php` — default implementation (today's signed-URL logic).
- Modify: `src/TrackedMailer.php` — resolve generator in `addUnsubscribeHeaders()`; delete the now-unused protected `generateUnsubscribeUrl()`.
- Modify: `src/EmailTrackerServiceProvider.php` — bind contract → default in `register()`.
- Create: `tests/Unit/SignedUnsubscribeUrlGeneratorTest.php` — default generator behavior.
- Create: `tests/Feature/UnsubscribeUrlGeneratorBindingTest.php` — binding resolves default; custom binding overrides; regression guard for single header path.
- Modify: `README.md`, `CHANGELOG.md`, `UPGRADE.md`, `composer.json` (description/keywords).

---

## Task 1: Define the `UnsubscribeUrlGenerator` contract

**Files:**
- Create: `src/Contracts/UnsubscribeUrlGenerator.php`

- [ ] **Step 1: Create the contract**

```php
<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Contracts;

interface UnsubscribeUrlGenerator
{
    /**
     * Generate the unsubscribe URL for a sent email.
     */
    public function generate(SentEmailContract $email): string;
}
```

- [ ] **Step 2: Verify it parses**

Run: `php -l src/Contracts/UnsubscribeUrlGenerator.php`
Expected: `No syntax errors detected in src/Contracts/UnsubscribeUrlGenerator.php`

- [ ] **Step 3: Commit**

```bash
git add src/Contracts/UnsubscribeUrlGenerator.php
git commit -m "Add UnsubscribeUrlGenerator contract"
```

---

## Task 2: Default `SignedUnsubscribeUrlGenerator` (TDD)

**Files:**
- Test: `tests/Unit/SignedUnsubscribeUrlGeneratorTest.php`
- Create: `src/Services/SignedUnsubscribeUrlGenerator.php`

The default reproduces today's logic from `TrackedMailer::generateUnsubscribeUrl()`: a non-expiring `URL::signedRoute('email-tracker.unsubscribe', ...)` when `signature_expiration` is `0`, and a `URL::temporarySignedRoute(...)` when `> 0`. The route `email-tracker.unsubscribe` is registered by the package's `routes/web.php` (loaded by the service provider in tests via Testbench).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Unit;

use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Services\SignedUnsubscribeUrlGenerator;
use R0bdiabl0\EmailTracker\Tests\TestCase;

class SignedUnsubscribeUrlGeneratorTest extends TestCase
{
    private function fakeEmail(string $address, string $messageId): SentEmailContract
    {
        return new class($address, $messageId) implements SentEmailContract {
            public function __construct(private string $address, private string $messageId) {}
            public function getId(): mixed { return 1; }
            public function getEmail(): string { return $this->address; }
            public function getMessageId(): string { return $this->messageId; }
            public function setMessageId(string $messageId): self { $this->messageId = $messageId; return $this; }
            public function setDeliveredAt(\DateTimeInterface $time): self { return $this; }
        };
    }

    public function test_generates_non_expiring_signed_url_by_default(): void
    {
        config()->set('email-tracker.unsubscribe.signature_expiration', 0);

        $url = (new SignedUnsubscribeUrlGenerator())->generate(
            $this->fakeEmail('user@example.com', 'msg-123')
        );

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('expires=', $url);
    }

    public function test_generates_temporary_signed_url_when_expiration_set(): void
    {
        config()->set('email-tracker.unsubscribe.signature_expiration', 24);

        $url = (new SignedUnsubscribeUrlGenerator())->generate(
            $this->fakeEmail('user@example.com', 'msg-123')
        );

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }
}
```

> Note: the anonymous class must implement every method on `SentEmailContract`. The methods above
> (`getId`, `getEmail`, `getMessageId`, `setMessageId`, `setDeliveredAt`) match the contract as of
> this writing — if `php -l`/the test reports a missing/extra method, reconcile against
> `src/Contracts/SentEmailContract.php` rather than guessing.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SignedUnsubscribeUrlGeneratorTest`
Expected: FAIL — `Class "R0bdiabl0\EmailTracker\Services\SignedUnsubscribeUrlGenerator" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Services;

use Illuminate\Support\Facades\URL;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Contracts\UnsubscribeUrlGenerator;

class SignedUnsubscribeUrlGenerator implements UnsubscribeUrlGenerator
{
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

        return URL::signedRoute('email-tracker.unsubscribe', $params);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter SignedUnsubscribeUrlGeneratorTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/SignedUnsubscribeUrlGenerator.php tests/Unit/SignedUnsubscribeUrlGeneratorTest.php
git commit -m "Add default signed unsubscribe URL generator"
```

---

## Task 3: Bind the contract in the service provider

**Files:**
- Modify: `src/EmailTrackerServiceProvider.php` (in `register()`, after the `TrackedMailer` singleton block, around line 50–53)

- [ ] **Step 1: Add imports**

At the top of the file, add (alongside existing `use` statements):

```php
use R0bdiabl0\EmailTracker\Contracts\UnsubscribeUrlGenerator;
use R0bdiabl0\EmailTracker\Services\SignedUnsubscribeUrlGenerator;
```

- [ ] **Step 2: Add the binding**

In `register()`, immediately after the `$this->app->alias(TrackedMailer::class, 'email-tracker');` line, add:

```php
// Bind the default unsubscribe URL generator. Apps may override this
// binding to supply their own URL scheme. Bound (not singleton) so it
// resolves fresh per request — Octane-safe, no mutable global state.
$this->app->bind(UnsubscribeUrlGenerator::class, SignedUnsubscribeUrlGenerator::class);
```

- [ ] **Step 3: Verify it parses and existing tests still pass**

Run: `php -l src/EmailTrackerServiceProvider.php && composer test -- --filter ServiceProviderTest`
Expected: no syntax errors; ServiceProviderTest passes.

- [ ] **Step 4: Commit**

```bash
git add src/EmailTrackerServiceProvider.php
git commit -m "Bind default unsubscribe URL generator in service provider"
```

---

## Task 4: Resolve the generator in TrackedMailer, remove hardcoded method

**Files:**
- Modify: `src/TrackedMailer.php` (`addUnsubscribeHeaders()` ~line 296–313; delete `generateUnsubscribeUrl()` ~line 315–338)

- [ ] **Step 1: Add the import**

At the top of `src/TrackedMailer.php`, add alongside the existing `R0bdiabl0\EmailTracker\Contracts\...` imports:

```php
use R0bdiabl0\EmailTracker\Contracts\UnsubscribeUrlGenerator;
```

- [ ] **Step 2: Change the URL resolution line**

In `addUnsubscribeHeaders()`, replace:

```php
$unsubscribeUrl = $this->generateUnsubscribeUrl($email);
```

with:

```php
$unsubscribeUrl = app(UnsubscribeUrlGenerator::class)->generate($email);
```

Leave the rest of `addUnsubscribeHeaders()` unchanged — it still builds `List-Unsubscribe`
(with the optional `mailto:` fallback) and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`.

- [ ] **Step 3: Delete the now-unused protected method**

Remove the entire `generateUnsubscribeUrl()` method (the docblock `Generate a signed unsubscribe URL.`
through its closing brace). Its logic now lives in `SignedUnsubscribeUrlGenerator`.

- [ ] **Step 4: Verify no other references remain**

Run: `grep -rn "generateUnsubscribeUrl" src tests`
Expected: no output (zero references).

- [ ] **Step 5: Verify it parses; check URL facade still used**

Run: `php -l src/TrackedMailer.php`
Expected: no syntax errors.
Then run: `grep -n "URL::" src/TrackedMailer.php` — if there are now zero `URL::` usages, also remove the
`use Illuminate\Support\Facades\URL;` import to keep Pint happy. If any remain, leave the import.

- [ ] **Step 6: Commit**

```bash
git add src/TrackedMailer.php
git commit -m "Resolve unsubscribe URL via pluggable generator"
```

---

## Task 5: Binding + override + single-header regression tests

**Files:**
- Test: `tests/Feature/UnsubscribeUrlGeneratorBindingTest.php`

These tests prove (a) the default binding resolves to the package default, (b) an app can override
the binding, and (c) the override flows through `addUnsubscribeHeaders()` to produce exactly one
`List-Unsubscribe` header containing the custom URL. We exercise the protected
`addUnsubscribeHeaders()` via a `Closure::bind` reflection helper so the test is independent of the
full SMTP send path.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Feature;

use Closure;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Contracts\UnsubscribeUrlGenerator;
use R0bdiabl0\EmailTracker\Services\SignedUnsubscribeUrlGenerator;
use R0bdiabl0\EmailTracker\Tests\TestCase;
use R0bdiabl0\EmailTracker\TrackedMailer;
use Symfony\Component\Mime\Header\Headers;

class UnsubscribeUrlGeneratorBindingTest extends TestCase
{
    private function fakeEmail(): SentEmailContract
    {
        return new class implements SentEmailContract {
            public function getId(): mixed { return 1; }
            public function getEmail(): string { return 'user@example.com'; }
            public function getMessageId(): string { return 'msg-123'; }
            public function setMessageId(string $messageId): self { return $this; }
            public function setDeliveredAt(\DateTimeInterface $time): self { return $this; }
        };
    }

    public function test_default_binding_resolves_to_signed_generator(): void
    {
        $this->assertInstanceOf(
            SignedUnsubscribeUrlGenerator::class,
            $this->app->make(UnsubscribeUrlGenerator::class)
        );
    }

    public function test_app_can_override_the_generator_binding(): void
    {
        $this->app->bind(UnsubscribeUrlGenerator::class, fn () => new class implements UnsubscribeUrlGenerator {
            public function generate(SentEmailContract $email): string
            {
                return 'https://example.com/u/custom-token';
            }
        });

        $this->assertSame(
            'https://example.com/u/custom-token',
            $this->app->make(UnsubscribeUrlGenerator::class)->generate($this->fakeEmail())
        );
    }

    public function test_emits_exactly_one_list_unsubscribe_header_with_custom_url(): void
    {
        config()->set('email-tracker.unsubscribe.enabled', true);
        config()->set('email-tracker.unsubscribe.mailto', null);

        $this->app->bind(UnsubscribeUrlGenerator::class, fn () => new class implements UnsubscribeUrlGenerator {
            public function generate(SentEmailContract $email): string
            {
                return 'https://example.com/u/custom-token';
            }
        });

        $mailer = $this->app->make(TrackedMailer::class);
        $headers = new Headers();

        // addUnsubscribeHeaders() is protected; invoke it bound to the mailer instance.
        $invoke = Closure::bind(
            function (Headers $headers, SentEmailContract $email) {
                $this->addUnsubscribeHeaders($headers, $email);
            },
            $mailer,
            TrackedMailer::class
        );
        $invoke($headers, $this->fakeEmail());

        $listUnsubscribe = $headers->all('list-unsubscribe');
        $this->assertCount(1, $listUnsubscribe);
        $this->assertStringContainsString('https://example.com/u/custom-token', $listUnsubscribe[0]->getBodyAsString());

        $post = $headers->all('list-unsubscribe-post');
        $this->assertCount(1, $post);
    }
}
```

> Note: `Headers::all($name)` returns the headers matching that (lowercased) name. If the installed
> Symfony Mime version exposes a different accessor, adjust to the available API but keep the
> assertion semantics: exactly one `List-Unsubscribe` header containing the custom URL, and exactly
> one `List-Unsubscribe-Post`.

- [ ] **Step 2: Run test to verify it fails (before any fix) or passes (if implementation already correct)**

Run: `composer test -- --filter UnsubscribeUrlGeneratorBindingTest`
Expected: With Tasks 1–4 already done, these should PASS. If `addUnsubscribeHeaders` cannot be
invoked or the header count is wrong, that is a real failure to fix in `src/TrackedMailer.php` —
do not weaken the assertions.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/UnsubscribeUrlGeneratorBindingTest.php
git commit -m "Add tests for pluggable unsubscribe URL generator"
```

---

## Task 6: Run full suite and lint

- [ ] **Step 1: Run the whole test suite**

Run: `composer test`
Expected: all tests PASS (including pre-existing ones).

- [ ] **Step 2: Lint**

Run: `composer lint`
Expected: no style violations. If Pint reports issues, run `vendor/bin/pint` and re-run `composer lint`.

- [ ] **Step 3: Commit any lint fixes**

```bash
git add -A
git commit -m "Apply code style fixes"
```
(Skip if nothing changed.)

---

## Task 7: Documentation, composer.json, CHANGELOG

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `UPGRADE.md`, `composer.json`

- [ ] **Step 1: README — add "Customizing the unsubscribe URL"**

Find the existing One-Click Unsubscribe / unsubscribe section in `README.md` and add a subsection
after it:

````markdown
### Customizing the unsubscribe URL

By default the package generates a non-expiring Laravel signed URL pointing at the
`email-tracker.unsubscribe` route. Set `unsubscribe.signature_expiration` above `0` to make those
links time-limited (not recommended — recipients may open the email after the window).

To use a completely different URL scheme (for example a persistent token URL that does not depend
on `APP_KEY`), bind your own implementation of `UnsubscribeUrlGenerator`:

```php
use R0bdiabl0\EmailTracker\Contracts\UnsubscribeUrlGenerator;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;

// In a service provider's register() method:
$this->app->bind(UnsubscribeUrlGenerator::class, function () {
    return new class implements UnsubscribeUrlGenerator {
        public function generate(SentEmailContract $email): string
        {
            return route('my.unsubscribe', ['token' => /* your token for $email */]);
        }
    };
});
```

The package always owns the RFC 8058 header assembly (`List-Unsubscribe`,
`List-Unsubscribe-Post`, and the optional `mailto:` fallback) — you only supply the URL, so there
is exactly one header path.
````

- [ ] **Step 2: CHANGELOG — add v1.7.0 entry**

Add at the top of `CHANGELOG.md` (match the file's existing heading style):

```markdown
## [1.7.0] - 2026-05-20

### Added
- Pluggable unsubscribe URL generation via the new `UnsubscribeUrlGenerator` contract. Bind your
  own implementation to use a custom unsubscribe URL scheme; the package retains ownership of RFC
  8058 header assembly.

### Changed
- The signed-URL behavior now lives in `SignedUnsubscribeUrlGenerator` (the default binding).
  Behavior is unchanged for existing consumers — non-expiring signed URLs by default, time-limited
  when `unsubscribe.signature_expiration` is greater than `0`.
```

- [ ] **Step 3: UPGRADE.md — add adoption note**

Append to `UPGRADE.md`:

```markdown
## Upgrading to 1.7.0

No action required — existing behavior is unchanged.

To customize the unsubscribe URL, bind your own `UnsubscribeUrlGenerator` implementation in a
service provider. See "Customizing the unsubscribe URL" in the README.
```

- [ ] **Step 4: composer.json — keep description accurate (optional keyword)**

In `composer.json`, add `"Unsubscribe"` to the `keywords` array if not already present. Leave
`description` as-is unless it reads inaccurately.

- [ ] **Step 5: Verify composer.json is valid JSON**

Run: `composer validate --no-check-publish`
Expected: `./composer.json is valid`.

- [ ] **Step 6: Commit**

```bash
git add README.md CHANGELOG.md UPGRADE.md composer.json
git commit -m "Document pluggable unsubscribe URL generator and add v1.7.0 changelog"
```

---

## Task 8: Tag and release v1.7.0

> Per repo policy (CLAUDE.md): no Claude/AI attribution anywhere — commit messages, tag message,
> or GitHub release notes. Confirm with the user before pushing/tagging if not already authorized.

- [ ] **Step 1: Push the branch**

Run: `git push origin main` (or the working branch, then open a PR if that is the team's flow).

- [ ] **Step 2: Create the annotated tag**

```bash
git tag -a v1.7.0 -m "v1.7.0 - Pluggable unsubscribe URL generation"
git push origin v1.7.0
```

- [ ] **Step 3: Create the GitHub release**

```bash
gh release create v1.7.0 --repo r0bdiabl0/laravel-email-tracker \
  --title "v1.7.0" \
  --notes "Pluggable unsubscribe URL generation via the new UnsubscribeUrlGenerator contract. Bind your own implementation to customize the unsubscribe URL; the package retains RFC 8058 header ownership. Default signed-URL behavior is unchanged."
```

Packagist auto-syncs from the new tag/release — no separate Packagist edit needed.

---

## Self-Review Notes

- **Spec coverage:** Contract (Task 1), default impl (Task 2), wiring/bind (Task 3), TrackedMailer
  resolution + method removal (Task 4), single-header regression + override tests (Task 5), full
  suite/lint (Task 6), README/CHANGELOG/UPGRADE/composer + Packagist note (Task 7), tag/release
  (Task 8). All spec sections mapped.
- **Non-breaking:** default binding reproduces current signed-URL behavior; existing tests run in
  Task 3 and Task 6.
- **Type consistency:** `UnsubscribeUrlGenerator::generate(SentEmailContract): string` used
  identically in Tasks 1, 2, 4, 5, 7. `SentEmailContract` methods mirrored from the real contract
  with a reconciliation note.
- **No AI attribution:** called out in Task 8 and honored in every commit message above.
