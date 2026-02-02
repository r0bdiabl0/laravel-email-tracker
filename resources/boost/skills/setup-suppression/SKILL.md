---
name: setup-suppression
description: Configure email suppression to automatically skip sending to bounced or complained addresses.
---

# Setup Suppression

## When to use this skill

Use this skill when:
- Enabling automatic bounce management
- Preventing sends to problematic addresses
- Checking suppression status before bulk sends
- Implementing email re-verification flows

## Enable Automatic Suppression

Add to `.env`:

```env
EMAIL_TRACKER_SKIP_BOUNCED=true
EMAIL_TRACKER_SKIP_COMPLAINED=true
```

When enabled, all sending methods automatically skip suppressed addresses:
- `EmailTracker::send()`
- `TracksWithEmail` trait
- `EmailTrackerChannel`

## Handle Suppression Exceptions

```php
use R0bdiabl0\EmailTracker\Exceptions\AddressSuppressedException;
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

try {
    EmailTracker::enableAllTracking()
        ->to($user->email)
        ->send(new WelcomeMail($user));
} catch (AddressSuppressedException $e) {
    Log::info('Email suppressed', [
        'email' => $e->getEmail(),
        'reason' => $e->getReason(),
    ]);
}
```

## Manual Suppression Checking

```php
use R0bdiabl0\EmailTracker\Services\EmailValidator;

// Check if blocked
if (EmailValidator::shouldBlock('user@example.com')) {
    return;
}

// Get bounce count (permanent only)
$bounces = EmailValidator::getBounceCount('user@example.com');

// Check for any bounce or permanent bounce
$hasBounce = EmailValidator::hasBounce('user@example.com');
$hasPermanent = EmailValidator::hasPermanentBounce('user@example.com');

// Check for complaints
$complained = EmailValidator::hasComplaint('user@example.com');

// Filter a list
$valid = EmailValidator::filterBlockedEmails($emailList);

// Get comprehensive validation summary
$summary = EmailValidator::getValidationSummary('user@example.com');
// Returns: ['bounces' => int, 'complaints' => int, 'should_block' => bool]

// Filter by specific provider
$sesBounces = EmailValidator::getBounceCount('user@example.com', 'ses');
$valid = EmailValidator::filterBlockedEmails($emailList, 'resend');
```

## Pre-Send Validation for Bulk

```php
use R0bdiabl0\EmailTracker\Services\EmailValidator;

public function sendBulk(array $recipients): array
{
    $valid = EmailValidator::filterBlockedEmails($recipients);
    $skipped = array_diff($recipients, $valid);

    foreach ($valid as $email) {
        EmailTracker::enableAllTracking()
            ->to($email)
            ->send(new NewsletterMail());
    }

    return ['sent' => count($valid), 'skipped' => $skipped];
}
```

## Query Suppressed Addresses

```php
use R0bdiabl0\EmailTracker\Models\EmailBounce;
use R0bdiabl0\EmailTracker\Models\EmailComplaint;

$bounced = EmailBounce::where('type', 'Permanent')
    ->distinct('email')
    ->pluck('email');

$complained = EmailComplaint::distinct('email')
    ->pluck('email');

$suppressed = $bounced->merge($complained)->unique();
```

## Clear Suppression

```php
// Allow sending again
EmailBounce::where('email', 'user@example.com')->delete();
EmailComplaint::where('email', 'user@example.com')->delete();
```

## Suppression Stats

```php
$stats = [
    'permanent_bounces' => EmailBounce::where('type', 'Permanent')->count(),
    'transient_bounces' => EmailBounce::where('type', 'Transient')->count(),
    'complaints' => EmailComplaint::count(),
];
```
