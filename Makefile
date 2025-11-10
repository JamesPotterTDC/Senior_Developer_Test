up:
	docker compose up -d

down:
	docker compose down -v

db-up:
	docker compose up -d pgsql

migrate:
	php artisan migrate --env=testing --force

wait-db:
	php bin/wait-for-pgsql.php

test:
	make db-up && make wait-db && php artisan migrate --env=testing --force && php artisan test

ci:
	composer lint && make test
