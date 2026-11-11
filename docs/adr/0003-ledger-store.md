# ADR 0003: Persist postings through a ledger store port

## Context

A valid domain `Posting` must update the immutable ledger entries and the cached account balances together. If any write fails, no part of the posting may remain committed. Concurrent postings must not read and overwrite the same stale balance.

## Decision

The application service depends on the `LedgerStore` port. `PostgresLedgerStore` implements that port and owns one database transaction for locking affected balance rows, calculating each `balance_after`, inserting the transaction and entries, and updating balances and versions. It locks rows in sorted account ID order to give concurrent operations a consistent lock order. `AppServiceProvider` binds the port to the PostgreSQL adapter.

## Consequences

- The application layer does not depend on Laravel's database facade or query builder.
- Ledger entries and cached balances commit or roll back together.
- A non-negative account cannot be debited below zero; allowed negative system accounts can be.
- PostgreSQL remains the production adapter and integration tests exercise it directly.
- The store returns the generated transaction ID; transaction IDs and entry IDs use UUIDv7.

## Alternatives

- Update balances outside the ledger transaction: rejected because failures could leave the ledger and cached balance inconsistent.
- Let the application service issue SQL: rejected because this would couple the application use case to PostgreSQL and make persistence harder to replace.
- Lock accounts in caller-provided order: rejected because concurrent requests that lock the same accounts in opposite orders can deadlock.
