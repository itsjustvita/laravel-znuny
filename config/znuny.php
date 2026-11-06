<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    */

    'default' => env('ZNUNY_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | base_url        https://<host>/otrs/nph-genericinterface.pl/Webservice/<Name>
    | routes          A preset ("generic-ticket-connector" = the GenericTicketConnectorREST
    |                 example Znuny ships, "generic-ticket-connector-classic" = the older
    |                 development example, "operation-name" = POST /<Operation>) or an array of
    |                 "Operation" => "METHOD /Path/:Param" with an optional "extends" preset.
    |                 Unmapped operations are sent to "POST /<Operation>".
    | auth            "session" (SessionCreate + cached SessionID) or "credentials"
    |                 (login and password on every request).
    | user_type       "agent" (UserLogin) or "customer" (CustomerUserLogin).
    | auth_transport  "auto" (X-OTRS-Header-* headers for GET routes, request data
    |                 otherwise), "payload" or "headers" (headers need Znuny 6.5+).
    |
    | Optional per connection: retries, retry_delay, batch_size, search_limit (see "http")
    | and timezone (see "timezone").
    |
    */

    'connections' => [
        'default' => [
            'base_url' => env('ZNUNY_BASE_URL'),
            'username' => env('ZNUNY_USERNAME'),
            'password' => env('ZNUNY_PASSWORD'),
            'routes' => env('ZNUNY_ROUTES', 'generic-ticket-connector'),
            'auth' => env('ZNUNY_AUTH', 'session'),
            'user_type' => env('ZNUNY_USER_TYPE', 'agent'),
            'auth_transport' => env('ZNUNY_AUTH_TRANSPORT', 'auto'),
            'verify_ssl' => env('ZNUNY_VERIFY_SSL', true),
            'timeout' => env('ZNUNY_TIMEOUT', 30),
            'connect_timeout' => env('ZNUNY_CONNECT_TIMEOUT', 3),
        ],
    ],

    /*
    | Znuny's OTRSTimeZone (System Configuration), "UTC" unless an administrator changed
    | it. Search dates are sent and returned dates are read in this time zone, whatever
    | the time zone of your app is. A connection can override it with its own "timezone".
    */

    'timezone' => env('ZNUNY_TIMEZONE', 'UTC'),

    /*
    | Znuny sessions are cached and reused. per_host keeps one session per host name:
    | Znuny binds a session to the IP address that created it (SessionCheckRemoteIP), so
    | servers and workers that share a cache store but reach Znuny from different
    | addresses would otherwise invalidate each other's session with every request.
    */

    'session' => [
        'cache_store' => env('ZNUNY_CACHE_STORE'),
        'cache_prefix' => 'znuny:session:',
        'ttl' => env('ZNUNY_SESSION_TTL', 21600),
        'auto_retry_on_expired' => true,
        'per_host' => env('ZNUNY_SESSION_PER_HOST', true),
    ],

    /*
    | Caching of the lookup resources (queues, states, priorities, types, customers).
    */

    'cache' => [
        'enabled' => env('ZNUNY_CACHE_ENABLED', true),
        'store' => env('ZNUNY_CACHE_STORE'),
        'prefix' => 'znuny:lookup:',
        'ttl' => [
            'queues' => 3600,
            'priorities' => 3600,
            'states' => 3600,
            'types' => 3600,
            'customers' => 600,
        ],
    ],

    'logging' => [
        'enabled' => env('ZNUNY_LOGGING_ENABLED', true),
        'channel' => env('ZNUNY_LOG_CHANNEL', 'znuny'),
        'log_requests' => true,
        'log_responses' => false,
        'log_errors' => true,
    ],

    /*
    | Optional validation of dynamic field values before they are sent, e.g.
    | 'Segment' => ['type' => 'string', 'allowed' => ['business', 'private']],
    | Types: string, integer, float, boolean, array. Options: allowed, min, max.
    */

    'dynamic_fields' => [],

    /*
    | Reads are retried after timeouts and 5xx; writes only when no connection could be
    | established.
    |
    | search_limit: the most tickets a search without limit() may match. Znuny cuts a
    | search off at its SearchLimit (500 by default) without saying so. With a search_limit
    | the package asks for that many tickets and throws a ZnunySearchLimitExceededException
    | when more match. Without it (null) no limit is sent and Znuny's cut-off applies.
    */

    'http' => [
        'retries' => 3,
        'retry_delay' => 100,
        'batch_size' => 50,
        'search_limit' => env('ZNUNY_SEARCH_LIMIT'),
    ],

    /*
    | Inbound webhooks: POST /{route_prefix}/{event?}, sent by a Znuny requester.
    | auth.type: "token" (Authorization: Bearer <token> or <header>: <token>),
    | "basic" (HTTP Basic auth) or "none".
    */

    'webhooks' => [
        'enabled' => env('ZNUNY_WEBHOOKS_ENABLED', false),
        'route_prefix' => 'znuny/webhooks',
        'middleware' => ['api'],
        'auth' => [
            'type' => env('ZNUNY_WEBHOOK_AUTH', 'token'),
            'token' => env('ZNUNY_WEBHOOK_TOKEN'),
            'header' => 'X-Znuny-Token',
            'username' => env('ZNUNY_WEBHOOK_USERNAME'),
            'password' => env('ZNUNY_WEBHOOK_PASSWORD'),
        ],
    ],

];
