# Прогрес

## Поточний стан
- Фаза 0, крок 0.1 — завершено.
- Фаза 0, крок 0.2 — завершено.
- Фаза 0, крок 0.3 — завершено.
- Фаза 0, крок 0.4 — завершено; Фаза 0 повністю завершена.
- Фаза 1, крок 1.1 — завершено.
- Наступний крок: 1.2 — PostgreSQL migrations для accounts і ledger.
- Створено окремий Git-репозиторій і базову структуру документації.
- `PLAN.md` містить майстер-план.
- `AGENTS.md` містить правила роботи та опис продукту з розділів 0 і 1 плану.
- Створено `docs/adr/` для архітектурних рішень.
- Режим роботи за замовчуванням змінено на `pair` за побажанням користувача; зміни внесено в `PLAN.md` та `AGENTS.md`.
- Додано `compose.yaml`, Nginx-конфігурацію і цілі `make up/down/test/lint`.
- Запущено PHP-FPM, Nginx, PostgreSQL, Redis і Mailpit; PostgreSQL та Redis здорові, перевірка конфігурації Nginx успішна.
- Docker Desktop 4.99.0 запущено; Docker Engine 29.9.3 і Docker Compose v5.7.0.
- Homebrew оновлення зупинилося на видаленні старих Docker helper-служб, що потребує пароля адміністратора. Запущено офіційний образ Docker Desktop 4.99.0 з перевіреного Homebrew-кешу; старий запис Homebrew про версію 4.47.0 залишився.
- Через зайняті локальні порти PostgreSQL і Redis доступні застосунку через внутрішню Compose-мережу; HTTP відкрито на `127.0.0.1:8080`, Mailpit — на `127.0.0.1:8025`.
- Навчальний протокол доповнено схемами залежностей, прикладами «погано/добре» та одним практичним питанням після кожної логічної порції.
- Навчання переведено на просту англійську рівня B1 з підготовкою відповідей для реальної backend-співбесіди та короткою корекцією англійської.
- Додано власний PHP image: PHP 8.4.27, Composer 2.11.2 і розширення `bcmath`, `intl`, `pcntl`, `pdo_pgsql`, `redis`, `zip`.
- Встановлено Laravel application skeleton v13.11.0 з Laravel Framework 13.42.0; застосунок відповідає HTTP 200 через Nginx.
- Згенеровані skeleton-інструкції для агентів не прийнято: `AGENTS.md` відновлено з `PLAN.md`, зайвий `CLAUDE.md` видалено.
- `.env.example` і локальний `.env` налаштовано на Compose-сервіси: PostgreSQL, Redis і Mailpit.
- Перевірено реальні з'єднання Laravel: PostgreSQL повернув базу `wallet`, Redis відповів на `PING`, HTTP залишився 200.
- Додано `declare(strict_types=1);` до всіх PHP-файлів застосунку, конфігурації, міграцій і тестів; Blade template виключено.
- Laravel Pint успішно перевірив 26 PHP-файлів.
- Встановлено Larastan 3.14.0 з PHPStan 2.3.5; аналіз налаштовано на `level: max` без baseline.
- Додано ціль `make analyse` для статичного аналізу application-коду, конфігурації, міграцій, routes і tests.
- Виправлено дві початкові знахідки PHPStan: тип значення `APP_URL` у filesystem config і беззмістовний skeleton unit test.
- Замість `assertTrue(true)` додано unit test, який перевіряє приховування password під час серіалізації `User`.
- Додано architecture test, який забороняє залежності Domain layer від `Illuminate`, `Laravel` і `Symfony`; suite зареєстровано у `phpunit.xml`.
- Перевірки успішні: Pint — 27 файлів, PHPStan — 0 помилок, PHPUnit — 3 тести та 4 assertions.
- Створено приватний GitHub-репозиторій `valeriibb/wallet-ledger-api` і запушено гілку `main`.
- Додано GitHub Actions pipeline на Ubuntu 24.04: Composer install → Pint → PHPStan → PostgreSQL migrations → PHPUnit.
- Перший GitHub Actions run успішно пройшов усі кроки на PostgreSQL 18.6 за 33 секунди.
- Реалізовано immutable `Currency` і `Money` value objects без Laravel-залежностей: валідація коду, integer minor units, перевірка валюти, арифметика, overflow protection і розподіл залишку.
- Додано property test для allocation: 10 020 комбінацій перевіряють збереження суми, кількості частин, рівномірність і валюту.
- Перевірки Money/Currency пройшли: PHPUnit — 25 тестів, 145 331 assertions; PHPStan max — 0 помилок; Pint — 33 файли.

## Версії
- PHP 8.4
- Laravel 13.x
- PostgreSQL 18.x

## Відкладено
- Код застосунку та решта кроків roadmap ще не починалися.
- Docker Desktop на цьому Mac: 4.99.0; Docker Engine: 29.9.3; Docker Compose: v5.7.0.

## Відкриті питання
- Немає.
