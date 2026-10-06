#!/bin/sh
set -eu

install -d -o application -g application -m 0755 \
    /app/data \
    /app/config \
    /app/private \
    /app/var
install -d -o application -g application -m 0700 /sessions

if [ ! -e /app/config/my.config.inc.php ]; then
    install -o application -g application -m 0640 \
        /opt/photobooth-defaults/my.config.inc.php \
        /app/config/my.config.inc.php
fi
