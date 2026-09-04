# Mithrandir

PWA mobile-first de gestão para advogados autônomos.

> A tela **Hoje** abre e responde três perguntas: o que vence, o que tenho que fazer,
> e quem me deve. Nada mais.

Implementação da [especificação v0.1](especificacao-app-advocacia.md) — escopo v1 (MVP).

---

## O que está pronto

| Módulo | Estado |
|---|---|
| **M1** Radar de publicações (DJEN) | Termos de vigilância, job diário 06:00, janela de 7 dias (30 na primeira varredura), dedup por hash, inbox de triagem com swipe, vínculo automático ao processo, auditoria de varredura, alerta de "radar cego" |
| **M2** Motor de prazos | `PrazoService` puro, dias úteis/corridos, feriados por abrangência, recesso do art. 220, prazo em dobro, buffer, cadeia de origem visível, ajuste manual com justificativa, recálculo em lote |
| **M3** Agenda | Timeline única, visões Hoje/Semana/Mês/Fatais em 7 dias, cor por criticidade, audiência com local e link, exportação `.ics` |
| **M4** Casos | Cadastro e edição (inclusive vincular cliente depois), número CNJ com validação de DV, abas Dados/Prazos/Documentos/Financeiro, timeline do caso, próxima ação, arquivamento, importação DataJud |
| **M5** Clientes | Ficha editável, contatos, atendimentos, "copiar status para o cliente", vigilância do nome do cliente no DJEN com prévia de volume |
| **M6** Documentos | Câmera multi-página → PDF único, compressão no cliente, fila offline, checklists com % |
| **M7** Financeiro | Honorários, geração de parcelas, baixa, despesas, painel do mês, provisão de imposto |
| **M8** Notificações | Web Push (VAPID), D-10/D-5/D-3/D-1/dia fatal, digest diário, fallback e-mail |
| **Equipe** | Convite por link, papéis (advogado / estagiário), acesso por caso, troca de espaço de trabalho, responsável por prazo e fila de conferência |
| **M9** Conta | Cadastro OAB/UF, aceite do termo, configurações, exportação LGPD, exclusão com carência |

---

## Rodando

Requisitos: PHP 8.3+, Composer, Node 20.19+ ou 22.12+.

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate --seed          # feriados, tipos de prazo, checklists
php artisan mithrandir:vapid --escrever   # chaves do Web Push
php artisan config:clear

npm run build
php artisan serve
```

Conta de demonstração (opcional):

```bash
php artisan db:seed --class=DemonstracaoSeeder
# ana@mithrandir.test / mithrandir
```

Em desenvolvimento, `npm run dev` em outro terminal.

### Fila e agendador

O radar, os alertas e o digest vivem na fila e no scheduler:

```bash
php artisan queue:work
php artisan schedule:work
```

| Horário (America/Sao_Paulo) | Job |
|---|---|
| 06:00 | `SyncPublicacoesJob` — um por termo de vigilância, janela de 7 dias |
| 06:30 | `AgendarAlertasPrazoJob` — antecedências D-10 … D-0 |
| de hora em hora | `DigestDiarioJob` + `DispararNotificacoesJob` |
| segunda, 07:00 | `RevisarCalendarioJob` — lembrete trimestral de feriados |

### Windows: `OPENSSL_CONF`

O Web Push depende de curva elíptica no OpenSSL. Se `mithrandir:vapid` reclamar,
aponte a variável para o `openssl.cnf` que vem com o PHP:

```
setx OPENSSL_CONF "C:\Program Files\PHP\current\extras\ssl\openssl.cnf"
```

Sem isso o push falha também em tempo de envio, não só na geração das chaves.

---

## Testes

```bash
php artisan test
```

124 testes. A suíte da seção 8.3 da especificação (`tests/Unit/PrazoServiceTest.php`)
roda sem banco, sem Laravel e sem rede — o `PrazoService` é função pura:

1. Prazo simples de 15 dias úteis
2. Disponibilização na sexta → publicação na segunda → início na terça
3. Véspera de feriado prolongado
4. Prazo atravessando o recesso de 20/12 a 20/01
5. Fatal em feriado municipal de Manaus
6. Prazo em dobro para a Fazenda
7. Prazo em dias corridos (penal)
8. Feriado cadastrado retroativamente → recálculo em lote
9. Prazo de 5 dias em semana com dois feriados
10. Ajuste manual preservando o valor calculado

---

## Arquitetura

```
PWA (Vue 3 + Inertia)          Service Worker · IndexedDB (Dexie) · Web Push
        │ HTTPS / JSON
Laravel 13
        ├─ PrazoService        função pura, sem I/O — feriados entram como argumento
        ├─ CalendarioService   ponte com a tabela `feriados`
        ├─ DjenClient          HTTP + retry + circuit breaker próprio
        ├─ DataJudClient       HTTP + cache 12h + circuit breaker separado
        ├─ IngestaoService     dedup, vínculo, auditoria, radar cego
        └─ SyncController      outbox offline, conflitos
        │
Queue Workers + Scheduler
```

**Decisões que valem repetir:**

1. `PrazoService` não toca banco nem relógio. É o que torna os 10 casos da seção 8.3
   executáveis sem infraestrutura — e o que permite auditar o cálculo.
2. DJEN e DataJud têm circuit breakers **independentes**. Queda do DataJud não pode
   derrubar a ingestão de prazo, porque o DataJud é enriquecimento e o DJEN é gatilho.
3. Publicação é imutável. Triagem cria registros novos e marca status; descarte
   arquiva, nunca apaga. A publicação é prova de que o app viu (ou não viu) o ato.
4. Prazo e financeiro **nunca** resolvem conflito de sincronização sozinhos — viram
   item de revisão.
5. Multi-tenant desde a primeira migration: `advogado_id` em tudo. Foi essa decisão
   que tornou a equipe barata de implementar — bastou trocar de onde vem o id do
   tenant (`contexto()->advogadoId()` em vez de `auth()->id()`), sem re-arquitetar
   as 9 tabelas nem as consultas.

### Offline

- Shell e assets em cache (stale-while-revalidate); navegação network-first com
  fallback para `/offline.html`.
- Leitura espelhada em IndexedDB via `/sync/snapshot`.
- Escritas offline vão para o outbox local e sobem no replay, com `client_id` para
  idempotência.
- Fotos entram em fila separada; a barra do topo mostra "N itens aguardando envio".

---

## Equipe

O titular convida por link; o convite só é aceito por quem entrar com o e-mail
convidado. Dois papéis:

| | Vê | Fecha prazo | Financeiro | Radar e conta |
|---|---|---|---|---|
| **advogado** | casos liberados | sim | sim | não |
| **estagiário** | casos liberados | **não** | não | não |

**O acesso nasce por caso.** Sigilo profissional é do cliente (EOAB art. 34) — um
estagiário raramente precisa da carteira inteira. Quem tem recorte só enxerga
prazos, documentos, agenda e clientes dos casos liberados; registro sem processo
vinculado fica com o titular.

**Estagiário não fecha prazo.** Ele toca em "Fiz a minha parte", o prazo entra em
conferência e **continua aberto e vermelho** até o titular confirmar. Isso não é
burocracia: quem responde pela perda perante a OAB é o titular, e um toque errado
não pode transferir esse risco. A auditoria grava quem agiu (`autor_id`) separado
de quem é dono do dado.

Uma faixa roxa fixa no topo mostra em qual espaço a pessoa está — trabalhar no
caso do outro sem perceber seria o pior erro possível aqui.

## O desenho de risco

Esta parte não é disclaimer, é produto:

- **Toda data de prazo exibe a cadeia de origem completa** — disponibilização,
  publicação (art. 224 §2), início (art. 224 §3), contagem (art. 219), prorrogação
  (art. 224 §1), buffer — com a base legal de cada passo e os dias em que o prazo
  não correu.
- **Ajuste manual exige justificativa** e preserva o valor calculado ao lado do
  ajustado, em log de auditoria.
- **O calendário de feriados é assumidamente incompleto.** Não existe fonte pública
  unificada de feriado forense no Brasil. A carga inicial cobre nacional + AM
  (TJAM, TRF1/JEF-AM, TRT11); a tela de feriados carrega um aviso permanente e o
  `RevisarCalendarioJob` cobra revisão trimestral.
- **Falha de varredura é evento de severidade alta.** Duas falhas seguidas disparam
  "seu radar está cego" no mesmo dia.
- O termo de uso (RF-9.2) abre com a cláusula de conferência obrigatória, e o aceite
  é pré-requisito para usar o app.

---

## Integrações

| | Custo | Papel |
|---|---|---|
| **DJEN / Comunica** (`comunicaapi.pje.jus.br`) | gratuito, sem chave | Gatilho formal de prazo |
| **DataJud** (`api-publica.datajud.cnj.jus.br`) | gratuito, chave pública do CNJ | Enriquecimento do caso. **Nunca** gatilho de prazo |
| **Web Push** | zero (VAPID self-hosted) | Notificação |

A chave do DataJud é editável em runtime (Configurações → DataJud), porque o CNJ
pode trocá-la sem aviso. Um 401 registra alerta e aparece na tela em vez de virar 500.

### Vigilância por nome de cliente

Além da OAB do advogado, dá para vigiar o **nome de um cliente** como parte do
processo (Clientes → ficha → "Vigiar no DJEN"). Publicações que chegam por aí
vêm marcadas com selo roxo `cliente: <nome>`, para não se confundirem com as da
sua OAB.

**Não existe busca por CPF/CNPJ.** Verificado contra a API real com sete nomes de
parâmetro (`numeroDocumento`, `cpfCnpj`, `documentoParte`, `cpf`, `documento`,
`numeroCpfCnpj`, `numeroDocumentoParte`): todos são silenciosamente ignorados e a
API devolve o diário inteiro. É a mesma restrição de LGPD que a especificação
registra para o DataJud na seção 7.2. O documento é guardado normalizado (com ou
sem pontuação vira o mesmo registro) e serve para identificar o cliente na
triagem, nunca como termo de busca.

Como a única chave é o nome, homônimo é inevitável — "Maria da Silva" traz dez
mil resultados em 30 dias. Por isso a vigilância nasce desligada e a tela mostra
**quantas publicações aquele nome traria** antes de você ligar.

### Guarda de volume anormal

`DJEN_TETO_VARREDURA` (padrão 300) recusa o lote inteiro quando uma varredura
volta acima do plausível para um advogado solo, e avisa em vez de importar. É o
inverso do radar cego: um filtro que parou de filtrar — por nome comum demais ou
por mudança de contrato do CNJ — afogaria a triagem, e o prazo de verdade sumiria
no ruído.

### Janela de consulta

Cada advogado escolhe a sua em Configurações → "Janela de busca no DJEN"; em
branco usa o padrão do sistema.

`DJEN_JANELA_DIAS` (padrão 7) e `DJEN_JANELA_INICIAL_DIAS` (padrão 30, só na
primeira varredura de cada termo) controlam quantos dias para trás cada busca
cobre. Sete dias em vez do mínimo de dois é deliberado: se a varredura falhar
alguns dias seguidos, a próxima que der certo cobre o buraco sozinha. A
deduplicação por hash torna a releitura barata e idempotente. O piso é sempre
ontem + hoje (RF-1.3), independente da configuração.

**iOS:** Web Push só funciona com o PWA instalado na tela de início. O onboarding
força esse passo — sem ele o produto não entrega a promessa central.

---

## Fora do escopo do v1

Google Calendar, WhatsApp Business API, gateway de pagamento, assinatura eletrônica,
peticionamento via Jus.br, OCR, geração de peças, portal do cliente. Todos v2+.

O produto **não é** editor de petição, base de jurisprudência, ERP contábil nem
rede social jurídica.
