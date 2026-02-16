FROM node:20-alpine AS ui-builder
WORKDIR /ui
COPY ui/package*.json ./
RUN npm install
COPY ui/ ./
RUN npm run build

FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
      cups \
      cups-client \
      cups-bsd \
      cups-filters \
      printer-driver-all \
      nginx \
      sudo \
      supervisor \
      python3 \
      python3-reportlab \
      postgresql \
      postgresql-contrib \
      libpq-dev \
      postgis \
      postgresql-15-postgis-3 \
      postgresql-15-postgis-3-scripts \
      ca-certificates \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN sed -ri "s|^;?clear_env\s*=.*|clear_env = no|" /usr/local/etc/php-fpm.d/www.conf

RUN usermod -aG lpadmin www-data \
    && printf 'www-data ALL=(ALL) NOPASSWD: /usr/bin/lp, /usr/bin/lpstat, /usr/sbin/lpadmin, /usr/sbin/cupsenable, /usr/sbin/accept, /usr/bin/cancel, /usr/bin/lpoptions\n' > /etc/sudoers.d/printer-hub \
    && chmod 0440 /etc/sudoers.d/printer-hub

RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default
COPY config/nginx.conf /etc/nginx/conf.d/default.conf

COPY config/cupsd.conf /etc/cups/cupsd.conf
COPY config/cupsd.conf /usr/local/share/printer-hub/cupsd.conf
COPY config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY config/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY config/init-postgres.sh /usr/local/bin/init-postgres.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/init-postgres.sh

COPY app/ /var/www/app/
COPY --from=ui-builder /ui/dist /var/www/ui

RUN mkdir -p /var/lib/printer-hub/jobs /var/log/printer-hub /run/cups /var/run/postgresql \
    && chown -R www-data:www-data /var/lib/printer-hub /var/log/printer-hub /var/www/app /var/www/ui \
    && chown -R postgres:postgres /var/lib/postgresql /var/run/postgresql

EXPOSE 80 631

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
