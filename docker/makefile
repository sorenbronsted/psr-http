
up:
	docker-compose up -d

down:
	docker-compose down

logs:
	docker-compose logs -f

bash:
	docker-compose exec httpserver bash

image:
	docker-compose build

depend:
	docker-compose exec composer composer install --ignore-platform-reqs

test:
	docker-compose exec httpserver vendor/bin/phpunit

