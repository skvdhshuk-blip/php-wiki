.PHONY: up down logs test doctor doctor-live shell

up:
	docker compose up --build -d

down:
	docker compose down

logs:
	docker compose logs -f app queue scheduler reverb

test:
	docker compose --profile test build test
	docker compose --profile test run --rm --no-deps test

doctor:
	docker compose exec app php artisan php-wiki:doctor

doctor-live:
	docker compose exec app php artisan php-wiki:doctor --live

shell:
	docker compose exec app sh
