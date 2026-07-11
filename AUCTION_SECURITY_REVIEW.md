# Auction Security Review

- SQL injection: Eloquent query builder and validated input are used.
- Mass assignment: auction models define explicit fillable fields.
- IDOR: auction routes use public ids and resources hide private payment details.
- File upload: requests restrict receipt/media MIME and size; SVG is rejected.
- XSS: auction descriptions are stripped on write.
- Replay attacks: payment submissions and bids require idempotency keys.
- Sensitive logging: receipt paths and provider payloads are not emitted in public resources.
- Admin finance operations: payment review requires admin role and writes audit records; future production should split `auction.payment.approve` from general admin role.
- Webhooks: schema supports provider event ids and idempotent processing; actual provider signature middleware must be added when a provider is selected.
- Storage: receipt fields are private operational records; signed download endpoints were not exposed.
