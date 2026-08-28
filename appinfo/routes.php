<?php
return [
    'routes' => [
        // User-facing app launcher page
        ['name' => 'page#index',            'url' => '/',                             'verb' => 'GET'],

        // Admin settings API (All consolidated to POST to prevent Webserver PUT/DELETE blocks and routing issues)
        ['name' => 'settings#createSp',     'url' => '/settings/sp',                  'verb' => 'POST'],
        ['name' => 'settings#updateSp',     'url' => '/settings/sp/update',           'verb' => 'POST'],
        ['name' => 'settings#deleteSp',     'url' => '/settings/sp/delete',           'verb' => 'POST'],
        ['name' => 'settings#generateCert', 'url' => '/settings/certificate',         'verb' => 'POST'],

        // SAML protocol endpoints
        ['name' => 'saml#metadata',         'url' => '/saml/metadata',                'verb' => 'GET'],
        ['name' => 'saml#sso',              'url' => '/saml/sso',                     'verb' => ['GET', 'POST']],
        ['name' => 'saml#idpInitiated',     'url' => '/saml/login/{spId}',            'verb' => 'GET'],
        ['name' => 'saml#confirmIdpInitiated', 'url' => '/saml/login/{spId}/confirm',   'verb' => 'POST'],
    ],
];
