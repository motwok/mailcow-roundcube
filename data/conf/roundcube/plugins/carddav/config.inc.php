<?php

$prefs['_GLOBAL']['fixed'] = true;

// $prefs['_GLOBAL']['hide_preferences'] = true;

// $prefs['_GLOBAL']['pwstore_scheme'] = 'encrypted';

$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::WARNING;
$prefs['_GLOBAL']['loglevel_http'] = \Psr\Log\LogLevel::ERROR;

$prefs['_GLOBAL']['collected_recipients'] = [
    'preset'  => 'Personal',
    'matchurl' => '#http://nginx/SOGo/dav/%u/Contacts/collected/#',
];
$prefs['_GLOBAL']['collected_senders'] = [
    'preset'  => 'Personal',
    'matchurl' => '#http://nginx/SOGo/dav/%u/Contacts/collected/#',
];
$prefs['_GLOBAL']['default_addressbook'] = [
    'preset'  => 'Personal',
    // 'matchname' => '/collected recipients/i',
    'matchurl' => '#https://nginx/SOGo/dav/%u/Contacts/personal/#',
];

$prefs['Personal'] = [
    'accountname'         =>  'Personal',
    'username'     =>  '%u',
    'password'     =>  '%p',
    'discovery_url'          =>  'http://nginx/SOGo/dav/',
    'rediscover_time' => '00:10:00',
    'hide' => false,
    'preemptive_basic_auth' => true,
    // disable verification of SSL certificate presented by CardDAV server
    'ssl_noverify' => true,

    'name'         => '%N',
    'active'       =>  true,
    //'readonly'     =>  true,
    'refresh_time' => '00:00:30',
    'use_categories' => false,
    // 'fixed'        =>  [ < 0 or more of the other attribute keys > ],
    // 'require_always_email' => true,

    // optional: manually add (non-discoverable) addressbooks
    'extra_addressbooks' =>  [
        // first manually-added addressbook
        [
            'url'          =>  'http://nginx/SOGo/dav/%u/Contacts/collected/',
            'name'         => '%N',
            'active'       =>  true,
            //'readonly'     =>  true,
            'refresh_time' => '00:00:30',
            'use_categories' => false,
            // 'fixed'        =>  [ < 0 or more of the other attribute keys > ],
            // 'require_always' => ['email'],
        ],
    ],
];
