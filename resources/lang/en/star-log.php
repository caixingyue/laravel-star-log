<?php

return [
    'route' => [
        'request' => '[:terminal] @ :ip - :method[:path] - Request:',
        'response' => 'Duration[:duration] - Memory[:memory] - :status[:path] - Response:',
    ],

    'client' => [
        'request' => ':method[:url] - Request:',
        'response' => 'Duration[:duration] - :status[:url] - Response:',
        'connection_failed' => ':method[:url] - Connection failed:reason.',
        'connection_failure_reasons' => [
            'name_resolution' => ' [name resolution failed]',
            'timeout' => ' [timed out]',
            'tls' => ' [TLS failed]',
        ],
    ],

    'query' => [
        'executed' => 'Connection[:connection] - Duration[:duration]',
    ],
];
