
name = psr-http
uid = $(shell id -u)
gid = $(shell id -g)
run = docker run --rm -u $(uid):$(gid) -e COMPOSER_HOME=/tmp/composer -v $(CURDIR):/app

.PHONY: image bash install test coverage

image:
	docker build -t $(name) .

bash:
	docker run -it -u $(uid):$(gid) -v ${PWD}:/app $(name) bash

install: image
	$(run) $(name) composer install --no-interaction

test: install
	$(run) $(name) composer test

coverage: install
	$(run) $(name) composer run coverage
