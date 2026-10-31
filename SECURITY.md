# Security policy

Supported module version: 0.1.x. Report privately to **security@lunixi.com**, with version, sanitized reproduction, expected/actual result and impact. Do not include private keys, webhook secrets, browser tokens, card data or real buyer details in an issue. Use synthetic data and a disposable sandbox. Public issues may discuss nonsensitive hardening after coordinated disclosure.

Credentials use the shared SDK's authenticated SecretBox encryption with an independent 32-byte server key. Immutable profiles rotate only within the same merchant and TEST/LIVE scope; historical identities remain retained. Back up the server key separately. Password inputs are never repopulated. The API origin and official form scripts are fixed. Secrets must not be placed in URLs or JavaScript.

Native owner/admin identity, addon role access, permissions and native CSRF guard checkout/settings/mutations. Webhooks use bounded exact bytes, v2 HMAC, freshness, signed envelope/environment, durable replay/collision protection and authenticated payment reads. Event receipt and native writes share the same serialized Capsule transaction; financial operations persist before network requests and reuse their immutable ID. A callback return or HTTP 2xx alone cannot pay/refund an invoice.

Native helper behavior cannot be proved without a licensed runtime. Run the supplied licensed sandbox acceptance before deployment. Third-party hooks must defer external effects. Emails and supported create/unsuspend commands run only after commit; uncertain results are held for review and never blindly repeated. Renewal/custom provisioning, product credit-note allocation and held partial-capture refunds require the documented native review process.

Deactivation retains financial data and encrypted credentials. Restrict DB/backup access, preserve reconciliation/refund retention and perform reviewed deletion/key migration separately. Logs contain generated error references only. Default tests refuse a non-disposable database; never point them at production.
