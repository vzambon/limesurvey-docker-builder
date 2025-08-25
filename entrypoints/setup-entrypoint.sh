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
DB_TYPE=${DB_TYPE:-pgsql}
DB_HOST=${DB_HOST:-pgsql}
DB_PORT=${DB_PORT:-5432}
DB_NAME=${DB_NAME:-limesurvey}
DB_TABLE_PREFIX=${DB_TABLE_PREFIX:-lime_}
DB_USERNAME=${DB_USERNAME:-limesurvey}
file_env DB_PASSWORD

ADMIN_USER=${ADMIN_USER:-admin}
ADMIN_NAME=${ADMIN_NAME:-Admin}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@admin.com}
file_env ADMIN_PASSWORD

DB_CHARSET=${DB_CHARSET:-utf8mb4}
DB_MYSQL_ENGINE=${DB_MYSQL_ENGINE:-InnoDB}
DEFAULT_LANGUAGE=${DEFAULT_LANGUAGE:-pt-BR}
DEBUG=${DEBUG:-0}
DEBUGSQL=${DEBUGSQL:-0}
SESSION_NAME=${SESSION_NAME:-LS-LRRCYVEOLCUJVOLI}
URL_FORMAT=${URL_FORMAT:-path}
SHOW_SCRIPT_NAME=${SHOW_SCRIPT_NAME:-true}
BASE_URL=${BASE_URL:-}
PUBLIC_URL=${PUBLIC_URL:-http://localhost/}

# Gera config.php se não existir
if [ ! -f application/config/config.php ]; then
    echo "Info: Generating config.php"

    sed "s|{{DB_TYPE}}|$DB_TYPE|g; \
         s|{{DB_HOST}}|$DB_HOST|g; \
         s|{{DB_PORT}}|$DB_PORT|g; \
         s|{{DB_NAME}}|$DB_NAME|g; \
         s|{{DB_USER}}|$DB_USERNAME|g; \
         s|{{DB_PASSWORD}}|$DB_PASSWORD|g; \
         s|{{DB_TABLE_PREFIX}}|$DB_TABLE_PREFIX|g; \
         s|{{DB_CHARSET}}|$DB_CHARSET|g
         s|{{DB_MYSQL_ENGINE}}|$DB_MYSQL_ENGINE|g; \
         s|{{URL_FORMAT}}|$URL_FORMAT|g; \
         s|{{SHOW_SCRIPT_NAME}}|$SHOW_SCRIPT_NAME|g; \
         s|{{BASE_URL}}|$BASE_URL|g; \
         s|{{DEBUG}}|$DEBUG|g; \
         s|{{DEBUGSQL}}|$DEBUGSQL|g; \
         s|{{PUBLIC_URL}}|$PUBLIC_URL|g; \
         s|{{SESSION_NAME}}|$SESSION_NAME|g; \
         s|{{DEFAULT_LANGUAGE}}|$DEFAULT_LANGUAGE|g;" \
         application/config/config.php.template > application/config/config.php
fi

# Check if database is available
if [ -z "$DB_SOCK" ]; then
    until nc -z -v -w30 "$DB_HOST" "$DB_PORT"
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
php application/commands/console.php user:exists "$ADMIN_USER" >/dev/null 2>&1
if [ $? -ne 0 ]; then
    php application/commands/console.php install "$ADMIN_USER" "$ADMIN_PASSWORD" "$ADMIN_NAME" "$ADMIN_EMAIL"
fi

# Install default plugins
if [ -d protiviti-defaults/plugins ]; then
    for plugin in protiviti-defaults/plugins/*; do
        if [ -d "$plugin" ]; then
            echo "Info: Installing plugin $(basename "$plugin")"
            echo "Running installuploadedplugin for $(basename "$plugin")"
            php application/commands/console.php installuploadedplugin --pluginName="$(basename "$plugin")"
        fi
    done
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
