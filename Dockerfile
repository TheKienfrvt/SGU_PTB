FROM webdevops/php-apache:8.4

# Adjust LimitRequestLine and
# update and install dependencies
RUN echo "LimitRequestLine 12000" > /opt/docker/etc/httpd/conf.d/limits.conf \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential \
        git \
        gphoto2 \
        libimage-exiftool-perl \
        rsync \
        udisks2 \
        python3 \
        ca-certificates \
        curl \
        gnupg \
        nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy files
WORKDIR /app
COPY . .
COPY docker/photobooth-security.conf /opt/docker/etc/httpd/conf.d/photobooth-security.conf

RUN mkdir -p /app/data /app/config /app/private /app/var \
    && chown -R application:application /app

# Named volumes can be created as root-owned empty directories. Repair only the
# volume roots at every boot before PHP-FPM drops privileges.
COPY docker/06-photobooth-volume-permissions.sh /opt/docker/provision/entrypoint.d/06-photobooth-volume-permissions.sh
COPY docker/kiosk.config.inc.php /opt/photobooth-defaults/my.config.inc.php
RUN chmod 0755 /opt/docker/provision/entrypoint.d/06-photobooth-volume-permissions.sh \
    && chmod 0644 /opt/photobooth-defaults/my.config.inc.php \
    && rm -f /app/docker/kiosk.config.inc.php

# PHP cookies and SGU capture sessions live outside the public web root.
# Docker seeds this ownership into the named session volume on first creation.
RUN mkdir -p /sessions && chown application:application /sessions && chmod 700 /sessions

# Use application for build operations only.
USER application

# Prefetch this legacy archive: npm's concurrent streaming extraction can stall on it.
# npm ci still verifies the content against package-lock.json's SHA-512 integrity.
RUN --mount=type=cache,target=/home/application/.npm,uid=1000,gid=1000 \
    curl --fail --location --retry 2 --max-time 60 \
        https://registry.npmjs.org/marvinj/-/marvinj-1.0.0.tgz -o /tmp/marvinj-1.0.0.tgz \
    && npm cache add /tmp/marvinj-1.0.0.tgz \
    && timeout 240 npm ci --prefer-offline --no-audit --no-fund --fetch-timeout=60000 --fetch-retries=2

RUN npm run build

# The webdevops/php-apache runtime entrypoint must start as root.
# Apache/PHP-FPM drop worker privileges internally.
USER root
