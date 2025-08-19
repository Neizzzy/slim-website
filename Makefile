PORT ?= 8000

start:
	php -S 0.0.0.0:$(PORT) -t public public/index.php

start-local:
	php -S localhost:8080 -t public public/index.php

setup:
	composer install

compose:
	docker-compose up

compose-bash:
	docker-compose run web bash

compose-setup: compose-build
	docker-compose run web make setup

compose-build:
	docker-compose build

compose-down:
	docker-compose down -v
