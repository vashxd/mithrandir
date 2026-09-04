# Deploy na Oracle Cloud Always Free (Sao Paulo)

Motivo da migracao: a API do DJEN responde **403 para IP estrangeiro**, e o plano
free do Render nao tem regiao no Brasil. Detalhes da medicao em `DEPLOY.md`.

O que muda e apenas onde o container roda. **Neon e Backblaze B2 continuam como
estao** - nada a refazer neles.

| Peca | Onde | Muda? |
|---|---|---|
| Aplicacao | VM Oracle, `sa-saopaulo-1` | sim |
| TLS / dominio | Caddy na propria VM | novo |
| Postgres | Neon | nao |
| Documentos | Backblaze B2 | nao |

---

## PASSO 0 - Teste o bloqueio antes de tudo

**Faca isto antes de migrar qualquer coisa.** O 403 prova que IP estrangeiro e
bloqueado, mas nao garante que um IP de datacenter brasileiro passe: se a regra
do CNJ tambem barrar faixas de nuvem, a Oracle cai no mesmo problema e a migracao
inteira e desperdicio.

Assim que a VM existir e voce conseguir entrar por SSH (fim do passo 3), rode:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  'https://comunicaapi.pje.jus.br/api/v1/comunicacao?numeroOab=21100&ufOab=AM&dataDisponibilizacaoInicio=2026-09-01&dataDisponibilizacaoFim=2026-09-04'
```

- `200` -> siga para o passo 4.
- `403` -> **pare**. A Oracle nao resolve; o caminho passa a ser hospedagem
  brasileira fora do gratuito (~R$ 25-40/mes).

---

## PASSO 1 - Conta na Oracle Cloud

1. <https://www.oracle.com/br/cloud/free/> -> **Comece gratuitamente**.
2. Escolha **Brazil East (Sao Paulo)** como *Home Region*. Isso **nao pode ser
   mudado depois** e e o que garante o IP brasileiro.
3. O cadastro pede cartao para verificacao. O Always Free nao cobra; a cobranca
   so existe se voce fizer upgrade explicito para Pay As You Go.

---

## PASSO 2 - Criar a VM

**Compute** -> **Instances** -> **Create instance**.

- *Name*: `mithrandir`
- *Image*: **Canonical Ubuntu 24.04**
- *Shape*: **Change shape** -> **Ampere** -> `VM.Standard.A1.Flex`
  - OCPUs: **2**, Memoria: **12 GB**
  - Confira o selo **Always Free eligible** antes de confirmar
- *Networking*: deixe criar a VCN nova, com **Assign a public IPv4 address**
- *Add SSH keys*: **Generate a key pair for me** e **baixe a chave privada**
  (ela nao fica disponivel depois)

> **"Out of host capacity"** e comum nas maquinas ARM gratuitas. Tente outro
> *Availability Domain* no formulario, ou repita mais tarde. Se insistir em
> falhar, `VM.Standard.E2.1.Micro` (AMD) tambem e Always Free, mas com 1 GB de
> RAM o `npm run build` do passo 6 nao passa - nesse caso construa a imagem em
> outro lugar e envie pronta.

Anote o **Public IP address** da instancia.

---

## PASSO 3 - Entrar por SSH

```bash
chmod 600 ~/Downloads/ssh-key-*.key
ssh -i ~/Downloads/ssh-key-*.key ubuntu@<IP-PUBLICO>
```

Dentro da VM, **volte ao PASSO 0 e rode o teste do DJEN agora.**

---

## PASSO 4 - Abrir as portas (sao dois lugares)

Este e o tropeco classico da Oracle: abrir so no painel nao funciona, porque a
imagem Ubuntu vem com `iptables` fechado por dentro.

**4a. No painel** - *Instance* -> *Virtual cloud network* -> *Security Lists* ->
*Default Security List* -> **Add Ingress Rules**, duas regras:

| Source CIDR | IP Protocol | Destination Port |
|---|---|---|
| `0.0.0.0/0` | TCP | `80` |
| `0.0.0.0/0` | TCP | `443` |

**4b. Na VM** - libere e persista no `iptables`:

```bash
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo apt-get update && sudo apt-get install -y iptables-persistent
sudo netfilter-persistent save
```

Sem o 4b, o Caddy nao consegue validar o certificado e o site nunca sobe.

---

## PASSO 5 - Docker

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
```

Saia e entre de novo no SSH para o grupo valer. Confira:

```bash
docker run --rm hello-world
```

---

## PASSO 6 - Dominio

O Caddy emite Let's Encrypt sozinho, mas precisa de um nome que aponte para o IP.

**Se voce tem dominio:** crie um registro `A` apontando para o IP publico e use
esse nome.

**Se nao tem:** use `sslip.io`, que resolve qualquer IP embutido no nome. Para o
IP `129.148.1.2`, o dominio e `129.148.1.2.sslip.io`. Funciona com Let's Encrypt
e nao exige cadastro.

> HTTPS nao e opcional aqui: service worker e web push so funcionam sob TLS, e o
> app e PWA.

---

## PASSO 7 - Subir a aplicacao

```bash
git clone https://github.com/vashxd/mithrandir.git
cd mithrandir
cp .env.producao.exemplo .env.producao
nano .env.producao
```

Preencha com os valores que voce ja tem do Render (`APP_KEY`, `DB_URL`,
`DB_MIGRATION_URL`, as quatro do B2 e as tres do VAPID), mais:

```
APP_DOMINIO=129.148.1.2.sslip.io
APP_URL=https://129.148.1.2.sslip.io
```

`APP_DOMINIO` vai **sem** `https://`; `APP_URL` vai **com**.

Suba:

```bash
docker compose up -d --build
```

O primeiro build leva ~10-15 min. Acompanhe:

```bash
docker compose logs -f app
```

Espere `Subindo nginx + php-fpm + fila + scheduler na porta 8080`. As migrations
e os seeders rodam sozinhos no entrypoint - e como o banco Neon ja esta migrado e
semeado, vao passar direto.

Acesse `https://<seu-dominio>`. A primeira carga demora alguns segundos enquanto
o Caddy emite o certificado.

---

## PASSO 8 - Primeiro usuario

Em `https://<seu-dominio>/cadastrar`.

Se o banco Neon ja tiver o usuario criado no Render, ele continua valendo - o
banco e o mesmo.

---

## PASSO 9 - Confirmar que o DJEN funciona

Entre no app e dispare a varredura manual das publicacoes. Deve trazer resultado
em vez de "confira o diario manualmente".

Confirme tambem pelo log:

```bash
docker compose logs app | grep -i djen
```

Nao deve haver `DJEN respondeu com erro {"status":403}`.

---

## Depois da migracao

**Desligue o servico no Render** para nao manter duas instancias gravando no
mesmo banco - duas filas consumindo os mesmos jobs causa processamento duplicado.
No painel do Render: *Settings* -> *Suspend Web Service* (ou *Delete*).

**Cancele o ping do cron-job.org.** Ele existia so para impedir a hibernacao do
Render. A VM nao hiberna, e agora a varredura das 06:00 roda de verdade.

### Operacao

```bash
# atualizar apos um push
cd ~/mithrandir && git pull && docker compose up -d --build

# logs
docker compose logs -f app

# reiniciar
docker compose restart app

# comando artisan avulso
docker compose exec app php artisan <comando>
```

> Diferente do Render free, aqui **existe** shell. `docker compose exec` da
> acesso direto ao artisan no container.

### Backup

O banco continua no Neon e os documentos no B2, entao a VM nao guarda estado -
se ela morrer, basta recriar e subir de novo. O que precisa de backup e o Neon:
programe um `pg_dump` periodico antes de entrar dado real de cliente.

```bash
# aspas simples: a variavel tem de ser expandida dentro do container,
# nao pelo shell da VM, que nao a conhece
docker compose exec app sh -c 'pg_dump "$DB_MIGRATION_URL"' > backup-$(date +%F).sql
```
