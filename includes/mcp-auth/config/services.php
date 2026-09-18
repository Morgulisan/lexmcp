<?php
// Public deployment configuration. These SHA-256 verifiers are NOT gateway secrets.
// Private overrides can be supplied through MCP_AUTH_CONFIG.
return [
    'public_url'=>'https://auth.mopoliti.de',
    'services'=>[
        'whtspp'=>[
            'name'=>'WhatsApp','resource'=>'https://whtspp.mopoliti.de/mcp',
            'gateway_client_id'=>'gateway-whtspp',
            'gateway_secret_hash'=>'abdb91047f801081d87ab3db811c460bb3e067f9aca301c68f694b16392b736a',
        ],
        'obsdn'=>[
            'name'=>'Obsidian','resource'=>'https://obsdn.mopoliti.de/mcp',
            'gateway_client_id'=>'gateway-obsdn',
            'gateway_secret_hash'=>'46785c947018e03795c022de993cd88d3c4b812773d1e13505e88077316674a3',
        ],
    ],
];
