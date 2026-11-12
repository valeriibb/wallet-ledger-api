# ADR 0006: Store idempotency state with the money transaction

## Context

A client can lose the HTTP response after a transfer commits and retry the request. A normal retry would create another valid ledger transaction and move money twice. Concurrent retries with the same key must also create only one transaction.

Saving an idempotency record after the transfer is unsafe. The process could commit the money movement and crash before saving the response. A later retry would find no record and transfer the money again.

## Decision

Require an `Idempotency-Key` header for `POST /v1/transfers`. Scope each key to the authenticated owner.

Create a SHA-256 request hash from a canonical representation containing the HTTP method, path, source account, destination account, integer amount, currency, and reference.

`IdempotencyExecutor` is the application port. `PostgresIdempotencyExecutor` is the adapter and owns the outer PostgreSQL transaction. It atomically:

1. Claims `(owner_id, key)` with `INSERT ... ON CONFLICT DO NOTHING`.
2. Runs the transfer inside the same outer transaction for a new claim.
3. Stores the HTTP status, response body, and ledger transaction ID.
4. Commits the claim, ledger posting, balances, and stored response together.

For an existing key:

- the same request hash and `completed` status replay the stored response;
- a different request hash returns 422;
- a non-expired `processing` record returns 409;
- an expired record can be claimed for a new operation.

Keep records for 24 hours. A processing lease lasts 30 seconds. Retry SQLSTATE `40001` and `40P01` around the complete outer idempotency transaction.

## Consequences

- A committed transfer always has its completed idempotency response in the same commit.
- Concurrent requests with one key wait on the unique row and observe one completed result.
- Request bodies are not stored for comparison; only a fixed-length SHA-256 hash is retained.
- JSONB can change object key order, but replayed JSON keeps the same semantic data.
- After 24 hours, reusing the key can create a new transfer. Clients must generate a new high-entropy key for each intended operation and keep it stable across retries.
- Failed domain operations roll back the claim, so a corrected retry can use the key again.

## Alternatives

### Save the response after the transfer commits

Rejected because a crash between the two commits can move money without an idempotency record and allow a duplicate transfer.

### Redis lock and PostgreSQL transfer

Rejected because correctness would depend on two systems without one atomic commit. Lock expiry, Redis failure, or network partitions could allow duplicates.

### Store only the key without a request hash

Rejected because a client bug could reuse one key for a different amount or destination and receive an unrelated old response without a clear error.

### Keep keys forever

Rejected because storage would grow without a bound and key reuse would remain blocked permanently. The 24-hour retention period is explicit API behavior and can be changed later with operational evidence.
