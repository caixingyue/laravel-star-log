<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routing Configuration
    |--------------------------------------------------------------------------
    |
    | Below you can configure the routing request log manager related parameters as needed.
    |
    | response_head_id: If true, the request ID will be added to the response header.
    | except: The URIs that should be excluded from LOG record.
    | except_method: The method that should be excluded from LOG record.
    | secret_fields: The field should be replaced by "******" from the LOG
    |
    */

    'route' => [
        'response_head_id' => env('STAR_LOG_RESPONSE_HEAD_ID', false),

        'except' => [
            //
        ],

        'except_method' => [
            //
        ],

        'secret_fields' => [
            'current_password',
            'password',
            'password_confirmation',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    |
    | Below you can configure the relevant parameters of the HTTP client request as needed.
    |
    | enable: If false, each http request and response result log will no longer be automatically recorded.
    | secret_fields: The field should be replaced by "******" from the LOG
    |
    */

    'http' => [
        'enable' => env('STAR_LOG_ENABLE_HTTP_CLIENT', false),

        'secret_fields' => [
            'current_password',
            'password',
            'password_confirmation',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Query Configuration
    |--------------------------------------------------------------------------
    |
    | Below you can configure the relevant parameters of the SQL query log as needed.
    |
    | enable: If false, each sql query log will no longer be automatically recorded.
    | except: The URIs that should be excluded from LOG record.
    | except.*: This option will exclude all SQL, not limited to a certain class.
    |
    */

    'query' => [
        'enable' => env('STAR_LOG_ENABLE_SQL_QUERY', false),

        'except' => [
            '*' => [
                'insert into sessions',
                'insert into cache',
                'insert into cache_locks',
                'insert into jobs',
                'insert into job_batches',
                'insert into failed_jobs',

                'delete from sessions',
                'delete from cache',
                'delete from cache_locks',
                'delete from jobs',
                'delete from job_batches',
                'delete from failed_jobs',

                'update sessions',
                'update cache',
                'update cache_locks',
                'update jobs',
                'update job_batches',
                'update failed_jobs',

                'select * from sessions',
                'select * from cache',
                'select * from cache_locks',
                'select * from jobs',
                'select * from job_batches',
                'select * from failed_jobs',
            ],
        ],
    ],

];
