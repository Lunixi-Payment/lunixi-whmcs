# Changelog

## 0.1.2 — 2026-10-31

- Default new checkout configuration to the existing Lunixi form on the merchant site; retain the optional hosted selector and explicit merchant settings.
- Redirect only to a validated backend `paymentPageUrl`; its opaque token can differ from the embedded form token. Missing URLs keep embedded checkout available and hosted checkout on hold without creating another intent/key.
- Preserve historical checkout modes, encrypted credentials/tokens, immutable request bodies and existing idempotency/3DS/split return behavior.
- Bundle shared PHP SDK 0.29.5 with validated URL handling and encrypted ledger persistence.

## 0.1.1 — 2026-10-31

- Accept bounded informational return POST before WHMCS bootstrap, with fixed query-only invoice locator and an empty same-origin 303; preserve native ownership on GET.
- Verify actual built return HTTP behavior and embedded HTML/JavaScript object/mount/XSS contract; 34 tests / 134 assertions.

- Lock development build/test tools at the PHP 8.2 minimum so PHP 8.2/8.3/8.4 CI installs the same PHP-8.2-compatible dependency graph. Merchant runtime and bundled SDK remain unchanged.

## 0.1.0 — 2026-10-31

- Hosted/embedded Lunixi invoice checkout, owner and native CSRF validation, immutable encrypted merchant/environment profiles.
- Exact DECIMAL outstanding balance with credit/partial payments, shared PHP 0.29 Commerce signing/evidence/opaque recovery and scoped standalone SDK.
- Capsule-native serialized ledger, atomic webhook/payment/refund/outbox writes, payload collision checks, cancellation/edited invoice safeguards.
- Durable addon capture/refund/void IDs, async confirmation and same-key retry; legacy native refund callback fails closed.
- Principal/fee-separated cash accounting and original-transaction-correlated refunds.
- Postcommit emails and documented native create/unsuspend commands with state checks; uncertain commands and unsupported renewal/custom flows remain reviewable.
- Original Lunixi branding, EN/TR UI/docs, real MariaDB official-contract tests, process concurrency, PHPStan 8, dependency/artifact checks and licensed-runtime acceptance runner.

No licensed WHMCS runtime acceptance or marketplace approval is claimed by this source release.
