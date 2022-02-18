FROM ubuntu:22.04
ENV DEBIAN_FRONTEND noninteractive
RUN apt-get update && \
    apt-get -y upgrade && \
    apt-get -y install php-cli php-xml php-xdebug iputils-ping dnsutils composer siege curl
RUN groupadd sobr && useradd -g sobr -d /home/sobr sobr
USER sobr
WORKDIR /var/www/html
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
