# ADR 0010: Represent exchange rates as exact decimals and round once

## Context

Money is stored as integer minor units. Currency conversion still needs a fractional exchange rate. PHP floating-point values cannot represent many decimal fractions exactly, and implicit rounding can move or lose minor units.

Rates also come from an external source. Repeated provider calls add latency and can return a different rate during one user operation. We need the exact rate, source, and validity time for later investigation.

## Decision

`ExchangeRateProvider` is the application port. `FakeExchangeRateProvider` is the deterministic source adapter used by this project. `CachedExchangeRateProvider` decorates the source with a five-minute cache and stores every fetched snapshot in PostgreSQL.

An `ExchangeRate` stores a normalized decimal string with 10 fraction digits. Conversion uses BCMath decimal integer operations. It never converts the rate or money to `float`.

The result is rounded once to the nearest target minor unit using **round half up**:

- a remainder below `0.5` minor unit rounds down;
- a remainder equal to or above `0.5` minor unit rounds up.

The conversion transaction will store the used rate, source, validity time, and rounding information in its metadata. Both sides of each currency are posted through system FX accounts, so each currency balances independently.

## Consequences

- The same input amount and rate always produce the same integer result.
- Historical investigations can identify the exact rate snapshot used.
- Provider traffic is bounded by the cache TTL.
- Half-up rounding can create a fractional economic difference; Phase 8.2 will track it through the FX system accounts and transaction metadata.
- Currency exponent differences are outside this project; API amounts are already expressed in each currency's minor units.

## Alternatives

### PHP float and `round()`

Rejected because binary floating point is not exact enough for financial calculations.

### Truncate every conversion

Rejected because it creates a systematic bias against the receiver.

### Banker's rounding

Valid in some accounting systems, but rejected here because half-up is simpler for the documented fake-provider contract. Changing it later requires a versioned business rule.
