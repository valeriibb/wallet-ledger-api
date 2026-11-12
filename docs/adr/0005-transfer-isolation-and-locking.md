# ADR 0005: Use ordered row locks for transfers

## Context

A transfer reads two account states and two balances, then writes one ledger transaction, two immutable entries, and both cached balances. Concurrent transfers may touch the same rows. Reading a balance without a lock can let two requests spend the same money. Locking source and destination in request order can create a circular wait for simultaneous A-to-B and B-to-A transfers.

The operation runs on PostgreSQL. The expected workload contains short transactions and can have high contention on a wallet. Every transfer must preserve the non-negative balance, balanced posting, and cached-balance invariants.

## Decision

Use PostgreSQL's default `READ COMMITTED` isolation level with explicit pessimistic row locks.

Inside one outer database transaction, `PostgresTransferStore`:

1. Sorts the two account UUIDs and locks the `accounts` rows with `SELECT ... FOR UPDATE` in that order.
2. Checks ownership and creates a domain posting from the locked account state.
3. Calls `LedgerStore`, which sorts the same account UUIDs and locks the `account_balances` rows in that order.
4. Rechecks available funds from the locked balances.
5. Inserts the ledger transaction and entries and updates both cached balances before commit.

The transaction contains database work only. It makes no HTTP, email, queue, or other network calls.

If PostgreSQL aborts the transaction with SQLSTATE `40001` or `40P01`, repeat the whole outer transaction up to three times. Use exponential backoff with jitter between attempts. Do not retry domain, authorization, validation, or programming errors.

## Consequences

- A competing transfer waits and then reads the latest committed row state before spending funds.
- A consistent lock order prevents the known A-to-B versus B-to-A circular wait.
- Account state, ledger entries, and cached balances commit or roll back as one unit.
- `READ COMMITTED` allows unrelated accounts to proceed without the broader cost and abort rate of `SERIALIZABLE`.
- Ordered locks reduce deadlocks but cannot guarantee that future queries or other transaction types never deadlock, so bounded retry remains necessary.
- Hot accounts serialize transfer work and can become a throughput limit. This is an accepted tradeoff for simple and strong correctness.

## Alternatives

### `SERIALIZABLE`

PostgreSQL could detect unsafe transaction histories without explicit row locking. It would also abort more transactions under contention, require the same whole-transaction retry discipline, and add work for a transfer whose conflicting rows are already known. We can reconsider it if future invariants depend on predicates or ranges that row locks cannot protect.

### Optimistic version checks

An update such as `UPDATE ... WHERE version = ?` avoids waiting when conflicts are rare. A conflict requires rebuilding and retrying the complete two-account posting. The wallet workload expects hot rows, so repeated optimistic failures would add complexity without a clear benefit. The existing version remains useful for diagnostics and a possible future optimistic adapter.

### PostgreSQL advisory locks

Advisory locks can represent application resources that have no row. Accounts already have rows, and row locks integrate directly with normal SQL visibility. Advisory locks also require every writing code path to follow the same custom convention; one missed path would bypass the protection.

### Lock in request order

This is simpler locally but lets opposite transfers acquire their first lock in different orders and form a circular wait. It is rejected.
