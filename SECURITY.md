# Security — Diapers & Wipes 360

This repository follows PRD v3.1 section **B16A (code integrity and anti-tampering)**. No system can be made impossible to change; these controls make sure no single person, stolen account, compromised package or AI agent can get altered code into production unseen, and that tampering is detected and reversed.

## Repository settings the owner must turn on (GitHub → Settings)

| Control | Where | PRD |
|---|---|---|
| Organisation owned by ERICA'S BABY CARE; developers are members, not owners | Organisation | SEC-INT-001 |
| Require hardware security keys (passkeys) for everyone with write access | Org → Authentication security | SEC-INT-002 |
| Ruleset on `main`: require pull request, 1 approval from someone other than the author, dismiss stale approvals, require CODEOWNERS review, block force-push and deletion | Rules → Rulesets | SEC-INT-010/012/013 |
| Require signed commits | Same ruleset | SEC-INT-011 |
| Required status checks: `api`, `web`, `secrets`, `codeql` | Same ruleset | SEC-INT-014 |
| Secret scanning + push protection on | Code security | SEC-INT-031 |
| `production` environment with the owner as required reviewer | Environments | SEC-INT-034 |
| Actions: allow only actions pinned to a full commit SHA | Actions → General | SEC-INT-030 |

## AI coding agents (including Claude)

AI agents are untrusted contributors (SEC-INT-020..022):

- they work only through a bot account limited to **opening pull requests** on branches;
- they cannot approve, merge, deploy, change rulesets, workflows, secrets or cloud settings;
- their pull requests get the label `ai-authored` and need the same human and CODEOWNERS review;
- they never receive production credentials, database access, payment-gateway keys or the Meta token.

## Runtime protections in the code

- **Signed releases only:** CI hashes every deployed file into `release-manifest.json`, signs it, and attests build provenance; `php artisan integrity:verify` (hourly) compares the running code with it and switches the API to read-only safe mode on any mismatch.
- **Append-only records:** PostgreSQL triggers block UPDATE/DELETE on the audit log, stock movements, consents, price history and receivable allocations; payments and sold-price snapshots are immutable except for their verification/return fields.
- **Hash-chained audit log:** `php artisan audit:verify` (daily) detects any edited or deleted entry.
- **Signed offline sales:** each shop device signs its offline events with a non-extractable key; edited local data is rejected on sync.
- **No payment secrets:** there is no field anywhere for MoMo PINs, OTPs, bank passwords or card numbers.

## Reporting a problem

Tell the owner immediately on 0544935335 and follow `docs/RUNBOOKS/tamper.md`.
