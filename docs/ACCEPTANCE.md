# Acceptance record and licensed runtime procedure

**Historical verified merchant ZIP 0.1.3:** [`lunixi-whmcs-0.1.3.zip`](https://github.com/Lunixi-Payment/lunixi-whmcs/releases/download/v0.1.3/lunixi-whmcs-0.1.3.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-whmcs/releases/download/v0.1.3/SHA256SUMS) · [Release v0.1.3](https://github.com/Lunixi-Payment/lunixi-whmcs/releases/tag/v0.1.3) · [Passing release CI](https://github.com/Lunixi-Payment/lunixi-whmcs/actions/runs/41242078452).

Public ZIP SHA-256: `d5a328aa9d7d53af4605d4d01f593b222d2fb046058793049f3103ca4e2d54a6`. The downloaded archive passed public checksum, GitHub asset digest and ZIP CRC checks on 1 November 2026.

## 0.1.3 shared SDK update

**Historical acceptance record:** these results apply to the 0.1.3 ZIP only.

The recorded 0.1.3 source/build release bundled published PHP SDK **0.29.6**, including its PHP 8.5 cURL compatibility correction. Fresh acceptance of the newly built 0.1.3 ZIP passed **36 tests / 146 assertions** on PHP **8.4.1**, real MariaDB 11/InnoDB and Illuminate. Both module classes/assets and the scoped SDK were loaded from the extracted new ZIP, with a new schema-only TEST database user. Prior-version receipts below are historical and do not certify the rebuilt package. Licensed WHMCS runtime acceptance remains a separate operator-provided step.

## What was actually executed (historical baseline)

The historical 0.1.1 baseline suite used PHP 8.4.1, actual MariaDB 11/InnoDB, actual Illuminate Database 12.70.2, the actual namespace-isolated Lunixi PHP SDK 0.29.5 and a deterministic injected HTTP transport. Official WHMCS gateway helpers, authentication and transaction models are **explicit contract stand-ins** in `tests/native-contract.php`. No actual Lunixi charge is created. No licensed WHMCS runtime was found in the known workspace; no licensed download/account/terms were acquired.

Do not rename these results as a real WHMCS install, native checkout, ionCube compatibility or certified 8.13/9.x acceptance. Official release targets reviewed on 31 October 2026: 8.13.7, 9.0.9 and 9.1.0. Source APIs do not prove identical behavior in every encoded runtime version.

Release 0.1.1 checks passed **34 tests / 134 assertions** using real MariaDB/InnoDB, Illuminate and the scoped PHP SDK **0.29.3**. They include the actual embedded HTML/JavaScript object/mount/XSS contract and a real HTTP server executing the built return entry point without WHMCS bootstrap: forged fields, an empty POST and an oversized POST. PHPStan level 8, PHPCS, dependency audits and merchant ZIP/secret checks also pass. These are official-contract tests, not licensed WHMCS acceptance.

## Licensed helper rollback runner

Provide an existing legally licensed **disposable sandbox**, disable real mail/registrar/provisioning endpoints, install the built module and addon, and create an unpaid disposable invoice owned by a test client. Use a fresh invoice with no Lunixi attempt. Back up the sandbox first. This runner writes native payment/refund rows then intentionally rolls back; it sends no Lunixi financial API call.

```bash
LUNIXI_LICENSED_TEST=1 php tests/licensed-acceptance.php /licensed/disposable/whmcs INVOICE_ID
```

The runner checks actual `checkCbInvoiceID` / `checkCbTransID` / five-argument `addInvoicePayment`, native Capsule/PDO identity and transaction nesting, `AddTransaction` outgoing records, the actual `originalTransaction()` relationship, `UpdateInvoice` refund status, event/outbox rollback and native invoice rollback. A helper that commits early, an immutable billing-note rule or changed native relation/API is an acceptance failure requiring adapter work. The native runtime and credentials are never redistributed.

## Required browser and TEST-merchant matrix

Run for each advertised native version and the site's actual order form/theme. Record version, PHP/ionCube, DB engine and actual result without credentials/buyer data.

| Scenario | Expected |
| --- | --- |
| Install, disable, re-enable | Tables installed; financial/profile/operation history retained |
| Admin roles + Manage Invoice/Configure Payment Gateways | Unauthorized role/action denied, profile secrets never repopulated |
| Missing/wrong CSRF and invoice owner | No intent or mutation; delegated/masquerade users denied in 0.1.1 |
| Client owner invoice checkout | Credit + prior payments deducted from exact native DECIMAL balance |
| Hosted and embedded TEST form | Official form only; correct owner; no card data in native server/logs |
| Decline/retry, lost create response | Same reserved key/body; reset only after authenticated VOIDED/EXPIRED with zero settlement, never FAILED alone |
| Return URL forged success | No native payment created |
| Webhook tamper/stale/replay/collision/wrong env | No new native payment/event consumption |
| Genuine complete capture | Principal paid once; fee separate; invoice/event/outbox atomic |
| Native write failure after account insert | Invoice, accounts, inbox and outbox all roll back |
| Authorization, partial capture, complete remaining capture | Unpaid until complete; no early provisioning |
| Correct split parent, incomplete/compensated split | Only balanced complete aggregate can pay |
| Monetary partial/full refund and timeout retry | Persisted operation ID; original native relation; no duplicate credit |
| Async capture/refund/void | Pending until authenticated delta; new operation blocked |
| Edited/cancelled invoice, external partial payment | Review; no automatic overcredit/fulfillment |
| Credential/webhook rotation | Same merchant+TEST/LIVE only; historical identity preserved |
| Postcommit email | No mail before commit; uncertain delivery held, not blindly repeated |
| ModuleCreate / ModuleUnsuspend | Native Pending/Suspended service opens once after commit, Fraud/cancelled/custom order holds stay held; native Active is skipped |
| Remote command timeout/crash | Stable job becomes sending/uncertain; operator checks remote/native state, no automatic retry |
| Renew/change-package/custom third-party hooks | Held job is reviewed/completed through native service UI; billing dates/notes verified |
| Product tax/billing notes/refund accounting | Native policy reviewed; monetary refund must not invent item/VAT allocations |
| CLI fairness/outbox | New invoices not starved by old paid rows; no secret/PII output |

Native email/provisioning/notification hooks that run outside the official pre-send/pre-module boundaries must be separately adapted to a postcommit outbox before deployment. This module cannot undo an unrelated plugin's external network side effect. Native domain/registrar automation is not claimed by this hosting gateway's tests.

## Held or uncertain jobs

Inspect the job ID/action/native service reference in the addon, then inspect actual delivery/remote account and native service state. Create/unsuspend remote commands are attempted once after commit; `sending`/`uncertain` is deliberately not automatically reset to `ready`. Renew/change-package jobs remain `review` because no guessed command/payload is used. Perform the required native action only after verifying it has not already happened, then acknowledge the job in the CSRF/role-gated addon. Acknowledgement records review without sending another command.

## 0.1.2 checkout correction

The targeted existing contract suite plus new default/backend-URL/legacy-cache cases passed **36 tests /146 assertions** on the same disposable MariaDB fixture using shared SDK0.29.5. PHPStan8 and PHPCS23 passed. New profiles default to embedded; historical explicit modes and all immutable request/key records remain unchanged. Hosted uses only the validated encrypted backend URL; its token may differ from the embedded token. No licensed runtime/live charge or new merchant acceptance is claimed. The0.1.1 baseline above is retained as historical evidence.

Historical public **0.1.3** is downloadable with its checksum and [passing release CI](https://github.com/Lunixi-Payment/lunixi-whmcs/actions/runs/41242078452). All **315 extracted files** match the locally tested ZIP byte for byte. The **36 tests / 146 assertions** ran on PHP **8.4.1**, actual MariaDB **11.8.6/InnoDB**, Illuminate and explicit official-contract stand-ins, with published scoped PHP SDK **0.29.6**. This verifies the distributed adapter payload and contracts; a licensed WHMCS native runtime, complete browser checkout, real provisioning/mail and bank/3DS acceptance remain unexecuted.
