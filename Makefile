.PHONY: up down test lint analyse mutate

up:
	docker compose up -d

down:
	docker compose down

test:
	docker compose exec php php artisan test

lint:
	docker compose exec php ./vendor/bin/pint --test

analyse:
	docker compose exec php ./vendor/bin/phpstan analyse --memory-limit=1G

mutate:
	docker compose exec php ./vendor/bin/infection --min-msi=80 --threads=1
