# Siege stress client. Build with:
#
#   docker build -f siege.dockerfile -t psr-http-siege .
#   docker run --rm psr-http-siege -b -c 10 -t 10S http://host:8000/
#
# Everything after the image name is passed to siege.
FROM debian:bookworm-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends siege \
 && rm -rf /var/lib/apt/lists/*

RUN useradd --create-home --uid 1000 siege
USER siege
WORKDIR /home/siege

# Create the default configuration at build time, so the first run does not
# print a "New configuration template added" notice.
RUN siege -C >/dev/null 2>&1

ENTRYPOINT ["siege"]
