FROM ipfwd/php-extended:8.3-rr-astra

USER root

WORKDIR /app

# tini provides proper PID 1 semantics: forwards SIGTERM/SIGINT to PHP and reaps
# zombies. Without it, the container takes the full 10s SIGKILL timeout to stop
# and any subprocess crashes leak file descriptors.
RUN apt-get update \
    && apt-get install -y --no-install-recommends tini \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Unprivileged app user with fixed UID/GID for tmpfs mapping
RUN groupadd -r -g 1500 appuser && useradd -r -u 1500 -g appuser -d /app -s /usr/sbin/nologin appuser

# Application code
COPY src/ /app/

# Writable runtime dirs owned by appuser
RUN mkdir -p /app/invoices \
    && chown -R appuser:appuser /app \
    && chmod -R 0750 /app

USER appuser

# tini -g forwards signals to the entire process group so curl/imap children die
# with the parent. Logs go straight to stdout/stderr → docker logs.
ENTRYPOINT ["/usr/bin/tini", "-g", "--"]
CMD ["php", "/app/refurbed-invoices.php"]
