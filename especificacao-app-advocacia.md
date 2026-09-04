# Especificação — PWA de Gestão para Advogados Autônomos

**Versão:** 0.1 (rascunho de projeto)
**Data:** 03/09/2026
**Autor:** Heriberto
**Status:** Definição de escopo — pré-desenvolvimento

---

## 1. Visão do produto

Um PWA mobile-first que resolve a dor número 1 do advogado solo: **não perder prazo**, e a partir daí organiza o resto da operação (agenda, casos, documentos e dinheiro) sem virar um ERP.

**Frase de posicionamento:**
> "O app abre na tela 'Hoje' e responde três perguntas: o que vence, o que tenho que fazer, e quem me deve. Nada mais."

**Diferencial técnico:** integração direta e gratuita com o DJEN (Comunica/CNJ), que hoje é o gatilho formal da contagem de prazo. Concorrentes cobram caro por essa camada; o custo real dela é engenharia, não licença.

**O que o produto NÃO é:** não é editor de petição, não é jurisprudência, não é ERP contábil, não é rede social jurídica.

---

## 2. Público-alvo

**Persona primária — "Advogada solo recém-inscrita"**
- 0 a 5 anos de OAB, atua sozinha ou com um estagiário.
- Áreas típicas: previdenciário (BPC/LOAS, auxílio-maternidade, aposentadorias), consumidor, família.
- Carteira de 15 a 80 processos ativos.
- Trabalha do celular boa parte do dia: fórum, INSS, CRAS, atendimento domiciliar.
- Controla prazo hoje em planilha, agenda do celular ou papel.
- Baixa tolerância a preço: se passar de ~R$ 60/mês, ela volta pra planilha.

**Persona secundária — "Escritório de 2 a 4 pessoas"** (v3, não é foco do v1)

**Anti-persona:** escritório com 20+ advogados, departamento jurídico de empresa, contencioso de massa. Volume e requisitos de permissão destroem a simplicidade do produto.

---

## 3. Dores validadas (base da priorização)

| # | Dor | Impacto | Módulo que resolve |
|---|-----|---------|--------------------|
| D1 | Medo constante de perder prazo; gestão manual em planilha carrega risco de revelia/preclusão | Crítico — perda financeira e reputacional | M1 + M2 |
| D2 | O advogado solo acumula prospecção, petição, recepção, financeiro, gestão de prazos e protocolo | Alto — sobrecarga e jornada sem fim | Todos |
| D3 | Horas da manhã consumidas por rotina administrativa | Alto — menos tempo faturável | M1, M3 |
| D4 | Documentos espalhados em pastas físicas, e-mail e nuvem; difícil recuperar | Médio-alto | M6 |
| D5 | Falta de planejamento financeiro, sem reserva para impostos nem precificação clara | Alto — instabilidade | M7 |
| D6 | Cliente pergunta status por WhatsApp o tempo todo | Médio — interrupção constante | M5 |
| D7 | Com a centralização no DJEN, acompanhar a intimação virou obrigação individual do advogado | Crítico | M1 |

---

## 4. Princípios de produto (guiam decisões de escopo)

1. **A tela "Hoje" é o produto.** Se algo não cabe nela ou não alimenta ela, é secundário.
2. **O app nunca é fonte única de prazo.** Toda data exibe a cadeia de origem e é ajustável com justificativa. Conferência no diário permanece obrigação do advogado — isso é design de risco, não disclaimer.
3. **Offline não é feature, é premissa.** Fórum sem sinal é o caso de uso mais comum.
4. **Zero digitação redundante.** O que a API traz não se redigita.
5. **Nenhuma integração paga no v1.** Toda dependência externa do v1 é gratuita e pública.
6. **Cada tela responde uma pergunta.** Sem dashboards decorativos.

---

## 5. Matriz de escopo

| Módulo | v1 (MVP — 8 semanas) | v2 | v3 |
|---|---|---|---|
| M1 Radar de publicações | DJEN por OAB + variações de nome, inbox de triagem | Alerta de nova distribuição | Monitoramento por CPF/CNPJ do cliente |
| M2 Motor de prazos | Contagem em dias úteis, feriados, buffer, ajuste manual | Prazos internos encadeados | Sugestão de tipo de prazo por IA |
| M3 Agenda | Timeline única, evento manual, audiências | Google Calendar (2 vias) | Bloqueio de foco / roteiro do dia |
| M4 Casos | Ficha, partes, fase, movimentações (DataJud) | Esteiras por tipo de ação | Jurimetria da carteira |
| M5 Clientes | Cadastro, contatos, histórico | Link público de acompanhamento | Portal do cliente autenticado |
| M6 Documentos | Upload, foto via câmera, checklist | OCR + busca full-text | Geração de peças por template |
| M7 Financeiro | Honorários, parcelas, a receber, despesas | PIX/boleto, provisão de imposto | Relatórios e DRE |
| M8 Notificações | Web Push + digest diário | E-mail, WhatsApp | Escalonamento por criticidade |

---

## 6. Requisitos funcionais

### M1 — Radar de publicações (DJEN)

| ID | Requisito | Prioridade |
|---|---|---|
| RF-1.1 | Cadastrar múltiplos "termos de vigilância" (OAB com/sem dígito, nome completo, nome social, variações de grafia) | Must |
| RF-1.2 | Job diário automático (06:00 America/Sao_Paulo) consultando a API do DJEN por termo | Must |
| RF-1.3 | Janela de consulta = ontem + hoje, nunca só hoje (evita perda/duplicação por virada de fuso) | Must |
| RF-1.4 | Deduplicação por hash do número de comunicação | Must |
| RF-1.5 | Inbox de triagem: cada publicação vira **prazo**, **ciência** (sem prazo) ou **descarte** | Must |
| RF-1.6 | Vínculo automático da publicação ao processo existente pelo número CNJ; se não existir, oferecer criar o caso | Must |
| RF-1.7 | Exibir teor completo da publicação, tribunal, órgão, data de disponibilização | Must |
| RF-1.8 | Botão "sincronizar agora" com rate limit no cliente | Should |
| RF-1.9 | Registro de auditoria de cada varredura (sucesso, falha, quantidade) | Should |
| RF-1.10 | Alerta se a varredura falhar 2 dias seguidos ("seu radar está cego") | Must |

> **Regra de ouro:** publicação nunca é apagada, só arquivada. É prova de que o app viu (ou não viu) o ato.

### M2 — Motor de prazos

| ID | Requisito | Prioridade |
|---|---|---|
| RF-2.1 | Calcular data-fatal a partir da data de disponibilização, tipo de prazo e tribunal | Must |
| RF-2.2 | Contagem em dias úteis (CPC art. 219), com opção de dias corridos (penal, alguns administrativos) | Must |
| RF-2.3 | Aplicar calendário de feriados nacionais, estaduais, municipais e forenses por tribunal | Must |
| RF-2.4 | Suspensão de 20/12 a 20/01 (CPC art. 220) | Must |
| RF-2.5 | Multiplicador de prazo (2x para Fazenda Pública, DP, MP) — sugerido, nunca automático | Must |
| RF-2.6 | Data-alvo interna = data-fatal − buffer configurável (padrão: 3 dias úteis) | Must |
| RF-2.7 | Exibir cadeia de origem completa na tela do prazo | Must |
| RF-2.8 | Ajuste manual da data com campo obrigatório de justificativa e log | Must |
| RF-2.9 | Estados do prazo: aberto → em andamento → cumprido / perdido / prejudicado | Must |
| RF-2.10 | Catálogo de tipos de prazo comuns com dias pré-preenchidos (contestação 15, apelação 15, embargos 5, recurso inominado 10, contrarrazões 15...) | Should |
| RF-2.11 | Recalcular todos os prazos afetados quando um feriado é cadastrado retroativamente, com aviso ao usuário | Must |

### M3 — Agenda

| ID | Requisito | Prioridade |
|---|---|---|
| RF-3.1 | Timeline única: prazos + audiências + compromissos + tarefas | Must |
| RF-3.2 | Visões: Hoje, Semana, Mês, "Fatais em 7 dias" | Must |
| RF-3.3 | Código de cor por criticidade (fatal ≤ 3 dias úteis = vermelho) | Must |
| RF-3.4 | Criar evento manual com vínculo opcional a caso/cliente | Must |
| RF-3.5 | Audiência com campo de local, link de videoconferência e lembrete de deslocamento | Should |
| RF-3.6 | Exportar .ics do evento | Could |

### M4 — Casos e processos

| ID | Requisito | Prioridade |
|---|---|---|
| RF-4.1 | Cadastro por número CNJ com validação de dígito verificador | Must |
| RF-4.2 | Campos: cliente, parte contrária, tribunal, vara, classe, assunto, fase, valor da causa, honorário vinculado | Must |
| RF-4.3 | Timeline do caso (publicações, prazos, documentos, atendimentos, financeiro) | Must |
| RF-4.4 | Importar capa e movimentações via DataJud | Should |
| RF-4.5 | Campo "próxima ação" livre — o que a advogada precisa lembrar sobre o caso | Must |
| RF-4.6 | Arquivar caso sem apagar histórico | Must |

### M5 — Clientes

| ID | Requisito | Prioridade |
|---|---|---|
| RF-5.1 | Cadastro: nome, CPF/CNPJ, contatos, endereço, origem da indicação | Must |
| RF-5.2 | Registro de atendimentos com data e resumo | Must |
| RF-5.3 | Botão "copiar status para o cliente" — gera texto pronto pro WhatsApp com a situação atual do caso | Should |
| RF-5.4 | Aniversário / lembrete de contato periódico | Could |

### M6 — Documentos

| ID | Requisito | Prioridade |
|---|---|---|
| RF-6.1 | Upload de arquivo e captura por câmera (multi-página → PDF único) | Must |
| RF-6.2 | Classificação por tipo (RG, CPF, comprovante de residência, CNIS, laudo, procuração, contrato) | Must |
| RF-6.3 | Checklist de documentos por tipo de caso, com % de conclusão | Should |
| RF-6.4 | Funcionar offline: foto tirada sem sinal entra na fila e sobe depois | Must |
| RF-6.5 | Compressão de imagem no cliente antes do upload | Must |

### M7 — Financeiro

| ID | Requisito | Prioridade |
|---|---|---|
| RF-7.1 | Contrato de honorários: valor, forma (fixo, parcelado, êxito, misto), % de êxito | Must |
| RF-7.2 | Geração de parcelas com vencimento; baixa manual | Must |
| RF-7.3 | Painel: a receber no mês, vencido, recebido no mês, total contratado | Must |
| RF-7.4 | Despesas por caso (custas, cópias, deslocamento) marcadas como reembolsáveis | Should |
| RF-7.5 | Provisão de imposto sugerida (% configurável sobre recebimentos) | Should |
| RF-7.6 | Lembrete de cobrança de parcela vencida | Should |

### M8 — Notificações

| ID | Requisito | Prioridade |
|---|---|---|
| RF-8.1 | Web Push (VAPID) para: nova publicação, prazo D-10/D-5/D-3/D-1, manhã do dia fatal | Must |
| RF-8.2 | Digest diário às 08:00 com o resumo da tela Hoje | Must |
| RF-8.3 | Onboarding explicando "Adicionar à Tela de Início" (obrigatório para push no iOS) | Must |
| RF-8.4 | Preferências por canal e por tipo de evento | Should |
| RF-8.5 | Fallback por e-mail quando o push falhar 3 vezes | Should |

### M9 — Conta e configurações

| ID | Requisito | Prioridade |
|---|---|---|
| RF-9.1 | Cadastro com OAB/UF e verificação de e-mail | Must |
| RF-9.2 | Aceite explícito do termo de uso com a cláusula de conferência obrigatória de prazos | Must |
| RF-9.3 | Configuração de buffer padrão, horário do digest, fuso | Must |
| RF-9.4 | Exportar todos os dados (LGPD — portabilidade) em JSON + ZIP de arquivos | Must |
| RF-9.5 | Excluir conta com carência de 30 dias | Must |

---

## 7. Integrações externas

### 7.1 DJEN / Comunica (CNJ) — gatilho de prazo

- **Custo:** gratuito, sem chave, sem cadastro.
- **Endpoint:** `https://comunicaapi.pje.jus.br/api/v1/comunicacao`
- **Parâmetros úteis:** `numeroOab`, `ufOab`, `numeroProcesso`, `siglaTribunal`, `dataDisponibilizacaoInicio`, `dataDisponibilizacaoFim`, `pagina`, `itensPorPagina`.
- **Retorno:** objeto com `count` e `items`, incluindo o teor da comunicação.
- **Armadilhas conhecidas:**
  - Datas em `America/Sao_Paulo`. Às 22h de Brasília o UTC já virou o dia seguinte → janela errada.
  - Sempre consultar **ontem + hoje**.
  - `numeroOab` é comparado como string exata; tribunais gravam `123456` e `123456-O` → daí a tabela de variações.
  - Nome com grafia divergente, abreviações e homônimos exigem revisão humana na triagem.
- **Base legal:** Resolução CNJ nº 455/2022; portal humano em `comunica.pje.jus.br`.

### 7.2 DataJud (CNJ) — enriquecimento

- **Custo:** gratuito, com Chave Pública publicada na wiki do CNJ (`datajud-wiki.cnj.jus.br/api-publica/acesso/`).
- **Auth:** header `Authorization: APIKey <chave>`.
- **Endpoint:** `https://api-publica.datajud.cnj.jus.br/api_publica_<alias-tribunal>/_search` (sintaxe Elasticsearch).
- **Entrega:** metadados de capa e movimentações. **Não** entrega inteiro teor nem serve para jurisprudência.
- **Limitações:**
  - A chave pode ser alterada pelo CNJ a qualquer momento → guardar em config editável em runtime + alerta em caso de 401.
  - O termo de uso permite limitar requisições por chave e revogar acesso sem aviso → cache agressivo e backoff obrigatórios.
  - CPF/CNPJ não são indexados nos campos pesquisáveis (LGPD); busca por nome de parte com filtragem posterior.
- **Papel no produto:** enriquecimento do caso. **Nunca** gatilho de prazo.

### 7.3 Web Push

- **Custo:** zero (VAPID self-hosted).
- **Restrição crítica:** no iOS só funciona com o PWA instalado na tela de início. O onboarding precisa forçar esse passo.

### 7.4 Não integrar no v1

Google Calendar, WhatsApp Business API, gateway de pagamento, assinatura eletrônica, peticionamento via Jus.br. Todos são v2+.

---

## 8. Motor de prazos — algoritmo e regras

### 8.1 Cadeia de cálculo

```
disponibilizacao        (data vinda do DJEN)
  ↓ próximo dia útil
publicacao              (CPC art. 224, §2º)
  ↓ próximo dia útil
inicio_contagem         (CPC art. 224, §3º)
  ↓ + N dias (úteis por padrão, art. 219), pulando feriados e suspensões
data_fatal
  ↓ − buffer (padrão 3 dias úteis)
data_alvo               ← esta é a data que aparece na agenda
```

### 8.2 Regras aplicadas

| Regra | Fonte | Tratamento |
|---|---|---|
| Prazo em dias úteis | CPC art. 219 | Padrão para processo civil e trabalhista; flag para dias corridos |
| Publicação no dia útil seguinte à disponibilização | CPC art. 224, §2º | Automático |
| Início no dia útil seguinte à publicação | CPC art. 224, §3º | Automático |
| Suspensão 20/12 a 20/01 | CPC art. 220 | Automático |
| Feriados forenses e suspensão de expediente | Portarias dos tribunais | Tabela mantida manualmente |
| Prazo em dobro | CPC arts. 180, 183, 186 | Sugerido, confirmação humana |
| Prorrogação para o dia útil seguinte quando o fatal cai em dia sem expediente | CPC art. 224, §1º | Automático |

### 8.3 Casos de teste obrigatórios (suíte do `PrazoService`)

1. Prazo simples de 15 dias úteis, sem feriado.
2. Disponibilização na sexta → publicação na segunda → início na terça.
3. Disponibilização na véspera de feriado prolongado.
4. Prazo iniciado em 15/12 que atravessa o recesso (20/12–20/01).
5. Fatal caindo em feriado municipal de Manaus.
6. Prazo em dobro para a Fazenda.
7. Prazo em dias corridos (penal).
8. Feriado cadastrado retroativamente → recálculo em lote e notificação.
9. Prazo de 5 dias que cai inteiramente dentro de uma semana com dois feriados.
10. Ajuste manual sobrescrevendo o cálculo — verifica que o log guarda os dois valores.

### 8.4 Calendário de feriados — o passivo do projeto

Não existe fonte pública unificada e confiável de feriados forenses. Estratégia:
- Tabela `feriados` com abrangência (nacional / estadual / municipal / tribunal específico).
- Carga inicial manual dos tribunais relevantes: **TJAM, TRF1, JEF-AM, TRT11**.
- Rotina de revisão trimestral + alerta ao usuário: "confira as portarias do seu tribunal".
- No v2, permitir que o próprio usuário cadastre feriado local.

---

## 9. Modelo de dados

```
advogados            id, nome, oab, uf, email, timezone, buffer_padrao, aceite_termo_em
oab_watches          id, advogado_id, termo, tipo(oab|nome), uf, ativo, ultima_sync_em
sync_logs            id, oab_watch_id, executado_em, status, qtd_itens, erro

publicacoes          id, advogado_id, hash, numero_comunicacao, tribunal, orgao,
                     numero_processo, teor, data_disponibilizacao,
                     status_triagem(nova|prazo|ciencia|descartada), processo_id?, arquivada_em

clientes             id, advogado_id, nome, documento, contatos(json), endereco(json), origem
processos            id, advogado_id, cliente_id, numero_cnj, tribunal, vara, classe,
                     assunto, fase, valor_causa, proxima_acao, arquivado_em
partes               id, processo_id, nome, tipo(autor|reu|terceiro), documento
movimentacoes        id, processo_id, data, codigo, descricao, origem(datajud|manual)

prazos               id, processo_id, publicacao_id?, tipo, dias, em_dias_uteis,
                     multiplicador, data_disponibilizacao, data_publicacao,
                     data_inicio, data_fatal, data_alvo,
                     ajustado_manualmente, justificativa, status, cumprido_em
eventos              id, advogado_id, tipo(prazo|audiencia|compromisso|tarefa),
                     eventable_type, eventable_id, titulo, inicio, fim, local, link

documentos           id, processo_id?, cliente_id?, nome, tipo, path, mime, tamanho,
                     origem(upload|camera), hash
checklists           id, processo_id, template_id, item, obrigatorio, documento_id?

honorarios           id, processo_id, tipo(fixo|parcelado|exito|misto), valor, percentual_exito
parcelas             id, honorario_id, numero, valor, vencimento, pago_em, valor_pago
despesas             id, processo_id, descricao, valor, data, reembolsavel, reembolsada_em

feriados             id, data, descricao, abrangencia(nacional|estadual|municipal|tribunal),
                     uf?, municipio?, tribunal_sigla?
notificacoes         id, advogado_id, tipo, payload(json), agendada_para, enviada_em, lida_em
push_subscriptions   id, advogado_id, endpoint, p256dh, auth, user_agent
outbox               id, advogado_id, entidade, operacao, payload(json), criado_em, sincronizado_em
auditoria            id, advogado_id, entidade, entidade_id, acao, antes(json), depois(json), em
```

**Índices críticos:** `publicacoes(advogado_id, status_triagem)`, `publicacoes(hash)` único, `prazos(advogado_id, data_alvo)`, `eventos(advogado_id, inicio)`, `feriados(data, abrangencia)`.

---

## 10. Arquitetura técnica

**Stack:** Laravel + Inertia + Vue 3, PostgreSQL, Redis (fila + cache), storage S3-compatible, Vite + Workbox para o service worker.

```
┌──────────────────────────────────────────────┐
│  PWA (Vue + Inertia)                         │
│  Service Worker · IndexedDB · Web Push       │
└───────────────┬──────────────────────────────┘
                │ HTTPS / JSON
┌───────────────▼──────────────────────────────┐
│  Laravel                                     │
│  ├─ Controllers / Inertia                    │
│  ├─ PrazoService      (puro, sem I/O)        │
│  ├─ CalendarioService (feriados)             │
│  ├─ DjenClient        (HTTP + retry)         │
│  ├─ DataJudClient     (HTTP + cache)         │
│  └─ SyncService       (outbox, conflitos)    │
└───────────────┬──────────────────────────────┘
                │
┌───────────────▼──────────────────────────────┐
│  Queue Workers + Scheduler                   │
│  06:00 SyncPublicacoesJob (por watch)        │
│  08:00 DigestDiarioJob                       │
│  a cada hora  DispararNotificacoesJob        │
│  semanal      RevisarCalendarioJob           │
└──────────────────────────────────────────────┘
```

**Offline-first:**
- Shell + rotas principais em cache (Workbox, estratégia stale-while-revalidate).
- Dados de leitura (agenda, casos, clientes) espelhados em IndexedDB.
- Escritas offline entram na tabela `outbox` local → replay ao reconectar.
- Resolução de conflito: last-write-wins em campos não críticos; **prazos e financeiro nunca resolvem sozinhos** — geram item de revisão.
- Anexos: fila separada, upload retomável, com indicador de "3 fotos aguardando envio".

**Decisões arquiteturais registradas:**
1. `PrazoService` é função pura, sem acesso a banco, para ser 100% testável. Feriados entram como argumento.
2. DJEN e DataJud são clientes separados, com circuit breaker independente. Queda do DataJud não pode derrubar a ingestão de prazos.
3. Publicações são imutáveis. Triagem cria registros novos, nunca sobrescreve.

---

## 11. Telas do v1

| # | Tela | Pergunta que responde | Elementos |
|---|---|---|---|
| 1 | **Hoje** | "O que não posso deixar passar?" | Fatais ≤ 7 dias, agenda do dia, publicações não triadas, a receber vencido |
| 2 | **Inbox de publicações** | "O que o Judiciário me mandou?" | Card por publicação, swipe → prazo / ciência / descarte |
| 3 | **Agenda** | "Como está minha semana?" | Mês + lista, cor por criticidade |
| 4 | **Caso** | "Qual a situação deste processo?" | Abas: Dados / Prazos / Documentos / Financeiro; botão câmera |
| 5 | **Clientes** | "Quem é essa pessoa e o que temos com ela?" | Lista, ficha, atendimentos, copiar status |
| 6 | **Financeiro** | "Quanto tenho a receber?" | A receber no mês, vencidos, recebidos |

**Navegação:** bottom tab bar com 4 itens (Hoje · Agenda · Casos · Mais). Publicações aparecem como badge na Hoje.

**Regras de UI:**
- Toda data de prazo mostra, ao toque, a cadeia de origem completa.
- Nada de modal com mais de dois campos no mobile — usar full screen.
- Área de toque mínima 44px; o app é usado em pé, no corredor do fórum.

---

## 12. Requisitos não funcionais

### Segurança e privacidade
- Criptografia em repouso dos campos sensíveis e dos arquivos.
- TLS obrigatório; HSTS.
- Log de acesso a documentos (quem abriu, quando).
- Sessão com expiração e revogação por dispositivo.
- Backup diário com retenção de 30 dias e teste de restauração mensal.

### LGPD
- Base legal: execução de contrato (dados do advogado) e legítimo interesse/obrigação legal (dados dos clientes tratados pelo advogado, que é o controlador — o app é **operador**).
- Contrato de operador de dados no termo de uso.
- Portabilidade (RF-9.4) e eliminação (RF-9.5).
- Registro de operações de tratamento.

### Sigilo profissional
- EOAB art. 34: dados de cliente são sigilosos. Nenhum acesso da equipe do produto a conteúdo de caso sem consentimento explícito e log.
- Se algum dia entrar IA sobre teor de processo: consentimento específico, sem treinamento com os dados, e opção de desligar.

### Publicidade (se houver site/portal público)
- Provimento OAB nº 205/2021: vedada captação, mercantilização e uso de linguagem promocional. Vale para landing page, portal do cliente e qualquer material de divulgação do app dirigido a leigos.

### Performance
- Tela Hoje em < 1,5s no 4G com cache quente.
- Bundle inicial < 250 KB gzip.
- Varredura DJEN de 5 termos em < 60s.

### Disponibilidade
- Falha de varredura é evento de severidade alta: alerta ao usuário no mesmo dia (RF-1.10).

---

## 13. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Cálculo de prazo errado → dano ao cliente do advogado | Média | **Crítico** | Suíte de testes, cadeia de origem visível, ajuste manual, termo de uso, nunca fonte única |
| Calendário de feriados desatualizado | Alta | Alto | Revisão trimestral, alerta permanente na UI, cadastro pelo usuário no v2 |
| Mudança de contrato/instabilidade da API do DJEN | Média | Alto | Cliente isolado, testes de contrato, fallback manual, monitoramento |
| Chave do DataJud alterada pelo CNJ | Alta | Baixo | Config em runtime, alerta em 401, DataJud não é crítico |
| Rate limit ou revogação de acesso pelo CNJ | Baixa | Alto | Cache, backoff, uso comedido, respeitar termo de uso |
| Push não chega no iOS | Alta | Alto | Onboarding forçando instalação; fallback e-mail |
| Escopo inflado (4 módulos "completos") | **Alta** | Alto | Matriz da seção 5 é contrato; nada entra no v1 sem sair outra coisa |
| Dev solo com tempo limitado | Alta | Alto | Fatia vertical, uso real em produção já na semana 4 |

---

## 14. Roadmap — 8 semanas

| Semana | Entrega | Critério de pronto |
|---|---|---|
| 1–2 | Auth, migrations, `PrazoService` + suíte de testes, seed de feriados TJAM/TRF1 | Os 10 casos de teste da seção 8.3 passam |
| 3–4 | `DjenClient`, job de ingestão, inbox de triagem, agenda básica | **Usuária-piloto usando em produção**, prazo real criado a partir de publicação real |
| 5 | PWA shell, offline, Web Push, onboarding de instalação | Push recebido no iOS e no Android |
| 6 | Casos, clientes, documentos com câmera, checklist | Foto tirada offline chega ao servidor após reconexão |
| 7 | Financeiro (honorários, parcelas, painel) | Painel bate com a planilha atual da piloto |
| 8 | DataJud, polimento, LGPD, termo de uso, exportação | Exportação completa funcionando; termo aceito no cadastro |

**Marco de validação:** semana 4. Se a usuária-piloto não abandonar a planilha até a semana 6, o produto está errado — parar e reavaliar antes de construir o resto.

---

## 15. Métricas de sucesso

| Métrica | Alvo v1 |
|---|---|
| Publicações triadas em até 24h | > 90% |
| Prazos criados a partir de publicação (vs. manual) | > 70% |
| Prazos perdidos | 0 |
| Abertura do app por dia útil | ≥ 1 |
| Tempo entre push e triagem | < 4h |
| Retenção da piloto após 60 dias | 100% (n=1, mas é o gate) |

---

## 16. Decisões em aberto

1. **Monetização:** freemium com limite de processos? Preço único? Definir antes da semana 6 (afeta modelagem de conta).
2. **Multi-tenant desde já?** Recomendação: sim, `advogado_id` em tudo desde a primeira migration, mesmo com um usuário. Custo baixo agora, caro depois.
3. **Nome do produto e domínio.**
4. **Estagiário como usuário secundário** — se entrar, precisa de permissão (ver mas não cumprir prazo). Fica no v3.
5. **Hospedagem:** VPS própria vs. plataforma gerenciada. Impacta custo por usuário e, portanto, o preço.
6. **Peticionamento intercorrente** via Jus.br (o portal já unifica DJEN e Domicílio Judicial Eletrônico e permite responder à comunicação) — avaliar viabilidade técnica para o v2. Seria um diferencial forte.

---

## 17. Referências

- Resolução CNJ nº 455/2022 e nº 569/2024 — DJEN e Domicílio Judicial Eletrônico.
- CNJ — Comunicações Processuais: https://www.cnj.jus.br/programas-e-acoes/processo-judicial-eletronico-pje/comunicacoes-processuais/
- Portal DJEN (consulta humana): https://comunica.pje.jus.br
- API de comunicações: `https://comunicaapi.pje.jus.br/api/v1/comunicacao`
- DataJud — Wiki e acesso: https://datajud-wiki.cnj.jus.br/api-publica/acesso/
- Termo de uso da API pública do DataJud (CNJ).
- Portaria CNJ nº 160/2020; Resolução CNJ nº 331/2020.
- CPC/2015: arts. 180, 183, 186, 219, 220, 224.
- Estatuto da OAB, art. 34; Provimento OAB nº 205/2021.
- Lei nº 13.709/2018 (LGPD).

---

*Documento vivo. Toda alteração de escopo deve atualizar a matriz da seção 5 e o roadmap da seção 14.*
