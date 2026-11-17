# ADR 0007: Publish ledger events through a transactional outbox

## Context

A ledger transaction and a message broker cannot share one local PostgreSQL transaction. Publishing before the database commit can expose an event for money that later rolls back. Publishing after the commit can lose the event if the process crashes between the commit and the broker call.

## Decision

Insert one `outbox_events` row inside the same PostgreSQL transaction that creates the ledger transaction, entries, and balance updates.

`OutboxWriter` is the application port. `PostgresOutboxWriter` stores the event. If the outbox insert fails, the complete ledger operation rolls back.

A separate `outbox:relay` command reads pending rows in bounded batches. `PostgresOutboxRelayStore` uses:

```sql
FOR UPDATE SKIP LOCKED
```

This allows several relay processes to work concurrently without waiting for or normally publishing the same row. After a successful publish, the relay sets `published_at`. A failed attempt increments `attempts` and leaves the event pending.

Delivery is at least once. A relay can publish successfully and crash before setting `published_at`; the next run will publish the same event again. Consumers must deduplicate by the stable outbox event ID.

## Consequences

- A committed ledger transaction always has a durable event waiting for publication.
- A rolled-back ledger transaction has no outbox event.
- Broker downtime does not block money operations after the outbox row is committed.
- Several relay workers can process different batches concurrently.
- Consumers must be idempotent because crash recovery can create duplicate delivery.
- The relay currently uses a logging publisher adapter; broker and webhook adapters can replace it through `EventPublisher`.

## Alternatives

### Publish directly before commit

Rejected because consumers could observe an operation that later rolls back.

### Publish directly after commit

Rejected because a crash after commit can permanently lose the event.

### Use a distributed transaction

Rejected because PostgreSQL and common brokers do not provide a simple shared transaction, and two-phase commit would add operational complexity and availability risks.

### Poll without row locking

Rejected because concurrent relay processes would select and publish the same pending rows during normal operation.
