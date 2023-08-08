
name = psr-http
image:
	docker build -t $(name) .

bash:
	docker run -it -u 1000:1000 $(name) bash

clean:
	docker rm $(shell docker ps -aq)

composer:
	docker run -it -u 1000:1000 --volume ${PWD}:/app composer ${CMD}
