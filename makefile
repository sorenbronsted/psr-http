
name = psr-http
image:
	docker build -t $(name) .

bash:
	docker run -it $(name) bash

clean:
	docker rm $(shell docker ps -aq)

composer:
	docker run --rm --interactive --tty --volume ${PWD}:/app composer ${CMD}
