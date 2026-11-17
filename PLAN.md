# Wallet & Ledger API — майстер-план проєкту

> Цей файл — єдине джерело істини для проєкту. Поклади його в корінь репозиторію як `PLAN.md`
> (а розділи 0 і 1 скопіюй ще й в `AGENTS.md`). Codex має читати його на початку кожної сесії.

---

## 0. Роль асистента (інструкція для Codex)

Ти — senior backend-ментор і reviewer. Ти ведеш мене по цьому плану від порожнього репозиторію до
production-ready портфоліо-проєкту. Моя мета: **глибоко розуміти** кожне рішення, щоб пояснити його
на співбесіді Middle/Senior PHP backend.

### Протокол роботи

1. **Одна задача за раз.** Бери наступний незавершений крок із розділу 12 (Roadmap). Не перестрибуй.
2. **Архітектурні рішення веде ментор.** Не вимагай від учня спроєктувати систему або обрати архітектуру до того, як він вивчив потрібні поняття. Самостійно приймай рішення в межах плану, коротко пояснюй причини та фіксуй нетривіальні рішення в ADR.
3. Перед кодом навчи принципу на конкретному сценарії: проблема → основне правило → поширена помилка й правильний варіант → компроміси → яке рішення застосуємо. Тільки після цього коротко (5–10 рядків) поясни обсяг коду.
4. Давай код **малими порціями** (один файл / одна відповідальність), одразу з тестом.
5. Після кожної порції: що я маю запустити, що очікувати побачити.
6. Після завершення **теми**, а не кожного файла, постав одне співбесідне питання про вже пояснений принцип. Не перетворюй навчання на серію дрібних перевірок і не проси учня обирати архітектуру до пояснення основ.
7. Після відповіді коротко оціни технічний зміст і виправ важливі помилки в англійській. Потім автоматично продовжуй заплановане навчання та роботу; не вимагай окремого «go», якщо учень уже попросив продовжувати. Якщо відповідь показала прогалину, поясни її просто й рухайся далі після виправлення.
8. Веди файл `docs/PROGRESS.md`: що зроблено, що відкладено, відкриті питання. Оновлюй після кожного кроку.
9. Кожне нетривіальне рішення — в `docs/adr/NNNN-назва.md` (формат ADR: контекст → рішення → наслідки → альтернативи).
10. Не додавай фічі, яких немає в плані, без мого погодження. Не роби «на виріст».
11. Якщо я пишу код сам — зроби code review: коректність, concurrency, безпека, читабельність.
12. Якщо щось у плані суперечить найкращій практиці — скажи прямо і запропонуй зміну, не мовчи.
13. Перед використанням версій пакетів/фреймворку перевіряй актуальну документацію, не покладайся на пам'ять.
14. Навчай системному дизайну поступово в потрібному порядку: вимоги й сценарії → доменні інваріанти → модель даних → транзакційність і concurrency → межі компонентів та ports/adapters → відмови й відновлення → альтернативи та компроміси. Пояснюй потрібну теорію до того, як вона знадобиться в коді або відповіді на співбесіді.
15. У кожній темі спочатку розбери реальний сценарій запиту від входу до результату. Поясни, який принцип працює на кожному кроці, чому типове хибне рішення небезпечне, і як це виглядає у production. Потім пов'яжи принцип із ролями класів, схемою залежностей і малими порціями коду.
16. Називай архітектурні ролі явно, наприклад `LedgerStore → port`, `PostgresLedgerStore → adapter`, `ServiceProvider → composition root`, і пояснюй, навіщо ця межа існує.
17. Основна мова навчання — проста англійська рівня B1. Використовуй справжні технічні терміни з PHP/backend-співбесід, але пояснюй їх короткими реченнями й простою лексикою.
18. Готуй до реальної технічної співбесіди: після відповіді окремо оціни технічний зміст і коротко виправ лише важливі помилки в англійській. Покажи природніший варіант відповіді, який можна сказати інтерв'юеру.

### Режими

- **pair** (режим за замовчуванням у цьому проєкті): ти створюєш код малими порціями, пояснюєш рішення та показуєш, як перевірити результат; я навчаюся, спостерігаючи, і ставлю питання.
- **learn**: ти пояснюєш і даєш завдання, я пишу код сам, ти ревʼюїш.
- **review**: я приношу готове, ти критикуєш.

### Заборонено

- `float`/`double` для грошей. Будь-де. Жодних `round()` на сумах.
- `UPDATE`/`DELETE` у таблиці ledger entries.
- Бізнес-логіка в контролерах, Eloquent-моделях-«жирунах» і Form Requests.
- «Тимчасові» хаки без запису в `docs/PROGRESS.md`.
- Мокати базу в тестах concurrency та ledger. Тільки реальний PostgreSQL.

---

## 1. Продукт

**Wallet & Ledger API** — backend фінансового ядра: мультивалютні гаманці, перекази, поповнення,
конвертація, повернення, з **подвійним записом (double-entry)**, незмінним журналом, ідемпотентністю
і надійною доставкою подій.

Це **не** платіжний шлюз і не реальні гроші. Зовнішній світ (банк/карта) емулюється системними рахунками
і fake-провайдером.

### Ключові інваріанти (на них тримається все)

| # | Інваріант |
|---|-----------|
| I1 | Для кожної транзакції сума дебетів = сумі кредитів (в межах кожної валюти). |
| I2 | Записи ledger незмінні. Виправлення тільки сторнуванням (reversal). |
| I3 | Баланс користувацького рахунку ніколи не стає відʼємним. |
| I4 | Повторний запит з тим самим idempotency key не рухає гроші вдруге. |
| I5 | Сума всіх записів по системі = 0 (по кожній валюті). |
| I6 | Закешований баланс рахунку = сумі його записів (перевіряє reconciliation). |
| I7 | Подія про операцію не губиться, навіть якщо черга/воркер впали (outbox). |
| I8 | Гроші зберігаються як integer в мінімальних одиницях (копійки/центи). |

---

## 2. Технологічний стек

| Шар | Вибір | Примітка |
|-----|-------|----------|
| Мова | PHP 8.4 | `declare(strict_types=1)` всюди, readonly класи, enums |
| Фреймворк | Laravel (остання стабільна; перевір версію) | Використовуємо як інфраструктуру, не як архітектуру |
| БД | PostgreSQL 16+ | Основа проєкту. Не MySQL, не SQLite |
| Кеш/черги | Redis | Черги, rate limiting, lock-и для планувальника |
| Черги | Laravel Queue + Horizon | |
| Авторизація | Laravel Sanctum (API tokens) + API keys для сервіс-клієнтів | |
| Тести | Pest або PHPUnit | Вибери одне й тримайся його |
| Статичний аналіз | PHPStan/Larastan, рівень max | |
| Стиль | Laravel Pint | |
| Mutation testing | Infection | Для Domain/Ledger |
| Load testing | k6 | |
| Контейнери | Docker Compose | php-fpm, nginx, postgres, redis, mailpit, horizon |
| CI | GitHub Actions | |
| Документація API | OpenAPI 3.1 | Генерація або ручна, але контрактні тести |
| Observability | Structured JSON logs, Prometheus metrics, Grafana (опційно), Sentry (опційно) | |

---

## 3. Архітектура

### 3.1 Стиль

**Modular monolith**, feature-first, з прагматичною гексагональною архітектурою **лише там, де це окупається**.

- `Ledger` — серце системи. Тут чистий домен, жодних залежностей від Laravel/Eloquent у доменних класах.
- Решта модулів — ідіоматичний Laravel із чіткими межами.
- Модулі спілкуються **тільки через публічні сервіси/інтерфейси модуля та доменні події**, не лізуть в таблиці одне одного.

### 3.2 Модулі

```
src/                         # (або app/Modules)
├── Shared/                  # Money, Currency, Clock, Uuid, помилки, трейти інфраструктури
├── Identity/                # користувачі, токени, API keys, ролі
├── Ledger/                  # ДОМЕН: рахунки, проводки, інваріанти, posting
├── Wallet/                  # use-cases: transfer, deposit, withdraw, reverse, statement
├── Currency/                # курси валют, конвертація
├── Events/                  # outbox, вихідні вебхуки, підписки
├── Reconciliation/          # нічна звірка, звіти про розбіжності
└── Audit/                   # журнал дій, trace-id
```

### 3.3 Шари всередині модуля

```
Wallet/
├── Domain/            # Entities/ValueObjects/Domain events/Exceptions (без Laravel)
├── Application/       # Use cases (Actions): TransferFunds, Deposit, ReverseTransaction…
│   ├── Command/       # DTO вхідних команд
│   └── Port/          # Інтерфейси: LedgerGateway, Clock, IdGenerator, Notifier…
├── Infrastructure/    # Eloquent/Query Builder, адаптери, черги, провайдери
└── Http/              # Controllers, FormRequests, Resources, Routes
```

Правила залежностей: `Http → Application → Domain`; `Infrastructure → Application/Domain`.
Domain **не знає** ні про Laravel, ні про БД. Це перевіряється **архітектурним тестом** (Pest arch / Deptrac / PHPat).

### 3.4 Що НЕ робимо

- Не створюємо Repository для кожної моделі. Інтерфейс — тільки на реальній межі (ledger storage, rate provider, webhook sender).
- Не робимо CQRS/Event Sourcing «для краси». Ledger і так append-only; читання — прості SQL/запити.
- Не робимо мікросервіси.
- Не обгортаємо Eloquent у власний ORM.

### 3.5 Чому саме так (для README / співбесіди)

Фінансове ядро потребує сильних транзакційних гарантій → одна база, одна транзакція, один процес.
Мікросервіси додали б розподілені транзакції (saga/2PC) без користі для задачі. Модульні межі дозволяють
пізніше виокремити Events/Notifications без переписування.

---

## 4. Модель даних (PostgreSQL)

Усі PK — UUID (v7, щоб були впорядковані за часом). Суми — `BIGINT` у мінімальних одиницях.
Час — `TIMESTAMPTZ` (UTC). Усі FK з індексами.

### 4.1 Таблиці

**users** — id, email (unique), password_hash, status, created_at.

**accounts**
- id, owner_id (nullable для системних), `type` (`user_wallet` | `system_cash` | `system_fees` | `system_fx` | `system_suspense`),
  `currency` (CHAR(3)), `status` (`active`|`frozen`|`closed`), created_at.
- `UNIQUE (owner_id, currency)` для `user_wallet`.

**account_balances** (денормалізований баланс)
- account_id (PK, FK), `balance` BIGINT NOT NULL, `version` BIGINT NOT NULL, updated_at.
- `CHECK` баланс ≥ 0 для користувацьких рахунків (через окрему колонку `allow_negative BOOLEAN` або partial constraint).
- Оновлюється **в тій самій транзакції**, що й запис проводок.

**ledger_transactions** (журнал)
- id, `type` (`transfer`|`deposit`|`withdrawal`|`conversion`|`reversal`|`fee`), `status` (`posted`|`reversed`),
  `reversal_of` (nullable FK на себе), `reference` (зовнішній), `metadata` JSONB, `created_at`.

**ledger_entries** (незмінні проводки)
- id, transaction_id (FK), account_id (FK), `direction` (`debit`|`credit`), `amount` BIGINT CHECK (amount > 0),
  `currency` CHAR(3), `balance_after` BIGINT (знімок для швидких виписок), created_at.
- **Захист від UPDATE/DELETE:** тригер `BEFORE UPDATE OR DELETE` кидає виняток + `REVOKE UPDATE, DELETE` для app-ролі БД.
- Індекси: `(account_id, created_at, id)` для виписок; `(transaction_id)`.

**idempotency_keys**
- `(owner_id, key)` UNIQUE, `request_hash`, `status` (`processing`|`completed`), `response_code`, `response_body` JSONB,
  `transaction_id`, `locked_until`, created_at, expires_at.

**outbox_events**
- id, aggregate_type, aggregate_id, `event_type`, payload JSONB, `occurred_at`, `published_at` (nullable), `attempts`.
- Частковий індекс по `published_at IS NULL`.

**webhook_endpoints** — id, owner_id, url, secret (зашифрований), events[], is_active.
**webhook_deliveries** — id, endpoint_id, event_id, attempt, status, response_code, next_retry_at, last_error.

**exchange_rates** — base, quote, rate (NUMERIC(20,10)), source, valid_from.
**reconciliation_runs** — id, started_at, finished_at, status, summary JSONB.
**reconciliation_discrepancies** — id, run_id, kind, account_id, expected, actual, details JSONB.
**audit_logs** — id, actor_id, action, subject, trace_id, ip, payload JSONB, created_at.

### 4.2 Рішення, які треба зафіксувати в ADR

- ADR-001: Чому integer minor units, а не decimal/float.
- ADR-002: Чому double-entry, а не одна колонка `balance`.
- ADR-003: Чому незмінний ledger + reversal.
- ADR-004: Чому закешований баланс разом із ledger (і як гарантуємо збіг).
- ADR-005: Рівень ізоляції та стратегія блокувань.
- ADR-006: Idempotency: дизайн і TTL.
- ADR-007: Outbox замість прямої відправки в чергу.
- ADR-008: UUIDv7 vs bigint.
- ADR-009: Modular monolith.

---

## 5. Ядро: алгоритм переказу

```
POST /v1/transfers
Headers: Authorization, Idempotency-Key (обовʼязковий), X-Request-Id
Body: { from_account_id, to_account_id, amount, currency, reference? }
```

1. Валідація (форма) у Form Request → команда `TransferFunds` (DTO, `Money` value object).
2. **Idempotency gate:** спробувати вставити ключ (`INSERT ... ON CONFLICT DO NOTHING`).
   - Новий → продовжити.
   - Існує + `completed` + однаковий `request_hash` → повернути збережену відповідь.
   - Існує + інший hash → `422` (ключ використано з іншим тілом).
   - Існує + `processing` → `409` (запит у процесі).
3. `BEGIN` (READ COMMITTED).
4. **Заблокувати рахунки у детермінованому порядку** (сортування за UUID):
   `SELECT ... FROM account_balances WHERE account_id IN (...) ORDER BY account_id FOR UPDATE`.
   Це усуває deadlock A→B / B→A.
5. Перевірки домену: рахунки активні, валюти збігаються, `balance >= amount`, власник має право, ліміти.
6. Створити `ledger_transaction` + 2 `ledger_entries` (debit from / credit to) з `balance_after`.
7. Оновити `account_balances` (balance, version++).
8. Записати `outbox_events` (`transfer.completed`).
9. Зберегти відповідь в `idempotency_keys`, позначити `completed`.
10. `COMMIT`.
11. Retry-обгортка на `40001` (serialization_failure) і `40P01` (deadlock_detected) з backoff і лімітом.

**Усе, що між `BEGIN` і `COMMIT`, — тільки БД. Жодних HTTP-викликів, email, черг.**

### Інші операції

- **Deposit / Withdrawal:** проводка між користувацьким рахунком і `system_cash`.
- **Conversion:** 4 проводки (user_from → fx_pool_from, fx_pool_to → user_to) з курсом, зафіксованим у метаданих.
  Правило округлення — явне й описане в ADR; різницю (rounding dust) відправляй на `system_fx`.
- **Reversal:** нова транзакція з протилежними проводками, `reversal_of` вказує на оригінал, оригінал → `reversed`.
  Повторне сторнування заборонене.
- **Fee:** окрема проводка на `system_fees`.

---

## 6. Надійна доставка подій

### Outbox
- Подія пишеться в `outbox_events` **в тій самій транзакції**, що й проводки.
- Relay-процес (команда/job) читає непублікованi (`FOR UPDATE SKIP LOCKED`), публікує в чергу, ставить `published_at`.
- Гарантія: **at-least-once**. Тому всі споживачі ідемпотентні (event_id як ключ дедуплікації).

### Вихідні вебхуки
- Підпис: `HMAC-SHA256(secret, timestamp + "." + body)`, заголовки `X-Signature`, `X-Timestamp`, `X-Event-Id`.
- Retries: експоненційний backoff з jitter, ліміт спроб, потім `dead` + можливість ручного replay.
- Захист від replay: клієнт перевіряє timestamp (±5 хв).
- SSRF-захист: заборона на приватні IP/localhost у URL endpointʼа.

---

## 7. Reconciliation

Нічний (і за запитом) job:

1. Для кожної валюти: `SUM(debit) = SUM(credit)` по всій таблиці entries (I1, I5).
2. Для кожного рахунку: `account_balances.balance = SUM(credit) - SUM(debit)` по його проводках (I6).
3. Перевірка послідовності `balance_after` в межах рахунку.
4. Транзакції без рівно збалансованих проводок.
5. Outbox-події, що висять непублікованими довше порогу.

Результат — `reconciliation_runs` + `discrepancies`, метрика й алерт при розбіжності.
Окремий **chaos-тест**: навмисно зіпсувати баланс у тестовій БД і переконатися, що звірка це знаходить.

---

## 8. API

Версіонування `/v1`. JSON. Єдиний формат помилок (RFC 9457 Problem Details).

| Метод | Шлях | Опис |
|-------|------|------|
| POST | `/v1/auth/register`, `/login` | Реєстрація / токен |
| GET | `/v1/accounts` | Мої рахунки |
| POST | `/v1/accounts` | Відкрити рахунок у валюті |
| GET | `/v1/accounts/{id}/balance` | Баланс |
| GET | `/v1/accounts/{id}/entries` | Виписка, **cursor pagination** |
| POST | `/v1/transfers` | Переказ (Idempotency-Key) |
| POST | `/v1/deposits` | Поповнення (fake provider) |
| POST | `/v1/withdrawals` | Виведення |
| POST | `/v1/conversions` | Конвертація |
| POST | `/v1/transactions/{id}/reverse` | Сторнування (admin) |
| GET | `/v1/transactions/{id}` | Деталі |
| CRUD | `/v1/webhook-endpoints` | Керування вебхуками |
| GET | `/v1/admin/reconciliation/runs` | Звіти звірки (admin) |

Сума в API — рядок або integer у мінімальних одиницях + `currency`, **ніколи JSON float**.

---

## 9. Безпека

- Паролі: Argon2id/bcrypt, rate limit на логін, блокування після N спроб.
- Авторизація через Policies: користувач бачить/рухає тільки свої рахунки.
- Ролі: `user`, `admin`, `service`.
- Усі мутації — через Idempotency-Key.
- Rate limiting (Redis) per user/per IP/per endpoint.
- Секрети вебхуків — зашифровані в БД; ніколи в логах.
- PII/секрети маскуються в логах.
- Валідація на межі, суворі типи всередині.
- `composer audit` у CI, Dependabot.
- Мінімальні права БД-ролі застосунку (без UPDATE/DELETE на entries).

---

## 10. Тестова стратегія

| Рівень | Що | Інструмент |
|--------|----|-----------|
| Unit | `Money`, доменні правила, округлення | Pest/PHPUnit |
| Property-based | «для будь-якої послідовності операцій I1–I5 зберігаються» | генератори випадкових сценаріїв |
| Integration | Use-cases на реальному Postgres | Pest + транзакції/refresh |
| Feature/HTTP | Ендпоінти, авторизація, помилки | Laravel HTTP tests |
| **Concurrency** | N паралельних переказів, A↔B deadlock-сценарій, повтор з тим самим ключем | паралельні процеси (`pcntl`/`Symfony Process`/pool) |
| Contract | Відповіді відповідають OpenAPI | Spectator або аналог |
| Architecture | Domain не залежить від Laravel; модулі не лізуть в чужі таблиці | Pest arch / Deptrac |
| Mutation | Тестами покрито логіку Ledger/Money | Infection (MSI ≥ 80% для Ledger) |
| Load | 1000 RPS на переказах, hot account | k6 |

### Обовʼязкові concurrency-сценарії

1. 100 паралельних переказів з одного рахунку з балансом на 10 → рівно 10 успішних, баланс 0, інваріанти цілі.
2. A→B і B→A одночасно 500 разів → жодного deadlock-краху (retry працює), сума збережена.
3. Один Idempotency-Key, 20 паралельних запитів → одна транзакція, 19 відповідей-повторів/409.
4. Воркер падає між commit і публікацією → outbox дошле подію.
5. Після всіх сценаріїв reconciliation повертає 0 розбіжностей.

---

## 11. Observability та експлуатація

- Structured JSON logs з `trace_id`, `request_id`, `account_id`, `transaction_id`.
- Метрики: latency/RPS ендпоінтів, кількість deadlock-retry, розмір outbox, лаг публікації, failed deliveries, розбіжності reconciliation.
- Health endpoints: `/health/live`, `/health/ready` (БД, Redis).
- Graceful shutdown воркерів.
- Міграції: backward-compatible, без довгих локів (в README опиши zero-downtime стратегію).
- Бекапи/відновлення: опиши процедуру (навіть як документ).

---

## 12. Roadmap (виконувати строго по порядку)

Кожен крок має **Definition of Done (DoD)**. Не переходь далі, поки DoD не виконано і я не відповів на питання «співбесіди».

### Фаза 0 — Фундамент (3–4 дні)
- [x] 0.1 Репозиторій, структура, `PLAN.md`, `AGENTS.md`, `docs/PROGRESS.md`, `docs/adr/`.
- [x] 0.2 Docker Compose: php-fpm, nginx, postgres, redis, mailpit. `make up/down/test/lint`.
- [x] 0.3 Laravel skeleton, strict types, Pint, Larastan (max), архітектурні тести-заглушки.
- [x] 0.4 GitHub Actions: lint → static analysis → tests (на Postgres service).
- **DoD:** `make test` зелений локально й в CI; PHPStan max без baseline.

### Фаза 1 — Money і Ledger core (1 тиждень)
- [x] 1.1 `Money` + `Currency` value objects (integer, перевірки, арифметика, ділення з розподілом залишку). Unit + property tests.
- [x] 1.2 Міграції: accounts, account_balances, ledger_transactions, ledger_entries, тригер-імутабельність, CHECK-обмеження.
- [x] 1.3 Домен Ledger: `Posting`, `JournalEntry`, перевірка балансу проводок (I1).
- [x] 1.4 Сервіс постингу в БД (один репозиторій-порт `LedgerStore`), оновлення `account_balances`.
- [x] 1.5 Тест на спробу `UPDATE/DELETE` entries → падає.
- **DoD:** можна програмно провести проводку; I1–I3, I8 покриті тестами; mutation score по Money ≥ 80%.
- **Питання співбесіди:** чому не float; чому не одна колонка balance; як працює CHECK/тригер.

### Фаза 2 — Identity і рахунки (3–4 дні)
- [x] 2.1 Реєстрація/логін (Sanctum), ролі, policies.
- [x] 2.2 Відкриття рахунків, баланс, виписка з cursor pagination.
- [x] 2.3 Єдиний формат помилок (Problem Details), request-id middleware.
- **DoD:** користувач не бачить чужі рахунки (тести на авторизацію); OpenAPI для цих ендпоінтів.

### Фаза 3 — Transfer + блокування (1 тиждень) ← головна
- [x] 3.1 Use-case `TransferFunds`: транзакція, `FOR UPDATE` у порядку, перевірки домену.
- [x] 3.2 Retry на deadlock/serialization failure.
- [x] 3.3 Concurrency-тести №1 і №2 (розділ 10).
- [x] 3.4 ADR-005 про ізоляцію/блокування, з поясненням альтернатив (SERIALIZABLE, optimistic version, advisory locks).
- **DoD:** сценарії 1–2 стабільно зелені 50 запусків підряд; жодних втрачених/створених копійок.

### Фаза 4 — Idempotency (3–4 дні)
- [x] 4.1 Middleware/сервіс idempotency (розділ 5, крок 2 і 9).
- [x] 4.2 Тест №3, тест на різний body з тим самим ключем, TTL.
- **DoD:** повтор запиту повертає ту саму відповідь; гонка з одним ключем дає одну транзакцію.

### Фаза 5 — Deposit/Withdraw/Fee/Reversal (4–5 днів)
- [x] 5.1 Системні рахунки, fake payment provider (порт + адаптер).
- [x] 5.2 Deposit, withdrawal.
- [x] 5.3 Reversal з усіма заборонами (подвійне сторнування, сторнування сторнування).
- [x] 5.4 Комісії.
- **DoD:** I5 виконується після будь-якої комбінації операцій (property-based тест).

### Фаза 6 — Outbox + вебхуки (1 тиждень)
- [x] 6.1 Outbox-таблиця, запис у транзакції, relay із `SKIP LOCKED`.
- [x] 6.2 Webhook endpoints CRUD, підпис HMAC, SSRF-захист.
- [x] 6.3 Доставка з retries/backoff/dead-letter, replay.
- [ ] 6.4 Тест №4 (падіння між commit і публікацією).
- **DoD:** жодна подія не губиться; дублікати безпечні (event_id).

### Фаза 7 — Reconciliation (3–4 дні)
- [ ] 7.1 Перевірки з розділу 7, scheduler, звіти, метрика.
- [ ] 7.2 Chaos-тест із навмисним псуванням даних.
- **DoD:** після тестів №1–4 звірка чиста; після навмисного псування знаходить розбіжність.

### Фаза 8 — Конвертація валют (4–5 днів)
- [ ] 8.1 Курси (fake provider + кеш), правила округлення, ADR.
- [ ] 8.2 Use-case `ConvertCurrency` (4 проводки).
- **DoD:** I1 виконується по кожній валюті окремо; rounding dust відстежується.

### Фаза 9 — Продуктивність (1 тиждень)
- [ ] 9.1 k6-сценарії: розподілені перекази, hot account, змішане навантаження.
- [ ] 9.2 `EXPLAIN ANALYZE` ключових запитів, індекси, налаштування пулу зʼєднань.
- [ ] 9.3 Звіт `docs/performance.md` з цифрами до/після і висновками.
- **DoD:** задокументовані RPS/p95/p99; описано, де вузьке місце (hot account) і що з цим робити (sharding балансу, batching).

### Фаза 10 — Observability, безпека, фіналізація (1 тиждень)
- [ ] 10.1 Метрики, логи, health endpoints, (опц.) Grafana dashboard.
- [ ] 10.2 Rate limiting, аудит-лог, `composer audit`, перевірка секретів у логах.
- [ ] 10.3 OpenAPI повний + контрактні тести.
- [ ] 10.4 Mutation testing на Ledger/Wallet.
- [ ] 10.5 Deploy (Fly.io/Railway/VPS + Docker), демо-середовище.
- **DoD:** публічний демо-URL, CI зелений, README готовий.

### Бонус (після MVP)
Антифрод-правила (velocity limits), multi-currency виписки, експорт CSV, admin-дашборд, multi-tenant, partitioning таблиці entries за часом, read-replica для виписок.

---

## 13. Структура репозиторію

```
.
├── AGENTS.md
├── PLAN.md
├── README.md
├── Makefile
├── docker/
├── docs/
│   ├── PROGRESS.md
│   ├── adr/
│   ├── architecture.md        # діаграми (Mermaid)
│   ├── performance.md
│   └── api/openapi.yaml
├── src/ (або app/Modules)
├── database/migrations/
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── Feature/
│   ├── Concurrency/
│   └── Architecture/
├── load/                      # k6 сценарії
└── .github/workflows/
```

---

## 14. README — що має бути

1. Що це і яку проблему вирішує (3 речення).
2. Демо: GIF/відео load-тесту та успішної звірки.
3. Архітектурна діаграма (Mermaid) і діаграма потоку переказу.
4. **«Ключові рішення»**: integer money, double-entry, immutable ledger, порядок блокувань, idempotency, outbox, reconciliation — по абзацу з посиланням на ADR.
5. **«Що буде, якщо…»**: воркер упав посередині; клієнт повторив запит; два перекази A↔B одночасно; база перезапустилась.
6. Як запустити за 3 команди.
7. Як запустити тести й load test.
8. Відомі обмеження та що б я робив далі.

---

## 15. Критерії готовності проєкту

- [ ] Всі інваріанти I1–I8 покриті тестами.
- [ ] Concurrency-сценарії 1–5 стабільно зелені.
- [ ] PHPStan max, Pint, Infection (Ledger MSI ≥ 80%).
- [ ] CI зелений, є демо-URL.
- [ ] Є ≥ 9 ADR.
- [ ] `docs/performance.md` з реальними цифрами.
- [ ] README дозволяє запустити проєкт чужій людині за 5 хвилин.
- [ ] Я можу без підказок пояснити кожне рішення з розділів 3–7.

---

## 16. Перше повідомлення для Codex (скопіюй у новий чат)

```
Прочитай PLAN.md повністю. Працюй за протоколом з розділу 0.
Режим: learn.
Почни з Фази 0, крок 0.1. Спочатку перевір актуальні версії PHP/Laravel/PostgreSQL
у документації й запропонуй конкретні версії, потім веди мене крок за кроком.
Після кожного кроку став мені 2 запитання як на співбесіді.
```
