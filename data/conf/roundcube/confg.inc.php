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
$config = [];

// ----------------------------------
// PLUGINS
// ----------------------------------
// OIDC (SSO), Calendars, Contacts, your custom Mailcow UI link, Context menu, and Spam learning
$config['plugins'] = [
    'oidc', 
    'calendar', 
    'carddav', 
    'mailcow_link', 
    'contextmenu', 
    'markasjunk',
	'simple_smime',
	'tasklist',
	'html5_notifier',
	'managesieve',
    'zipdownload',
    'archive',
	'filesystem_attachments',
	'subscriptions_option',
	'vcard_attachments',
	'emoticons',
];

// ----------------------------------
// OIDC / OAUTH2 CONFIGURATION
// ----------------------------------
$config['oidc_provider'] = 'https://' . getenv('MAILCOW_HOSTNAME'); 
$config['oidc_client_id'] = getenv('ROUNDCUBE_CLIENT_ID');
$config['oidc_client_secret'] = getenv('ROUNDCUBE_CLIENT_SECRET');
$config['oidc_scope'] = 'openid profile email';
$config['oidc_username_claim'] = 'email';
$config['oidc_auto_redirect'] = true;

// ----------------------------------
// MAIL SERVER INBOUND/OUTBOUND AUTH
// ----------------------------------
$config['imap_auth_type'] = 'XOAUTH2';
$config['smtp_auth_type'] = 'XOAUTH2';

// ----------------------------------
// REVERSE PROXY / SUBFOLDER PATHS
// ----------------------------------
$config['assets_path'] = '/roundcube/';

// ----------------------------------
// CALDAV & CARDDAV (SOGO INTEGRATION)
// ----------------------------------
$config['calendar_driver'] = 'caldav';
$config['calendar_caldav_server'] = 'http://mailcowdockerized-sogo-mailcow-1:20000/SOGo/dav/';
$config['calendar_caldav_url'] = 'http://mailcowdockerized-sogo-mailcow-1:20000/SOGo/dav/%u/Calendar/';
$config['calendar_caldav_user'] = '%u';
$config['calendar_caldav_pass'] = '%p';

$config['carddav_presets'] = [
    'SOGo' => [
        'name'          => 'SOGo Contacts',
        'discovery_url' => 'http://mailcowdockerized-sogo-mailcow-1:20000/SOGo/dav/',
        'username'      => '%u',
        'password'      => '%p',
        'active'        => true,
        'fixed'         => ['username', 'password']
    ]
];

// ----------------------------------
// MARK AS JUNK (RSPAMD LEARNING)
// ----------------------------------
// Tell the plugin to pass the mail to a specific driver when clicked
$config['markasjunk_driver'] = 'cmd_learn';

// Use Mailcow's internal Dovecot-Sieve command to feed Rspamd spam/ham buckets
$config['markasjunk_spam_cmd'] = '/usr/bin/rspamc -h rspamd:11334 learn_spam %f';
$config['markasjunk_ham_cmd']  = '/usr/bin/rspamc -h rspamd:11334 learn_ham %f';

// Switch the tasklist driver from local DB storage to CalDAV
$config['tasklist_driver'] = 'caldav';

// SOGo endpoint path (uses the internal Docker hostname)
$config['tasklist_caldav_server'] = 'http://mailcowdockerized-sogo-mailcow-1:20000/SOGo/dav/';
$config['tasklist_caldav_url']    = 'http://mailcowdockerized-sogo-mailcow-1:20000/SOGo/dav/%u/Calendar/';
$config['tasklist_caldav_user']   = '%u';
$config['tasklist_caldav_pass']   = '%p';

// ----------------------------------
// MANAGESIEVE (MAILCOW FILTER RULES)
// ----------------------------------
// Use the internal Mailcow Dovecot container for sieve filters
$config['managesieve_host'] = 'dovecot';
$config['managesieve_port'] = 4190;

// Enable TLS for the internal connection (highly recommended)
$config['managesieve_usetls'] = true;

// Default name for the active sieve script rule set
$config['managesieve_default'] = 'roundcube';


// ----------------------------------
// IMAP FOLDER MANAGEMENT (Dovecot Sync)
// ----------------------------------
// Automatically create default IMAP folders if they do not exist
$config['create_default_folders'] = true;

// Define standard names matching Mailcow / Dovecot defaults
$config['drafts_mbox'] = 'Drafts';
$config['junk_mbox']   = 'Junk';
$config['sent_mbox']   = 'Sent';
$config['trash_mbox']  = 'Trash';

// Display system folders always at the very top of the folder list
$config['default_folders'] = ['INBOX', 'Drafts', 'Sent', 'Junk', 'Trash'];

// ----------------------------------
// CUSTOM EXTRA CONFIGURATION INTERCEPT
// ----------------------------------
// Dynamically include extra settings if the file exists in the config folder
$extra_config_file = __DIR__ . '/extra.inc.php';

if (file_exists($extra_config_file)) {
    include_inner($extra_config_file);
}

// Helper function to keep variables within the scope of the global $config array
function include_inner($file) {
    global $config;
    include($file);
}
