# Deploy: Render + Neon + Backblaze B2

Stack de producao gratuita para o Mithrandir:

| Peca | Servico | Plano |
|---|---|---|
| Aplicacao (nginx + php-fpm + fila + scheduler) | Render | Free |
| Banco Postgres | Neon | Free |
| Documentos enviados | Backblaze B2 | Free (10 GB) |

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

> **No Windows** o comando falha com "OpenSSL nao consegue gerar chave de curva
> eliptica" enquanto `OPENSSL_CONF` estiver vazia. Aponte para o arquivo que vem
> com o PHP e rode de novo - sem isso o proprio envio de push tambem falha em
> dev, nao so a geracao:
>
> ```bash
> OPENSSL_CONF="C:/Program Files/PHP/current/extras/ssl/openssl.cnf" >   php artisan mithrandir:vapid
> ```
>
> No container Alpine do deploy isso nao ocorre.

---

## 1. Neon (banco Postgres)

1. Entre em <https://neon.com> e crie a conta (da para entrar com o GitHub).
2. **Create project**:
   - *Name*: `mithrandir`
   - *Postgres version*: 17
   - *Region*: `AWS South America (São Paulo)` - menor latencia para o escritorio.
3. Criado o projeto, o Neon mostra a caixa **Connection string**. Voce precisa
   de **duas** variantes da mesma string, alternando o toggle *Connection pooling*:

   | Toggle | Host | Vai em |
   |---|---|---|
   | Ligado | `ep-algo-pooler.sa-east-1...` | `DB_URL` |
   | Desligado | `ep-algo.sa-east-1...` | `DB_MIGRATION_URL` |

   A diferenca no host e so o sufixo `-pooler`; usuario e senha sao os mesmos.
   Guarde as duas - a senha so aparece uma vez.

> **Por que duas.** O app em runtime usa a pooled: o free do Neon tem poucas
> conexoes diretas e web + fila + scheduler abrem varias. Mas o pooler e o
> PgBouncer em transaction mode, e ele **nao propaga o erro de DDL dentro de
> transacao**: o `CREATE TABLE` da migration retorna sucesso para o PDO enquanto
> a transacao ja abortou no servidor, e a migration morre com
> `SQLSTATE[25P02] current transaction is aborted`. Por isso `php artisan
> migrate` roda pela conexao `pgsql_unpooled`, que aponta para o endpoint direto.
> Isso ja esta ligado no `entrypoint` - voce so precisa fornecer as duas URLs.

4. Rode as migrations agora, do seu proprio terminal, para validar o schema
   antes de envolver o Render:

   ```bash
   DB_CONNECTION=pgsql DB_URL='<string SEM -pooler>'      php artisan migrate --force
   ```

---

## 2. Backblaze B2 (documentos)

O disco do Render free e efemero: tudo que for gravado some no proximo deploy
ou restart. Sem isto, os documentos anexados aos processos se perdem.

Usamos o B2 e nao o Cloudflare R2 porque o R2 exige cartao para liberar a conta,
mesmo dentro da cota gratuita. O B2 da 10 GB permanentes sem cartao e expoe uma
API S3 completa - do lado do Laravel e o mesmo disco `s3`, so muda o endpoint.

1. Crie a conta em <https://www.backblaze.com/sign-up/cloud-storage>.
2. No painel, **B2 Cloud Storage** -> **Buckets** -> **Create a Bucket**:
   - *Bucket Name*: `mithrandir-documentos` (o nome e global; se estiver em uso,
     acrescente um sufixo e ajuste `AWS_BUCKET`)
   - *Files in Bucket are*: **Private**
   - *Default Encryption*: habilitado
   - *Object Lifecycle*: **Keep only the last version**
3. Criado o bucket, a lista mostra o **Endpoint**, algo como
   `s3.us-west-004.backblazeb2.com`. Anote os dois pedacos:
   - `AWS_ENDPOINT` = `https://s3.us-west-004.backblazeb2.com`
   - `AWS_DEFAULT_REGION` = `us-west-004`

   > A regiao precisa ser a real do bucket. Diferente do R2, o B2 nao aceita
   > `auto` - assinatura V4 com regiao errada devolve `SignatureDoesNotMatch`.

   > O padrao do B2 e *Keep all versions*: cada documento substituido ou
   > excluido pelo app deixa a versao antiga ocupando espaco, e a cota de 10 GB
   > se esgota com arquivo que voce acha que apagou.

4. **Application Keys** -> **Add a New Application Key**:
   - *Name*: `mithrandir-render`
   - *Allow access to Bucket*: apenas `mithrandir-documentos`
   - *Type of Access*: **Read and Write**

   > Nao use a **Master Application Key**. Ela carrega `deleteBuckets`,
   > `writeKeys` e `listAllBucketNames` - manda na conta inteira. Essa chave vai
   > viver numa variavel de ambiente de um app exposto na internet; restrita ao
   > bucket, um vazamento atinge so os documentos, nao a conta.
5. Anote o que aparece (o `applicationKey` so e exibido uma vez):
   - **keyID** -> `AWS_ACCESS_KEY_ID`
   - **applicationKey** -> `AWS_SECRET_ACCESS_KEY`

Mantenha o bucket **privado**. O app serve os arquivos por download autenticado
(`DocumentoController@download`), nunca por URL publica.

> **Alternativa se o B2 nao servir:** Supabase Storage, tambem S3-compativel e
> sem cartao, mas so 1 GB e o projeto free hiberna apos 7 dias sem uso - o que
> deixaria os documentos indisponiveis. Para arquivo de escritorio, o B2 e a
> escolha melhor.

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
   | `DB_URL` | connection string do Neon **com** `-pooler` |
   | `DB_MIGRATION_URL` | a mesma string **sem** `-pooler` |
   | `AWS_ACCESS_KEY_ID` | keyID do B2 |
   | `AWS_SECRET_ACCESS_KEY` | applicationKey do B2 |
   | `AWS_BUCKET` | `mithrandir-documentos` |
   | `AWS_ENDPOINT` | `https://s3.<regiao>.backblazeb2.com` |
   | `AWS_DEFAULT_REGION` | a regiao do bucket, ex.: `us-west-004` |
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

Pela propria aplicacao, em `https://<sua-url>/cadastrar`.

> A aba **Shell** do Render **nao existe no plano free** - e recurso de instancia
> paga. Nada aqui depende dela. Se precisar rodar um comando artisan contra a
> producao, rode do seu proprio terminal apontando para o Neon, que e acessivel
> de qualquer lugar:
>
> ```bash
> DB_CONNECTION=pgsql DB_URL='<string do Neon>' php artisan <comando>
> ```

Os dados de referencia (feriados, tipos de prazo, templates de checklist) ja sao
semeados pelo `entrypoint` a cada deploy - os seeders usam `updateOrCreate`, entao
repetir nao duplica nada. Para desligar, `RUN_SEED=false`.

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

## Depurando um erro 500 sem acesso a Shell

Em producao `APP_DEBUG=false`, entao o 500 chega ao navegador sem explicacao, e o
log as vezes mostra so `Cannot modify header information - headers already sent`,
que e o erro secundario de quando a propria pagina de erro falha ao renderizar.

Para ver a excecao real:

1. **Environment** -> `APP_DEBUG` = `true` -> **Save** (o Render redeploya).
2. Acesse a URL: a pagina passa a mostrar a excecao com arquivo e linha.
3. **Volte `APP_DEBUG` para `false`** assim que identificar - com ele ligado
   qualquer visitante ve stack trace, caminho de arquivo e trechos de config.

Pelos logs tambem da: aba **Logs** (essa e gratuita), procure a **primeira** linha
`production.ERROR:` do bloco - e ela que traz a mensagem original, nao o fim do
stack trace.

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
