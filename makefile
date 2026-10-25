
name = psr-http
uid = $(shell id -u)
gid = $(shell id -g)
run = docker run --rm -u $(uid):$(gid) -e COMPOSER_HOME=/tmp/composer -v $(CURDIR):/app

PHP_VERSION ?= 8.5
CONCURRENCY ?= 250
DURATION ?= 60S
SIEGE_ARGS ?=
server_image = psr-http-run
siege_image = psr-http-siege

.PHONY: image bash install test coverage server-image siege-image stress

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

server-image:
	docker build -f run.dockerfile --build-arg PHP_VERSION=$(PHP_VERSION) -t $(server_image) .

siege-image:
	docker build -f siege.dockerfile -t $(siege_image) .

stress: server-image siege-image
	CONCURRENCY=$(CONCURRENCY) DURATION=$(DURATION) SERVER_IMAGE=$(server_image) SIEGE_IMAGE=$(siege_image) ./stress.sh $(SIEGE_ARGS)
