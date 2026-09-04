# Rodar o Mithrandir em servidor proprio

Guia para quem vai hospedar a aplicacao numa maquina propria, com dominio.

Tudo roda em containers: aplicacao, banco e exposicao para a internet. Nao ha
nada para instalar na maquina alem do Docker.

## Por que servidor no Brasil importa

A aplicacao consulta o **DJEN** (Diario de Justica Eletronico Nacional), que e o
gatilho formal de contagem de prazo. A API do CNJ **responde 403 para IP
estrangeiro** - medimos isso: a mesma requisicao devolve `200` de uma conexao
brasileira e `403` de um servidor nos EUA.

Por isso a hospedagem precisa estar no Brasil. Uma conexao residencial brasileira
atende esse requisito.

---

## O que voce precisa

- Docker e Docker Compose (`curl -fsSL https://get.docker.com | sudo sh`)
- Um dominio (ou subdominio) que voce controle
- ~2 GB de RAM livres para o build e ~10 GB de disco

---

## PASSO 1 - Clonar e configurar

```bash
git clone https://github.com/vashxd/mithrandir.git
cd mithrandir
cp .env.servidor.exemplo .env.servidor
nano .env.servidor
```

Preencha o dominio e **troque a senha do banco nos dois lugares** - `POSTGRES_PASSWORD`
e `DB_PASSWORD` precisam ser identicas: a primeira cria o usuario no Postgres, a
segunda e como a aplicacao se conecta.

---

## PASSO 2 - Gerar as chaves

A imagem precisa existir antes. Construa e gere:

```bash
docker compose -f docker-compose.servidor.yml build app

docker compose -f docker-compose.servidor.yml run --rm app php artisan key:generate --show
docker compose -f docker-compose.servidor.yml run --rm app php artisan mithrandir:vapid
```

Copie a saida para `APP_KEY`, `VAPID_PUBLIC_KEY` e `VAPID_PRIVATE_KEY` no
`.env.servidor`. O `APP_KEY` vai **com** o prefixo `base64:` - sem ele a
aplicacao nao sobe (o proprio boot avisa).

---

## PASSO 3 - Escolher como expor para a internet

### Opcao A - Tunel da Cloudflare (recomendada)

Nao precisa abrir porta no roteador, funciona atras de CGNAT, nao expoe o IP da
sua casa e o TLS e da Cloudflare. Em conexao residencial brasileira e quase
sempre a unica opcao que funciona sem dor: muitos provedores bloqueiam as portas
80 e 443 de entrada, o que impede a validacao do certificado na opcao B.

1. Aponte o dominio para a Cloudflare (nameservers dela).
2. Painel **Zero Trust** -> **Networks** -> **Tunnels** -> **Create a tunnel** ->
   tipo **Cloudflared**.
3. Copie o **token** do tunel para `TUNNEL_TOKEN` no `.env.servidor`.
4. Ainda no painel, aba **Published application routes**:
   - *Subdomain* + *Domain*: o endereco que voce quer
   - *Type*: `HTTP`
   - *URL*: `app:8080`

   > `app:8080` e o nome do servico na rede do compose, nao `localhost`. O
   > cloudflared roda em container e enxerga a aplicacao por esse nome.

5. Suba:

```bash
docker compose -f docker-compose.servidor.yml --profile tunel up -d
```

### Opcao B - Exposicao direta com Caddy

Exige IP publico de verdade (sem CGNAT) e as portas **80 e 443 encaminhadas** no
roteador para a maquina. O Caddy emite e renova Let's Encrypt sozinho.

1. Crie um registro `A` do dominio apontando para seu IP publico.
2. Encaminhe 80 e 443 no roteador.
3. Suba:

```bash
docker compose -f docker-compose.servidor.yml --profile caddy up -d
```

> Se o IP da conexao for dinamico, use um DDNS para manter o registro `A`
> atualizado - senao o site cai a cada renovacao de IP.

---

## PASSO 4 - Acompanhar o primeiro start

```bash
docker compose -f docker-compose.servidor.yml logs -f app
```

O primeiro build leva ~10-15 min. O container espera o Postgres ficar pronto,
cria as tabelas e carrega os dados de referencia (feriados, tipos de prazo,
templates de checklist) sozinho. Espere por:

```
==> Subindo nginx + php-fpm + fila + scheduler na porta 8080
```

---

## PASSO 5 - Primeiro usuario

Acesse `https://<seu-dominio>/cadastrar`.

---

## PASSO 6 - Confirmar que o DJEN responde

Este e o teste que importa. Entre no app, cadastre a OAB a ser vigiada e dispare
a varredura manual de publicacoes.

Se aparecer "nao foi possivel falar com o DJEN", confira o log:

```bash
docker compose -f docker-compose.servidor.yml logs app | grep -i djen
```

- `403` -> a saida da rede nao esta sendo vista como brasileira. Acontece se
  houver VPN ou proxy no caminho.
- `Circuito do DJEN aberto` -> apos 5 falhas seguidas o cliente bloqueia novas
  tentativas por 5 minutos. Espere e tente de novo.

---

## Operacao

```bash
# use sempre o -f; sao dois arquivos compose no repositorio
alias mith='docker compose -f docker-compose.servidor.yml'

mith logs -f app          # acompanhar
mith restart app          # reiniciar
mith exec app php artisan <comando>
git pull && mith up -d --build    # atualizar
```

Os containers sobem sozinhos depois de um reboot (`restart: unless-stopped`),
desde que o servico do Docker esteja habilitado no boot:

```bash
sudo systemctl enable docker
```

### Onde ficam os dados

| O que | Onde |
|---|---|
| Banco | volume `pgdata` |
| Documentos anexados | volume `documentos` |

Nenhum dos dois vive dentro da imagem: `up --build` nao os apaga.

### Backup

Dado de cliente sob LGPD. Programe os dois - banco e documentos:

```bash
# banco
docker compose -f docker-compose.servidor.yml exec -T db \
  sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > banco-$(date +%F).sql

# documentos
docker run --rm \
  -v mithrandir_documentos:/dados:ro \
  -v "$PWD":/saida alpine \
  tar czf /saida/documentos-$(date +%F).tar.gz -C /dados .
```

O nome do volume tem o prefixo do diretorio do projeto; confirme com
`docker volume ls`.

---

## Se preferir banco gerenciado em vez de local

O `docker-compose.servidor.yml` sobe um Postgres proprio, que e o mais simples
numa maquina com disco. Para usar um Postgres gerenciado (Neon, por exemplo):

1. Remova o servico `db` e o `depends_on` do `app`.
2. No `.env.servidor`, apague as variaveis `POSTGRES_*`, `DB_HOST`, `DB_PORT`,
   `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` e use no lugar:

```
DB_URL=postgresql://usuario:senha@host/banco?sslmode=require
DB_MIGRATION_URL=postgresql://usuario:senha@host-sem-pooler/banco?sslmode=require
DB_SSLMODE=require
```

> As duas URLs existem por um motivo real: em Postgres com pooler (PgBouncer em
> transaction mode, como o do Neon) o erro de DDL dentro de transacao nao chega
> ao cliente, e a migration falha com `SQLSTATE[25P02]`. As migrations usam a
> conexao direta; a aplicacao usa a com pooler.

Para guardar os documentos fora da maquina, veja a secao do Backblaze B2 em
`DEPLOY.md` - basta trocar `UPLOAD_DISK` para `s3` e preencher as chaves.
