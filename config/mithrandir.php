<?php

return [
    'nome' => 'Mithrandir',

    'timezone' => env('MITHRANDIR_TZ', 'America/Sao_Paulo'),

    'user_agent' => env('MITHRANDIR_USER_AGENT', 'Mithrandir/1.0 (gestao de prazos para advogados)'),

    /**
     * Versao do termo de uso. Mudar aqui obriga novo aceite (RF-9.2).
     */
    'termo_versao' => '1.0',

    'djen' => [
        'base_url' => env('DJEN_BASE_URL', 'https://comunicaapi.pje.jus.br'),
        'itens_por_pagina' => (int) env('DJEN_ITENS_POR_PAGINA', 100),

        // Janela de consulta, em dias corridos contando ate hoje.
        // RF-1.3 exige no minimo ontem + hoje; 7 dias vai alem de proposito:
        // se a varredura falhar alguns dias (radar cego), a proxima que der
        // certo cobre o buraco sozinha. A deduplicacao torna isso barato.
        'janela_dias' => (int) env('DJEN_JANELA_DIAS', 7),

        // Conta nova nao tem historico: a primeira varredura busca mais longe
        // para a carteira nao aparecer vazia no primeiro acesso.
        'janela_primeira_sync_dias' => (int) env('DJEN_JANELA_INICIAL_DIAS', 30),
        'max_paginas' => (int) env('DJEN_MAX_PAGINAS', 20),

        // Teto de sanidade por varredura. Acima disso o lote e recusado inteiro:
        // ou o nome vigiado e comum demais, ou o contrato da API mudou e o
        // filtro parou de filtrar.
        'teto_por_varredura' => (int) env('DJEN_TETO_VARREDURA', 300),
        // RF-1.8: rate limit do botao "sincronizar agora".
        'sync_manual_intervalo_min' => (int) env('DJEN_SYNC_MANUAL_MIN', 10),
    ],

    'datajud' => [
        'base_url' => env('DATAJUD_BASE_URL', 'https://api-publica.datajud.cnj.jus.br'),
        // Chave publica divulgada na wiki do CNJ. Sobrescrita em runtime pela
        // tabela `configuracoes` quando o CNJ trocar.
        'api_key' => env('DATAJUD_API_KEY', ''),
    ],

    'prazos' => [
        'buffer_padrao' => (int) env('PRAZO_BUFFER_PADRAO', 3),
        // Antecedencias de notificacao (RF-8.1).
        'alertas_dias' => [10, 5, 3, 1, 0],
    ],

    'push' => [
        'vapid_subject' => env('VAPID_SUBJECT', 'mailto:contato@mithrandir.app'),
        'vapid_public_key' => env('VAPID_PUBLIC_KEY', ''),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],

    'uploads' => [
        'max_mb' => (int) env('UPLOAD_MAX_MB', 20),
        'disk' => env('UPLOAD_DISK', 'local'),
    ],

    /**
     * Carencia da exclusao de conta (RF-9.5).
     */
    'exclusao_carencia_dias' => 30,
];
