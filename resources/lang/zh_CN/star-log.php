<?php

return [
    'route' => [
        'request' => '[:terminal] @ :ip - :method[:path] - 请求报文:',
        'response' => '耗时[:duration] - 内存消耗[:memory] - :status[:path] - 响应报文:',
    ],

    'client' => [
        'request' => ':method[:url] - 请求报文:',
        'response' => '耗时[:duration] - :status[:url] - 响应报文:',
        'connection_failed' => ':method[:url] - 连接失败:reason。',
        'connection_failure_reasons' => [
            'name_resolution' => ' [名称解析失败]',
            'timeout' => ' [连接超时]',
            'tls' => ' [TLS 失败]',
        ],
    ],

    'query' => [
        'executed' => '连接[:connection] - 耗时[:duration]',
    ],
];
