# Acceptance record and licensed runtime procedure

## What was actually executed

The local suite uses PHP 8.4.1, actual MariaDB 11/InnoDB, actual Illuminate Database 12.70.2, the actual namespace-isolated Lunixi PHP SDK 0.29.3 and a deterministic injected HTTP transport. Official WHMCS gateway helpers, authentication and transaction models are **explicit contract stand-ins** in `tests/native-contract.php`. No actual Lunixi charge is created. No licensed WHMCS runtime was found in the known workspace; no licensed download/account/terms were acquired.

Do not rename these results as a real WHMCS install, native checkout, ionCube compatibility or certified 8.13/9.x acceptance. Current official release targets reviewed 31 October 2026: 8.13.7, 9.0.9 and 9.1.0. Source APIs do not prove identical behavior in every encoded runtime version.

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
| Missing/wrong CSRF and invoice owner | No intent or mutation; delegated/masquerade users denied in 0.1.0 |
| Client owner invoice checkout | Credit + prior payments deducted from exact native DECIMAL balance |
| Hosted and embedded TEST form | Official form only; correct owner; no card data in native server/logs |
| Decline/retry, lost create response | Same reserved key/body; terminal reset only after authenticated failure |
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
