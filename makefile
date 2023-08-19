
name = psr-http
image:
	docker build -t $(name) .

bash:
	docker run -it -u 1000:1000 -v ${PWD}:/app $(name) bash
