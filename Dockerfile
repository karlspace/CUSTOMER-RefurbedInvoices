FROM ipfwd/php-extended:8.3-rr-astra

USER root

WORKDIR /app

ENTRYPOINT []

RUN apt-get update && apt-get install -y cron
COPY crontab /etc/cron.d/refurbed-cron
RUN chmod 0644 /etc/cron.d/refurbed-cron
RUN crontab /etc/cron.d/refurbed-cron

COPY refurbed-invoices.php /app/
RUN chmod +x /app/refurbed-invoices.php

CMD ["sh", "-c", "printenv > /etc/environment && cron -f"]