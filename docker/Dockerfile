FROM ubuntu:22.04
ENV DEBIAN_FRONTEND noninteractive
RUN apt update && \
    apt -y upgrade && \
    apt -y install php-cli php-xdebug iputils-ping dnsutils composer siege
RUN groupadd sobr && useradd -g sobr sobr
USER sobr
WORKDIR /var/www/html
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
