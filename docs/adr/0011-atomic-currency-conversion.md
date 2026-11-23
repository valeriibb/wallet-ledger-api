# ADR 0011: Post a conversion as one four-entry ledger transaction

## Context

A currency conversion moves value between two different currency ledgers. One debit and one credit cannot balance across currencies because the amounts and units differ. The rate also comes from a provider boundary and must not be fetched while account rows are locked.

## Decision

Resolve and persist the exchange-rate snapshot before entering the idempotent monetary transaction. `ConvertCurrency` receives that immutable `ExchangeRate`; it does not call the provider itself.

Post one ledger transaction with four entries:

1. debit the user's source wallet in the input currency;
2. credit the FX system account in the input currency;
3. debit the FX system account in the output currency;
4. credit the user's destination wallet in the output currency.

`PostgresConversionStore` locks all four accounts in UUID order, validates both user wallets under the lock, and delegates the posting to `LedgerStore`. The transaction metadata stores the rate, source, validity time, exact output, rounded output, rounding mode, and signed rounding difference.

The fractional rounding difference cannot be a ledger entry because ledger amounts are integer minor units. The integer currency legs remain balanced through the FX accounts, while metadata preserves the fractional audit evidence.

## Consequences

- Each currency balances independently inside the conversion transaction.
- The user source balance, user destination balance, FX balances, ledger entries, outbox event, and idempotency response commit or roll back together.
- A replay returns the stored response and cannot post another conversion.
- Investigation can reproduce the calculation without depending on the current cache or current provider rate.
- The current fake provider is resolved before idempotency execution. A future remote provider should add a durable quote workflow so a completed replay does not depend on provider availability after a cache miss.

## Alternatives

### One cross-currency debit and credit

Rejected because amounts in different units cannot satisfy the per-currency balance invariant.

### Two independent ledger transactions

Rejected because a crash between them could debit the source without crediting the destination.

### Store fractional dust as a ledger entry

Rejected because ledger entries are integer minor units. The exact fractional difference belongs in immutable transaction metadata.
