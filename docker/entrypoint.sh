#!/bin/sh
set -e

: "${PORT:=10000}"
export PORT

# O Render define a porta so em runtime; injeta no template do nginx.
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

cd /var/www/html

# Cache de config precisa ser gerado agora, nao no build: as variaveis de
# ambiente (banco, chaves, R2) so existem em runtime.
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Migrations vao pelo endpoint direto do Neon: o pooled (PgBouncer) engole o
# erro do DDL transacional e a migration falha com "transaction is aborted".
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "==> Rodando migrations (conexao ${MIGRATION_CONNECTION:-pgsql_unpooled})"
    php artisan migrate --force --no-interaction         --database="${MIGRATION_CONNECTION:-pgsql_unpooled}"
fi

echo "==> Subindo nginx + php-fpm + fila + scheduler na porta ${PORT}"
exec supervisord -c /etc/supervisord.conf
