<?php
// Copy outside the webroot to /data/mcp-auth/config.php. Do not commit the real file.
return [
    'public_url'=>'https://auth.mopoliti.de',
    'services'=>[
        'whtspp'=>[
            'name'=>'WhatsApp',
            'resource'=>'https://whtspp.mopoliti.de/mcp',
            'gateway_client_id'=>'gateway-whtspp',
            'gateway_secret_hash'=>'REPLACE_WITH_SHA256_OF_RANDOM_GATEWAY_SECRET',
            // Omit allowed_user_ids for self-service by existing users; [] blocks everyone.
        ],
        'obsdn'=>[
            'name'=>'Obsidian',
            'resource'=>'https://obsdn.mopoliti.de/mcp',
            'gateway_client_id'=>'gateway-obsdn',
            'gateway_secret_hash'=>'REPLACE_WITH_SHA256_OF_RANDOM_GATEWAY_SECRET',
            // Optional: 'allowed_user_ids'=>[123],
        ],
    ],
];
