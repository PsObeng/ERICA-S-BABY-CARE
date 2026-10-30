# Runbook — suspected tampering (SEC-INT-072)

Trigger: an integrity alert, a broken audit chain, a deploy outside the pipeline, an unexpected admin or deploy key, or a lost/stolen shop phone.

1. **Freeze deploys** — owner removes everyone except themselves from the `production` environment reviewers.
2. **Contain** — revoke all sessions (`php artisan tinker` is disabled; use the break-glass account): rotate `APP_KEY`-dependent tokens by deleting `personal_access_tokens`; revoke the affected device in the app (`POST /api/v1/devices/{id}/revoke`).
3. **Redeploy the last verified release** — pick the newest artifact whose `gh attestation verify` passes; deploy it into a fresh read-only directory; run `php artisan integrity:verify` (must pass) to leave safe mode.
4. **Check the data** — `php artisan audit:verify`; compare stock, sales and payments with the last daily owner summary; restore from the write-once backup only if records are proven wrong.
5. **Rotate every secret** — database passwords, gateway and Meta tokens, SMS keys, the release signing key.
6. **Review** — what changed, how it got in, which control failed; add a test or rule so it cannot happen the same way again.
7. **Tell people who need to know** — if customer data may be exposed, assess notification duties under the Data Protection Act, 2012 (Act 843) with an adviser.
