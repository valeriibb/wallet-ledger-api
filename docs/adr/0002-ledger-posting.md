# ADR 0002: Validate ledger postings in the domain

## Context

The ledger must satisfy invariant I1: total debits equal total credits for each currency. This rule must hold before a posting can be written to PostgreSQL, and it must not depend on Laravel or database behavior.

## Decision

Represent each line as an immutable `JournalEntry` with an account ID, explicit debit or credit direction, and positive `Money`. Represent a complete operation as an immutable `Posting`. A `Posting` is created only when it has at least two entries and its debit and credit totals match independently for every currency.

## Consequences

- Application code can only pass a validated `Posting` to the persistence boundary.
- Domain tests can verify accounting rules without booting Laravel or connecting to PostgreSQL.
- Multi-currency postings are allowed, but amounts in different currencies are never added or netted.
- Integer overflow during aggregation is rejected by `Money` arithmetic.

## Alternatives

- Validate balance only in the database: rejected because this mixes domain policy with persistence and makes the rule harder to reuse before writes.
- Compare one grand total across currencies: rejected because minor units in different currencies are not comparable without an explicit exchange rate.
