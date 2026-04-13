FROM ipfwd/php-extended:8.3-rr-astra

USER root

WORKDIR /app

ENTRYPOINT []

# Install cron, then clean apt cache to reduce image size
RUN apt-get update \
    && apt-get install -y --no-install-recommends cron \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Create unprivileged app user with fixed UID/GID for tmpfs mapping
RUN groupadd -r -g 1500 appuser && useradd -r -u 1500 -g appuser -d /app -s /usr/sbin/nologin appuser

# Setup cron job via /etc/cron.d/ (supports user field; do NOT use crontab command)
COPY docker/crontab /etc/cron.d/refurbed-cron
RUN chmod 0644 /etc/cron.d/refurbed-cron

# Copy application
COPY src/ /app/

# Create invoices directory owned by appuser
RUN mkdir -p /app/invoices \
    && chown -R appuser:appuser /app \
    && chmod -R 0750 /app

# Entrypoint: write env vars to a file only readable by appuser, then start cron
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod 0755 /entrypoint.sh

CMD ["/entrypoint.sh"]
