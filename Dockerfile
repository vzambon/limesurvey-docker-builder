# ------------------------
# BASE IMAGE
# ------------------------
FROM php:8.3.23-fpm-alpine AS base

ARG USER_ID
ARG GROUP_ID

# Variáveis
ENV SUPERCRONIC_URL=https://www.github.com/aptible/supercronic/releases/download/v0.2.34/supercronic-linux-amd64 \
    SUPERCRONIC_SHA1SUM=e8631edc1775000d119b70fd40339a7238eece14 \
    SUPERCRONIC=supercronic-linux-amd64

# Instala pacotes de runtime
RUN apk add --no-cache \
    curl \
    libjpeg-turbo-dev \
    libpng-dev \
    freetype-dev \
    gosu \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev \
    libxml2-dev \
    libpq \
    tzdata \
    unzip \
    shadow \
    supervisor \
    linux-headers \
    zlib-dev \
    openldap-dev \
    imap-dev


# Build das extensões PHP
RUN apk add --no-cache --virtual .build-deps \
    autoconf \
    g++ \
    make \
    libtool \
    && docker-php-ext-configure gd \
        --with-freetype=/usr/include/ \
        --with-jpeg=/usr/include/ \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        gd \
        intl \
        mbstring \
        pdo_pgsql \
        pgsql \
        xml \
        zip \
        mysqli \
        opcache \
        pcntl \
        ldap \
        imap \
    && apk del .build-deps

# Instala Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instala Supercronic
RUN curl -fsSLO "$SUPERCRONIC_URL" \
 && echo "${SUPERCRONIC_SHA1SUM}  ${SUPERCRONIC}" | sha1sum -c - \
 && chmod +x "$SUPERCRONIC" \
 && mv "$SUPERCRONIC" "/usr/local/bin/${SUPERCRONIC}" \
 && ln -s "/usr/local/bin/${SUPERCRONIC}" /usr/local/bin/supercronic

# Cron File
COPY limesurvey-cron /etc/cron.d/limesurvey-cron
RUN chmod 0644 /etc/cron.d/limesurvey-cron

# Diretório de logs
RUN mkdir -p /var/log/app

# Entrypoint
COPY ./entrypoints/ /usr/local/bin/
RUN chmod +x /usr/local/bin/*.sh

# Supervisor
COPY supervisor.conf /etc/supervisor.conf

# Configuração OpCache
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Usuário LimeSurvey
RUN groupadd -g ${GROUP_ID} limesurvey \
    && useradd -u ${USER_ID} -g limesurvey -s /bin/sh -m limesurvey
    
COPY ./LimeSurvey /var/www/html
COPY ./templates/config.php.template /var/www/html/application/config/config.php.template
COPY ./commands/ /tmp/commands/
RUN cp -rn /tmp/commands/* /var/www/html/application/commands/ \
&& rm -rf /tmp/commands

RUN mkdir -p /var/log/app \
    && chown -R limesurvey:limesurvey /var/log/app \
    && chmod 755 /var/log/app
RUN chown -R limesurvey:limesurvey /var/log/app /var/www/html

WORKDIR /var/www/html
EXPOSE 9000
USER limesurvey

ENTRYPOINT ["/usr/local/bin/setup-entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor.conf"]
