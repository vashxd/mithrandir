# Deploy: Render + Neon + Cloudflare R2

Stack de producao gratuita para o Mithrandir:

| Peca | Servico | Plano |
|---|---|---|
| Aplicacao (nginx + php-fpm + fila + scheduler) | Render | Free |
| Banco Postgres | Neon | Free |
| Documentos enviados | Cloudflare R2 | Free (10 GB) |

O container roda quatro processos sob supervisord: `nginx`, `php-fpm`,
`queue:work` e `schedule:work`. Nao e preciso servico separado para fila.

---

## 0. Antes de comecar

Gere a chave da aplicacao (nao commite ela):

```bash
php artisan key:generate --show
```

Guarde a saida (`base64:...`) - vai em `APP_KEY` no Render.

Gere tambem as chaves de web push:

```bash
php artisan mithrandir:vapid
```

Guarde a publica e a privada.

---

## 1. Neon (banco Postgres)

1. Entre em <https://neon.com> e crie a conta (da para entrar com o GitHub).
2. **Create project**:
   - *Name*: `mithrandir`
   - *Postgres version*: 17
   - *Region*: `AWS South America (São Paulo)` - menor latencia para o escritorio.
3. Criado o projeto, o Neon mostra a caixa **Connection string**. Escolha o
   dropdown `Parameters only` -> nao; deixe em **Connection string** mesmo e
   marque a opcao **Pooled connection**.
4. Copie a string inteira. Ela tem este formato:

   ```
   postgresql://usuario:senha@ep-algo-pooler.sa-east-1.aws.neon.tech/neondb?sslmode=require
   ```

   Esse valor e o `DB_URL` do passo 3. Guarde - a senha so aparece uma vez.

> Use sempre a **pooled connection** (o host tem `-pooler`). O plano free do
> Neon tem poucas conexoes diretas, e o worker da fila mais o web abrem varias.

---

## 2. Cloudflare R2 (documentos)

O disco do Render free e efemero: tudo que for gravado some no proximo deploy
ou restart. Sem isto, os documentos anexados aos processos se perdem.

1. Entre em <https://dash.cloudflare.com> -> **R2 Object Storage**.
2. Ative o R2 (pede cartao para verificacao, mas o free de 10 GB nao cobra).
3. **Create bucket**: nome `mithrandir-documentos`, location `Automatic`.
4. Menu **R2** -> **API** -> **Manage API tokens** -> **Create API token**:
   - *Permissions*: `Object Read & Write`
   - *Specify bucket*: apenas `mithrandir-documentos`
5. Anote os tres valores que aparecem:
   - **Access Key ID** -> `AWS_ACCESS_KEY_ID`
   - **Secret Access Key** -> `AWS_SECRET_ACCESS_KEY`
   - **Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`) -> `AWS_ENDPOINT`

Mantenha o bucket **privado**. O app serve os arquivos por download autenticado
(`DocumentoController@download`), nunca por URL publica.

---

## 3. Render (aplicacao)

1. Entre em <https://render.com> e conecte a conta do GitHub.
2. **New** -> **Blueprint**, escolha o repositorio `vashxd/mithrandir`,
   branch `main`. O Render le o `render.yaml` e ja monta o servico.

   *(Alternativa sem blueprint: **New** -> **Web Service** -> repositorio ->
   *Language*: `Docker`, *Plan*: `Free`, *Health check path*: `/healthz`.)*

3. Na tela de criacao ele pede as variaveis marcadas como secretas. Preencha:

   | Variavel | Valor |
   |---|---|
   | `APP_KEY` | a chave `base64:...` do passo 0 |
   | `APP_URL` | `https://mithrandir.onrender.com` (ajuste ao nome final) |
   | `DB_URL` | a connection string pooled do Neon |
   | `AWS_ACCESS_KEY_ID` | Access Key ID do R2 |
   | `AWS_SECRET_ACCESS_KEY` | Secret Access Key do R2 |
   | `AWS_BUCKET` | `mithrandir-documentos` |
   | `AWS_ENDPOINT` | `https://<account-id>.r2.cloudflarestorage.com` |
   | `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | do passo 0 |
   | `VAPID_SUBJECT` | `mailto:seu@email.com` |

   O resto (`APP_ENV`, `DB_CONNECTION`, `UPLOAD_DISK`, etc.) ja vem do
   `render.yaml`.

4. **Create**. O primeiro build leva ~8-12 min (compila assets e extensoes PHP).
5. O `entrypoint` roda `php artisan migrate --force` sozinho a cada deploy.
   Acompanhe em **Logs** ate ver `Subindo nginx + php-fpm + fila + scheduler`.

6. `APP_URL` so e conhecido depois do primeiro deploy. Se voce chutou o nome,
   volte em **Environment**, corrija e faca **Manual Deploy**.

### Criar o primeiro usuario

No painel do servico, aba **Shell**:

```bash
php artisan tinker
```

E crie o advogado inicial conforme o `README.md`.

---

## 4. Manter o servico acordado (importante)

O plano free do Render **hiberna apos 15 min sem requisicao**. Isso tem uma
consequencia seria aqui: o scheduler de `routes/console.php` - varredura do DJEN
as 06:00, digests de hora em hora - **nao dispara com o container dormindo**.
Prazo perdido por varredura que nao rodou e o pior modo de falha deste sistema.

Solucao: um ping externo gratuito no health check.

1. <https://cron-job.org> -> conta gratuita -> **Create cronjob**.
2. URL: `https://mithrandir.onrender.com/healthz`
3. Schedule: a cada 10 minutos.

O free do Render da 750 horas-instancia/mes; um unico servico sempre acordado
consome ~730h, entao cabe - desde que seja **so este** servico na conta.

---

## Limitacoes conhecidas deste arranjo

- **Cold start**: se o ping falhar, a primeira requisicao demora ~50s.
- **Neon free**: 0,5 GB de storage e o branch dorme apos inatividade (acorda em
  poucos segundos na primeira query). Suficiente para o volume de um escritorio
  pequeno, mas monitore o uso.
- **Sem backup automatico**. Configure um dump periodico do Neon; dado de
  cliente sob LGPD nao pode depender de um plano free como unica copia.
- **Deploy reinicia a fila**: jobs em andamento voltam para a fila (`--tries=3`).

## Rodando o container localmente

```bash
docker build -t mithrandir .
docker run --rm -p 8080:8080 -e PORT=8080 \
  -e APP_KEY="base64:..." \
  -e DB_CONNECTION=pgsql -e DB_URL="postgresql://..." \
  mithrandir
```
