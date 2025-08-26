#!/bin/sh
# Entrypoint for Alpine Linux (POSIX sh)

cd /var/www/html

file_env() {
    var="$1"
    file_var="${var}_FILE"
    default="$2"

    if [ ! -z "$(eval echo \$$var)" ] && [ ! -z "$(eval echo \$$file_var)" ]; then
        echo "$var and $file_var are exclusive" >&2
        exit 1
    fi

    val="$default"
    if [ ! -z "$(eval echo \$$var)" ]; then
        val="$(eval echo \$$var)"
    elif [ ! -z "$(eval echo \$$file_var)" ]; then
        val="$(cat "$(eval echo \$$file_var)")"
    fi

    export "$var=$val"
    unset "$file_var"
}

# Variáveis padrão
LS_DB_TYPE=${LS_DB_TYPE:-pgsql}
LS_DB_HOST=${LS_DB_HOST:-pgsql}
LS_DB_PORT=${LS_DB_PORT:-5432}
LS_DB_NAME=${LS_DB_NAME:-limesurvey}
LS_DB_TABLE_PREFIX=${LS_DB_TABLE_PREFIX:-lime_}
LS_DB_USERNAME=${LS_DB_USERNAME:-limesurvey}
file_env LS_DB_PASSWORD

LS_ADMIN_USER=${LS_ADMIN_USER:-admin}
LS_ADMIN_NAME=${LS_ADMIN_NAME:-Admin}
LS_ADMIN_EMAIL=${LS_ADMIN_EMAIL:-admin@admin.com}
file_env LS_ADMIN_PASSWORD

LS_DB_CHARSET=${LS_DB_CHARSET:-utf8mb4}
LS_DB_MYSQL_ENGINE=${LS_DB_MYSQL_ENGINE:-InnoDB}
LS_DEFAULT_LANGUAGE=${LS_DEFAULT_LANGUAGE:-pt-BR}
LS_DEBUG=${LS_DEBUG:-0}
LS_DEBUGSQL=${LS_DEBUGSQL:-0}
LS_SESSION_NAME=${LS_SESSION_NAME:-LS-LRRCYVEOLCUJVOLI}
LS_URL_FORMAT=${LS_URL_FORMAT:-path}
LS_SHOW_SCRIPT_NAME=${LS_SHOW_SCRIPT_NAME:-true}
LS_BASE_URL=${LS_BASE_URL:-'/limesurvey'}
LS_SESSION_DOMAIN=${LS_SESSION_DOMAIN:-.localhost}

# Gera config.php se não existir
if [ ! -f application/config/config.php ]; then
    echo "Info: Generating config.php"

    sed "s|{{DB_TYPE}}|$LS_DB_TYPE|g; \
         s|{{DB_HOST}}|$LS_DB_HOST|g; \
         s|{{DB_PORT}}|$LS_DB_PORT|g; \
         s|{{DB_NAME}}|$LS_DB_NAME|g; \
         s|{{DB_USER}}|$LS_DB_USERNAME|g; \
         s|{{DB_PASSWORD}}|$LS_DB_PASSWORD|g; \
         s|{{DB_TABLE_PREFIX}}|$LS_DB_TABLE_PREFIX|g; \
         s|{{DB_CHARSET}}|$LS_DB_CHARSET|g
         s|{{DB_MYSQL_ENGINE}}|$LS_DB_MYSQL_ENGINE|g; \
         s|{{URL_FORMAT}}|$LS_URL_FORMAT|g; \
         s|{{SHOW_SCRIPT_NAME}}|$LS_SHOW_SCRIPT_NAME|g; \
         s|{{BASE_URL}}|$LS_BASE_URL|g; \
         s|{{DEBUG}}|$LS_DEBUG|g; \
         s|{{DEBUGSQL}}|$LS_DEBUGSQL|g; \
         s|{{PUBLIC_URL}}|$LS_PUBLIC_URL|g; \
         s|{{SESSION_NAME}}|$LS_SESSION_NAME|g; \
         s|{{DEFAULT_LANGUAGE}}|$LS_DEFAULT_LANGUAGE|g; \
         s|{{SESSION_DOMAIN}}|$LS_SESSION_DOMAIN|g;" \
         application/config/config.php.template > application/config/config.php
fi

# Check if database is available
if [ -z "$LS_DB_SOCK" ]; then
    until nc -z -v -w30 "$LS_DB_HOST" "$LS_DB_PORT"
    do
        echo "Info: Waiting for database connection..."
        sleep 5
    done
    sleep 2
    echo "Info: Database is available"
fi

# Atualiza DB
php application/commands/console.php updatedb

# Cria admin se necessário
php application/commands/console.php user:exists "$LS_ADMIN_USER" >/dev/null 2>&1
if [ $? -ne 0 ]; then
    php application/commands/console.php install "$LS_ADMIN_USER" "$LS_ADMIN_PASSWORD" "$LS_ADMIN_NAME" "$LS_ADMIN_EMAIL"
fi

# Install uploaded plugins
if [ -d upload/plugins ]; then
    for plugin in upload/plugins/*; do
        if [ -d "$plugin" ]; then
            echo "Info: Installing plugin $(basename "$plugin")"
            echo "Running installuploadedplugin for $(basename "$plugin")"
            php application/commands/console.php installuploadedplugin --pluginName="$(basename "$plugin")"
        fi
    done
fi

exec "$@"
