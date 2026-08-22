<?php
/**
 * GNU AFFERO GENERAL PUBLIC LICENSE
 * Version 3, 19 November 2007
 *
 * Copyright (c) 2026 Emmo "mo2000" Emminghaus mo2000 at mo2000 dot de
 *
 * This project is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This project is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this project. If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// ----------------------------------
// Reverse Proxy Settings
// ----------------------------------
// Subfolder path for Roundcube is passed as an environment variable to the container. 
//      ROUNDCUBEMAIL_REQUEST_PATH=/roundcube
$proxy_ips = [
    '127.0.0.1',
    getenv('IPV4_NETWORK') . '.0/24',
    getenv('IPV6_NETWORK') . '/64',
];
$config['proxy_whitelist'] = array_filter(array_unique($proxy_ips));
$config['request_path'] = '/roundcube';

// ----------------------------------
// Database Connection
// ----------------------------------
$db_user = getenv('ROUNDCUBEMAIL_DB_USER') ?: 'roundcube';
$db_password = getenv('ROUNDCUBEMAIL_DB_PASSWORD') ?: '';
$db_name = getenv('ROUNDCUBEMAIL_DB_NAME') ?: 'roundcube';

$config['db_dsnw'] = sprintf('mysqli://%s:%s@localhost/%s?socket=/var/run/mysqld/mysqld.sock',
    urlencode($db_user),
    urlencode($db_password),
    $db_name
);

// ----------------------------------
// Imap
// ----------------------------------
$config['imap_host'] = 'ssl://' . getenv('IPV4_NETWORK') . '.250:993';
$config['imap_auth_type'] = 'PLAIN';
$config['imap_conn_options'] = [
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
];
$config['default_folders'] = ['INBOX', 'Drafts', 'Sent', 'Junk', 'Trash'];

// ----------------------------------
// SMTP
// ----------------------------------
$config['smtp_host'] = 'tls://' . getenv('IPV4_NETWORK') . '.253:587';
$config['smtp_auth_type'] = 'PLAIN';
$config['smtp_conn_options'] = [
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
];

// ----------------------------------
// ManageSieve
// ----------------------------------
$config['managesieve_host'] = 'tcp://' . getenv('IPV4_NETWORK') . '.250:4190';
$config['managedsieve_auth_type'] = 'PLAIN';
$config['managesieve_conn_options'] = [
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
];
$config['managesieve_default'] = 'roundcube';

// ----------------------------------
// Virtual User Query
// ----------------------------------
$config['virtuser_query'] = [
    'email' => "
        SELECT a.address AS email, m.name AS name
        FROM mailcow.alias a, mailcow.mailbox m
        WHERE a.goto = m.username AND a.active = '1' AND m.active = '1'	AND m.username = '%u'
        UNION
        SELECT CONCAT( m.local_part, '/', d.alias_domain) AS email, m.name AS name
        FROM mailcow.alias_domain d, mailcow.mailbox m
        WHERE d.target_domain = m.domain AND d.active = '1' AND m.active = '1'	AND m.username = '%u';
        ",
    // 'user' => '',
    // 'host' => '',
    // 'alias' => ''
];

// ----------------------------------
// Carddav
// ----------------------------------
// see plugins/carddav/config.inc.php file
$config['address_book_type'] = '';



// ##################################
// Plugin Configurations
// ##################################

// ----------------------------------
// zipdownload
// ----------------------------------
// TODO Check Documentation for options
$config['zipdownload_default_name'] = 'emails.zip';
$config['zipdownload_max_files'] = 1000;
$config['zipdownload_max_size'] = 50000000;
$config['zipdownload_max_size_per_file'] = 50000000;

// ----------------------------------
// archive
// ----------------------------------
// TODO Check Documentation for options
// $config['archive_mbox'] = 'Archive';
// $config['archive_mbox_subfolders'] = true;
// $config['archive_mbox_subfolders_separator'] = '.';
// $config['archive_mbox_subfolders_prefix'] = 'Archive.';
// $config['archive_mbox_subfolders_suffix'] = '';
// $config['archive_mbox_subfolders_exclude'] = ['INBOX', 'Drafts', 'Sent', 'Junk', 'Trash'];
// $config['archive_mbox_subfolders_exclude_regex'] = false;
// $config['archive_mbox_subfolders_exclude_case_sensitive'] = false;
// $config['archive_mbox_subfolders_exclude_empty'] = true;
// $config['archive_mbox_subfolders_exclude_hidden'] = true;
// $config['archive_mbox_subfolders_exclude_system'] = true;
// $config['archive_mbox_subfolders_exclude_custom'] = [];

// ----------------------------------
// filesystem_attachments
// ----------------------------------
// TODO Check Documentation for options
// $config['filesystem_attachments_dir'] = '/tmp/roundcube_attachments';
// $config['filesystem_attachments_max_size'] = 50000000;
// $config['filesystem_attachments_max_files'] = 1000;
// $config['filesystem_attachments_cleanup'] = true;
// $config['filesystem_attachments_cleanup_interval'] = 3600;
// $config['filesystem_attachments_cleanup_max_age'] = 7200;
// $config['filesystem_attachments_cleanup_max_files'] = 1000;
// $config['filesystem_attachments_cleanup_max_size'] = 50000000;
// $config['filesystem_attachments_cleanup_max_size_per_file'] = 50000000;

// ----------------------------------
// subscriptions_option
// ----------------------------------
// TODO Check Documentation for options
// $config['subscriptions_option'] = 'auto';
// $config['subscriptions_auto'] = true;
// $config['subscriptions_auto_folders'] = ['INBOX', 'Drafts', 'Sent', 'Junk', 'Trash'];
// $config['subscriptions_auto_folders_exclude'] = ['INBOX', 'Drafts', 'Sent', 'Junk', 'Trash'];
// $config['subscriptions_auto_folders_exclude_regex'] = false;
// $config['subscriptions_auto_folders_exclude_case_sensitive'] = false;
// $config['subscriptions_auto_folders_exclude_empty'] = true;
// $config['subscriptions_auto_folders_exclude_hidden'] = true;
// $config['subscriptions_auto_folders_exclude_system'] = true;
// $config['subscriptions_auto_folders_exclude_custom'] = [];
// $config['subscriptions_auto_folders_exclude_custom_regex'] = false;
// $config['subscriptions_auto_folders_exclude_custom_case_sensitive'] = false;
// $config['subscriptions_auto_folders_exclude_custom_empty'] = true;
// $config['subscriptions_auto_folders_exclude_custom_hidden'] = true;
// $config['subscriptions_auto_folders_exclude_custom_system'] = true;


// ----------------------------------
// vcard_attachments
// ----------------------------------
// TODO Check Documentation for options
// $config['vcard_attachments_dir'] = '/tmp/roundcube_vcards';
// $config['vcard_attachments_max_size'] = 50000000;
// $config['vcard_attachments_max_files'] = 1000;
// $config['vcard_attachments_cleanup'] = true;
// $config['vcard_attachments_cleanup_interval'] = 3600;
// $config['vcard_attachments_cleanup_max_age'] = 7200;

// ----------------------------------
// emoticons
// ----------------------------------
// TODO Check Documentation for options
// $config['emoticons'] = [
//     'default' => [
//         'name' => 'Default',
//         'path' => '/plugins/emoticons/images/default/',
//         'url'  => '/plugins/emoticons/images/default/',
//         'active' => true
//     ]
// ];
// $config['emoticons_default'] = 'default';
// $config['emoticons_active'] = ['default'];
// $config['emoticons_order'] = ['default'];
// $config['emoticons_custom'] = [];

// ----------------------------------
// calendar
// ----------------------------------
//$config['calendar_driver'] = 'caldav';
//$config['calendar_caldav_server'] = 'http://sogo:20000/SOGo/dav/';
//$config['calendar_caldav_url'] = 'http://sogo:20000/SOGo/dav/%u/Calendar/';
//$config['calendar_caldav_user'] = '%u';
//$config['calendar_caldav_pass'] = '%p';
// TODO What about Login
// TODO Check Documentation for options
// $config['calendar_caldav_default_calendar'] = 'Calendar';
// $config['calendar_caldav_default_calendar_name'] = 'Calendar';
// $config['calendar_caldav_default_calendar_color'] = '#1E90FF';
// $config['calendar_caldav_default_calendar_timezone'] = 'UTC+2';
// $config['calendar_caldav_default_calendar_readonly'] = false;
// $config['calendar_caldav_default_calendar_shared'] = false;
// $config['calendar_caldav_default_calendar_subscribe'] = false;

// ----------------------------------
// tasklist
// ----------------------------------
// $config['tasklist_driver'] = 'caldav';
// $config['tasklist_caldav_server'] = 'http://sogo:20000/SOGo/dav/';
// $config['tasklist_caldav_url']    = 'http://sogo:20000/SOGo/dav/%u/Calendar/';
// $config['tasklist_caldav_user']   = '%u';
// $config['tasklist_caldav_pass']   = '%p';
// TODO What about Login
// TODO Check Documentation for options
// $config['tasklist_caldav_default_tasklist'] = 'Calendar';


// ----------------------------------
// contextmenu
// ----------------------------------
// TODO Check Documentation for options
// $config['contextmenu'] = [
//     'folders' => [
//         'create' => true,
//         'rename' => true,
//         'delete' => true,
//         'subscribe' => true,
//         'unsubscribe' => true,
//         'move' => true,
//         'copy' => true,
//         'markasjunk' => true,
//         'archive' => true,
//         'download' => true
//     ],
//     'messages' => [
//         'reply' => true,
//         'replyall' => true,
//         'forward' => true,
//         'delete' => true,
//         'markasjunk' => true,
//         'archive' => true,
//         'download' => true
//     ]
// ];

// ----------------------------------
// html5_notifier
// ----------------------------------
// TODO Check Documentation for options
// $config['html5_notifier'] = true;
// $config['html5_notifier_service_worker'] = true;
// $config['html5_notifier_service_worker_path'] = '/plugins/html5_notifier/service-worker