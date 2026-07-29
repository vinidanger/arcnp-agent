<?php

return [
    /*
     * Identificador deste servidor no Painel (definido no pareamento).
     * Também é o segmento {agentUuid} usado nas URLs de callback/heartbeat.
     */
    'server_id' => env('AGENT_SERVER_ID'),

    /*
     * Segredo compartilhado com o Painel usado para assinar (HMAC-SHA256)
     * e verificar requisições em ambas as direções.
     */
    'shared_secret' => env('AGENT_SHARED_SECRET'),

    /*
     * Janela de tolerância (segundos) entre o timestamp assinado na
     * requisição e o relógio local, para mitigar replay attacks.
     */
    'timestamp_tolerance' => (int) env('AGENT_TIMESTAMP_TOLERANCE', 30),

    /*
     * URL base do Painel. Callback de job e heartbeat são construídos
     * a partir daqui: {base}/agent-webhooks/{server_id}/callback e
     * {base}/agent-webhooks/{server_id}/heartbeat.
     */
    'panel_base_url' => rtrim((string) env('AGENT_PANEL_BASE_URL', ''), '/'),
];
