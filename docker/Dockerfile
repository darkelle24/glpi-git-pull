# Build an application image containing Git, not just a separate git sidecar.
FROM glpi/glpi:latest
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends git bash ca-certificates \
    && rm -rf /var/lib/apt/lists/*
USER www-data
