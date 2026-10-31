<p align="center"><img src="modules/gateways/lunixi/assets/lunixi.svg" width="240" alt="Lunixi"></p>

# Lunixi Payments for WHMCS

**Verified public merchant ZIP 0.1.2:** [`lunixi-whmcs-0.1.2.zip`](https://github.com/Lunixi-Payment/lunixi-whmcs/releases/download/v0.1.2/lunixi-whmcs-0.1.2.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-whmcs/releases/download/v0.1.2/SHA256SUMS) · [Release v0.1.2](https://github.com/Lunixi-Payment/lunixi-whmcs/releases/tag/v0.1.2). The CI-built installation package was downloaded and verified on 2026-10-31. Use this asset rather than GitHub's automatic source archive.

Public CI ZIP SHA256: `940aa94ee9a0962645ba76d4eedc0e83a13d3aa280ba94d02940d4633f5547c5`.

The existing Lunixi form opens **on the merchant website by default**. Hosted checkout remains optional and uses only the validated backend `paymentPageUrl`. Its opaque token can differ from the embedded token; the module never builds this URL from that token. Missing or legacy URLs keep embedded checkout available; hosted checkout stays on hold/reconciliation without a new intent or key. Existing attempts keep their saved mode, request body, profile and key. The official form/panel controls available methods and multi-card options.

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-1e3a5f)](https://www.php.net/) [![MIT](https://img.shields.io/badge/license-MIT-ff6b35)](LICENSE) · [Türkçe](README.tr.md) · [Documentation](https://docs.lunixi.com/en/sdks/whmcs)

Embedded Lunixi checkout for WHMCS invoices by default, with an optional backend-URL hosted flow, with encrypted merchant profiles, authenticated settlement, durable capture/refund/void operations and an encrypted notification outbox. Payment methods, installments, insurance, eligible alternative methods and multi-card availability stay configured in **Lunixi**, without duplicate switches in WHMCS. No PAN/CVV is collected by this module.

Version **0.1.2** implements the official third-party gateway/addon contracts for **WHMCS 8.13 / 9.0 / 9.1**, with **64-bit PHP 8.2+**. Official releases reviewed on 31 October 2026: 8.13.7 / 9.0.9 / 9.1.0. **No licensed WHMCS runtime was available:** the executed acceptance suite uses actual MariaDB/InnoDB and Illuminate with explicit official-contract stand-ins. It is not a certified native-platform or marketplace acceptance claim. Complete [licensed acceptance](docs/ACCEPTANCE.md) before deployment.

## Install the merchant ZIP

1. Back up WHMCS and its database. Confirm HTTPS `SystemURL`, PHP Sodium/cURL/JSON/PDO MySQL, and InnoDB native financial tables. Use WHMCS's supported PHP/ionCube combination; the module itself is unencoded PHP.
2. Generate a separate server encryption key locally:
   ```bash
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
   ```
   Store it in the PHP web **and CLI** worker environment as `LUNIXI_WHMCS_ENCRYPTION_KEY`. Back it up outside the web root/database. Keep it stable across upgrades; it cannot be replaced without a reviewed credential migration.
3. Extract **`lunixi-whmcs-0.1.2.zip` into the WHMCS root**. It supplies `modules/gateways/lunixi.php`, its private scoped SDK, `modules/gateways/callback/lunixi.php`, and `modules/addons/lunixi_payments/`. The built ZIP requires **no Composer** on the merchant server. A GitHub source archive is not the merchant ZIP.
4. Activate **Lunixi Payments** under **System Settings → Addon Modules**, assigning only appropriate admin roles. In **Addons → Lunixi Payments**, create a credential profile using password fields for the Ed25519 private key/webhook secret. Select the key's **TEST or LIVE** environment, embedded (default) or optional hosted mode and optional preauthorization.
5. Activate **Lunixi Payments** under **Payment Gateways**, then enter that profile ID. Key entry is in the companion addon; secrets are authenticated-encrypted, never repopulated in the form or sent to a browser.
6. Register the **exact webhook URL shown beside that profile**, including `?profile=ID`, in Lunixi. The environment must match the registered key. Subscribe to payment lifecycle events.
7. Run the acceptance matrix below using a registered TEST merchant before enabling live checkout.

The approved API origin is fixed at `https://api-gateway.lunixi.com`. The key determines the actual API environment; the module never sends an invented environment request flag. Only the fixed official `https://cdn.lunixi.com/lunixi-checkout.min.js` is loaded in embedded mode. Allow its documented form origins in the storefront CSP.

## Invoice and settlement rules

The owner of the authenticated selected WHMCS client account must submit a **native CSRF-protected POST** before receiving a token. Delegated client users and admin client-area masquerading are intentionally excluded in 0.1.2; a native owner session is required. The module reads invoice `DECIMAL` values, applied credit and existing account transactions on the server. It does not trust browser amounts or WHMCS's floating-point balance accessor. Unsupported currency precision, foreign-currency account rows and zero/negative balances fail before a gateway call.

A persistent reservation commits before create-intent I/O. A lost response keeps the same request/key. An expired token or uncertain result never creates a fresh charge. Administrators can reset only an authenticated terminal failure with no captured funds; the original identity/history remains retained.

| Evidence | Native invoice result |
| --- | --- |
| Initiated, processing, unknown or inconsistent | Unpaid / review; no payment transaction |
| Authorization or partial capture | Unpaid; no fulfillment |
| Complete authenticated capture | Principal credited once with official native payment helpers |
| Confirmed partial refund | Correlated native outgoing transaction; no extra client credit |
| Confirmed full refund | Native refund transaction and `Refunded` status |
| Confirmed void | Unpaid; no payment created |

The browser return only redirects the authenticated owner to the invoice. Webhook status alone is also insufficient: bounded **exact raw-byte v2 HMAC**, timestamp, signed ID/type/environment, persistent replay/payload-collision checks and an **authenticated payment GET** are required. Merchant/order/payment/currency/amount and split-parent aggregate evidence must match the immutable reservation. A single paid split leg cannot pay an invoice. Lost create-response recovery additionally requires the opaque attempt/fingerprint echoed by authenticated GET.

Invoice principal, credit, owner, currency and external transactions are compared again under the native invoice lock. Later edits or outside partial payments trigger review instead of silently overcrediting. Native cancellation is respected. Ledger, event receipt, native payment/refund records and encrypted outbox writes use **the same Capsule connection/transaction**. Direct PDO transaction starts are not used. Nontransactional tables and foreign transaction connections fail closed.

Installment fees are recorded as a **separate noninvoice income transaction**, never added to invoice principal/client credit or put in WHMCS's processor-fee field. Confirmed refunds allocate principal first, then installment fee, with each outgoing transaction associated through the native `originalTransaction()` relation. Monetary refunds do not invent product/VAT credit notes or billing notes. Partial captures remain held until full capture; refunding held partial captures requires accounting review rather than an incorrect native cash ledger entry.

## Durable admin operations

Open **Addons → Lunixi Payments**, inspect an invoice, choose an exact decimal capture/refund amount (empty for void), and click **Prepare durable operation**. Review the persisted operation ID/action/amount, then **Execute / retry same operation**. POST, native admin CSRF, addon role access and **Manage Invoice** permission are mandatory; credential creation additionally requires **Configure Payment Gateways**.

An HTTP 2xx mutation is not success. The UI stays pending/uncertain until authenticated settlement confirms the requested delta. New operation IDs are blocked while one is unresolved; reuse the saved ID. Duplicate execution cannot add another native transaction. **The standard WHMCS refund callback deliberately returns an error directing staff here:** its official contract provides no durable refund request ID. No key is guessed from transaction ID + amount, and WHMCS cannot add a second post-return refund record.

Creating a newer profile rotates authentication/webhook secrets only for the **same merchant AND TEST/LIVE scope**. Historical profile IDs, checkout configuration and snapshots remain fixed. Other merchants/environments cannot authorize old invoices. Keep archived profiles and the separate encryption key through the reconciliation/refund retention period.

## Maintenance and notifications

Schedule every few minutes, with the same environment key as the web worker:

```bash
php /path/to/whmcs/modules/gateways/lunixi/cron.php
```

The CLI reconciles batches fairly by last-check time and drains postcommit emails. Monitor its exit code and the addon outbox panel. Native payment emails and create/unsuspend commands are aborted during financial application and durably queued; `sending`/`uncertain` delivery is held for review, never blindly resent. After checking delivery and completing a held native service action, the authorized operator can acknowledge the job in the addon. Acknowledgement records review; it does not perform an external command.

**Postcommit provisioning:** normal native **ModuleCreate** and **ModuleUnsuspend** jobs run once after commit through the documented Local API. The worker checks the confirmed paid invoice/client and native service/order state; already Active is skipped, and only Pending/Suspended respectively permits a command. A stable persisted job is claimed before network work; a crash/timeout leaves `sending`/`uncertain` for review. It never blindly repeats a remote command. Renewal/change-package/custom flows stay held for native operator review because no undocumented command/payload is invented. Native notifications are suppressed during financial application. Third-party hooks with external effects must follow the same postcommit discipline; licensed acceptance must verify installed hooks, billing notes, emails and service renewal behavior.

Deactivation preserves all encrypted profiles, financial history, events, operations and outbox jobs. Re-enable and reconcile before data retention/deletion work. No uninstall table-drop routine is shipped.

## Source, build and verification

Source repository: [Lunixi-Payment/lunixi-whmcs](https://github.com/Lunixi-Payment/lunixi-whmcs). No marketplace/registry availability is implied by a source release.

```bash
composer install --working-dir=tools --no-interaction
php tools/build.php
php tools/check-artifact.php
php tools/vendor/bin/phpcs --standard=phpcs.xml
php tools/vendor/bin/phpstan analyse -c phpstan.neon --no-progress
composer audit --working-dir=tools
```

Default builds resolve the published `lunixi/php-sdk:0.29.5` package; maintainers can instead pass an explicit reviewed SDK source checkout to `php tools/build.php /absolute/path/to/lunixi-php`. No build input path is exported. PHP-Scoper isolates the shared SDK at `Lunixi\WHMCS\Vendor\Lunixi\Sdk`; merchant runtime has no sibling-repository dependency or site Composer requirement. Shared SDK owns signing, integer money and payment evidence rules.

For actual database/contract checks, create **only a disposable database named `lunixi_whmcs`** and an external mode-0600 JSON connection file containing `host`, `port`, `database`, `username`, `password`:

```bash
LUNIXI_CONTRACT_TEST=1 LUNIXI_TEST_DB_CONFIG=/secure/disposable-db.json \
  php tools/vendor/bin/phpunit -c phpunit.xml
```

The suite recreates that database's tables. It covers native helper argument contracts, DECIMAL credit/partial balance, concurrent process locking, atomic rollback/inbox/outbox, raw-byte tamper/replay/hash collision, identity/environment/rotation, complete-vs-partial capture, shared split safeguards, idempotent refunds, async operations and permissions/CSRF. PHPStan level 8 uses narrow official API declarations because licensed WHMCS source cannot be redistributed. These checks complement, rather than replace, [licensed runtime acceptance](docs/ACCEPTANCE.md).

See [security policy](SECURITY.md), [contributing](CONTRIBUTING.md), [changelog](CHANGELOG.md) and [official contracts](docs/CONTRACTS.md). MIT license covers this module and bundled SDK; WHMCS requires its separate valid license.

Financial outcomes that remain uncertain are held for review. `FAILED` alone does not authorize a new attempt: the public payment read does not expose definitive provider outcome certainty. Unresolved financial retries stop after 23 hours; authenticated GET reconciliation remains available. A timeout, token expiry or browser callback never generates a fresh financial key.

The informational POST return runs before WHMCS session/CSRF/runtime bootstrap. It reads only `invoiceid` from the URL query, discards at most 1 MiB of raw body and ignores every submitted field. It performs no invoice lookup, gateway request or financial write, and sends an empty relative 303 to `return.php?invoiceid=...`. The resumed GET still bootstraps WHMCS and checks native invoice ownership before redirecting to the invoice. Initial checkout keeps its native CSRF requirement. Configure matching proxy body limits; PHP may buffer POST input before this entry point executes.


Release 0.1.2 checks passed **36 tests / 146 assertions** using real MariaDB/InnoDB, Illuminate and the scoped PHP SDK **0.29.5**. They include the actual embedded HTML/JavaScript object/mount/XSS contract and a real HTTP server executing the built return entry point without WHMCS bootstrap: forged fields, an empty POST and an oversized POST. PHPStan level 8, PHPCS, dependency audits and merchant ZIP/secret checks also pass. These are official-contract tests, not licensed WHMCS acceptance.
