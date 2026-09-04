#!/bin/sh
set -e

# Comando avulso (docker compose run --rm app php artisan ...): executa e sai,
# sem migrar, semear nem subir os servicos. E o unico jeito de rodar
# key:generate numa instalacao nova, ja que a validacao de APP_KEY abaixo
# impediria o container de subir sem a chave que se quer justamente gerar.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

: "${PORT:=10000}"
export PORT

# O Render define a porta so em runtime; injeta no template do nginx.
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

cd /var/www/html

# Falha cedo e com mensagem legivel: sem isto uma APP_KEY malformada so
# aparece como 500 generico em cada request, com "Unsupported cipher or
# incorrect key length" enterrado no log. O caso comum e colar a chave sem
# o prefixo base64:, que faz o Laravel usar os 44 caracteres literais como
# chave em vez dos 32 bytes decodificados.
php -r '
$k = getenv("APP_KEY") ?: "";
if ($k === "") {
    fwrite(STDERR, "ERRO: APP_KEY nao definida.
");
    exit(1);
}
$raw = str_starts_with($k, "base64:") ? base64_decode(substr($k, 7), true) : $k;
if ($raw === false || strlen($raw) !== 32) {
    fwrite(STDERR, sprintf(
        "ERRO: APP_KEY invalida - %d bytes, esperado 32.
%s
",
        $raw === false ? 0 : strlen($raw),
        str_starts_with($k, "base64:")
            ? "O prefixo base64: esta presente, entao a chave em si esta truncada. Gere outra com: php artisan key:generate --show"
            : "Faltou o prefixo base64: no valor da variavel - cole a chave inteira, incluindo ele."
    ));
    exit(1);
}
'

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

# Feriados, tipos de prazo e templates de checklist: sem esses dados de
# referencia o calculo de prazo nao fecha. Os seeders usam updateOrCreate,
# entao rodar a cada deploy e idempotente.
if [ "${RUN_SEED:-true}" = "true" ]; then
    echo "==> Semeando dados de referencia"
    php artisan db:seed --force --no-interaction
fi

echo "==> Subindo nginx + php-fpm + fila + scheduler na porta ${PORT}"
exec supervisord -c /etc/supervisord.conf
