# ADR 0001 — Laravel modular monolith + React PWA with a signed offline queue

**Status:** accepted · **PRD:** v3.1 B13, B12, B16A

**Context.** One shop in Hansua–Techiman, 2–5 staff, frequent network and power cuts, retail and wholesale, cash and manual MoMo first, online payments later.

**Decision.**
- One Laravel 13 API (PHP 8.3+), business logic in `app/Actions`, pure rules in `app/Domain` (no framework dependency, testable anywhere).
- PostgreSQL is the source of truth; append-only tables are enforced by database triggers, not only by code.
- React + TypeScript PWA; the till keeps selling offline by queueing events in IndexedDB, each signed with a non-extractable ECDSA P-256 device key; the server verifies, de-duplicates by `client_uuid`, accepts real-world sales and flags rule breaks for review.
- Money as integer pesewas everywhere; prices decided on the server.

**Consequences.** Simple to host and operate; no microservices. Offline POS cannot do credit above the cached limit, refunds to MoMo, price changes or stock adjustments (OFF-003).
