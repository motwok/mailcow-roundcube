<?php
/**
 * Mailcow SSO Plugin - Minimale Test-Version
 */
class mailcow extends rcube_plugin {
    public $task = '.*';

    function init() {
        $this->add_hook('startup', array($this, 'on_startup'));
        // $this->add_hook('authenticate', array($this, 'on_authenticate'));
        // $this->add_hook('render_page', array($this, 'on_render'));
    }

    function on_startup($args) {
        $rcmail = rcmail::get_instance();
        $this->add_texts('localization/', false);
        $this->include_stylesheet('mailcow_plugin.css');

        // Add Mailcow UI button to the taskbar
        if (!$rcmail->output->framed) {
            $this->add_button([
                'type'       => 'link',
                'href'       => '/',
                'class'      => 'mailcowui',
                'classsel'   => 'mailcowui button-selected',
                'innerclass' => 'button-inner',
                'label'      => 'mailcow.mailcowui',
                'target'     => '_top',
            ], 'taskbar');
        }
        return $args;
    }

    function on_authenticate($args) {
        error_log('MAILCOW: on_authenticate Hook aufgerufen');
        $args['valid'] = true;
        return $args;
    }

    function on_render($args) {
        error_log('MAILCOW: on_render Hook aufgerufen');
        $rcmail = rcmail::get_instance();
        $rcmail->output->add_footer('<script>console.log("MAILCOW PLUGIN AKTIV!");</script>');
        return $args;
    }
}

///**
// * GNU AFFERO GENERAL PUBLIC LICENSE
// * Version 3, 19 November 2007
// *
// * Copyright (c) 2026 Emmo "mo2000" Emminghaus mo2000 at mo2000 dot de
// *
// * This project is free software: you can redistribute it and/or modify
// * it under the terms of the GNU Affero General Public License as published by
// * the Free Software Foundation, either version 3 of the License, or
// * (at your option) any later version.
// *
// * This project is distributed in the hope that it will be useful,
// * but WITHOUT ANY WARRANTY; without even the implied warranty of
// * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// * GNU Affero General Public License for more details.
// *
// * You should have received a copy of the GNU Affero General Public License
// * along with this project. If not, see <https://www.gnu.org/licenses/>.
// *
// * SPDX-License-Identifier: AGPL-3.0-or-later
// *
// * Mailcow SSO Plugin
// * Provides SSO authentication and Mailcow admin link in Roundcube.
// */
//class mailcow extends rcube_plugin {
//    public $task = 'login|mail|settings|addressbook|calendar';
//    private $display_name = null;
//
//    public function init() {
//        // Debug: Plugin wurde geladen
//        rcube::write_log('mailcow_plugin', 'MAILCOW PLUGIN INIT CALLED');
//
//        $this->add_texts('localization/', true);
//        $this->add_hook('authenticate', array($this, 'sso_login'));
//        $this->add_hook('login_after', array($this, 'set_display_name'));
//        $this->add_hook('startup', array($this, 'check_sso_session'));
//        $this->add_hook('render_page', array($this, 'add_mailcow_link'));
//
//        rcube::write_log('mailcow_plugin', 'MAILCOW PLUGIN HOOKS REGISTERED');
//    }
//
//    public function check_sso_session($args)
//    {
//        rcube::write_log('mailcow_plugin', 'check_sso_session CALLED');
//
//        $rcmail = rcube::get_instance();
//
//        // Check if nginx auth_request passed credentials via headers
//        if (!empty($_SERVER['HTTP_X_USER']) && !empty($_SERVER['HTTP_X_AUTH'])) {
//            // Extract credentials from nginx-passed headers (bei JEDEM Request)
//            $username = $_SERVER['HTTP_X_USER'];
//
//            // Store display name for later use (bei JEDEM Request)
//            if (!empty($_SERVER['HTTP_X_DISPLAY_NAME'])) {
//                $this->display_name = $_SERVER['HTTP_X_DISPLAY_NAME'];
//            }
//
//            // Extract password from Base64 Basic Auth (bei JEDEM Request)
//            $auth_string = str_replace('Basic ', '', $_SERVER['HTTP_X_AUTH']);
//            $decoded = base64_decode($auth_string);
//            list($user, $pass) = explode(':', $decoded, 2);
//
//            // Speichere Credentials in Session für spätere Verwendung
//            $_SESSION['sso_user'] = $user;
//            $_SESSION['sso_pass'] = $pass;
//
//            // NUR Login triggern wenn User NICHT eingeloggt ist
//            if (!$rcmail->user->ID && empty($_SESSION['user_id']) && empty($_POST['_user'])) {
//                // Force Autologin über POST-Variablen-Simulation
//                $_POST['_task'] = 'login';
//                $_POST['_action'] = 'login';
//                $_POST['_user'] = $user;
//                $_POST['_pass'] = $pass;
//            }
//        }
//        return $args;
//    }
//
//    public function sso_login($args)
//    {
//        // Wenn SSO-Credentials in der Session vorhanden sind, verwende diese
//        if (!empty($_SESSION['sso_user']) && !empty($_SESSION['sso_pass'])) {
//            $args['user'] = $_SESSION['sso_user'];
//            $args['pass'] = $_SESSION['sso_pass'];
//            $args['valid'] = true;
//            $args['cookiecheck'] = false;
//
//            // Speichere Display Name in Roundcube-Argument für weitere Verarbeitung
//            if (!empty($this->display_name)) {
//                $args['user_name'] = $this->display_name;
//            }
//        }
//
//        return $args;
//    }
//
//    public function set_display_name($args)
//    {
//        // Wird nach erfolgreichem Login aufgerufen
//        if (!empty($this->display_name)) {
//            $rcmail = rcube::get_instance();
//
//            if ($rcmail->user && $rcmail->user->ID) {
//                // Hole die Standard-Identität des Benutzers
//                $identities = $rcmail->user->list_identities();
//
//                if (!empty($identities)) {
//                    $identity = $identities[0];
//
//                    // Aktualisiere nur wenn der Name noch nicht gesetzt ist oder leer ist
//                    if (empty($identity['name']) || $identity['name'] === $identity['email']) {
//                        $rcmail->user->update_identity($identity['identity_id'], array(
//                            'name' => $this->display_name
//                        ));
//                    }
//                }
//            }
//        }
//
//        return $args;
//    }
//
//    public function add_mailcow_link($args) {
//        $rcmail = rcmail::get_instance();
//
//        // Nur wenn der User eingeloggt ist
//        if (!$rcmail->user || !$rcmail->user->ID) {
//            return $args;
//        }
//
//        $title = $this->gettext('mailcow_administration');
//
//        // Get Mailcow URL from configuration
//        $mailcow_hostname = $rcmail->config->get('mailcow_link_url', getenv('MAILCOW_HOSTNAME'));
//        $mailcow_url = !empty($mailcow_hostname) ? 'https://' . $mailcow_hostname : '/';
//
//        // Button direkt in den <body> einfügen via JavaScript
//        $script = sprintf(
//            '<script type="text/javascript">
//            $(document).ready(function() {
//                if ($("#taskbar").length) {
//                    var btn = $("<a>")
//                        .attr("href", %s)
//                        .attr("target", "_blank")
//                        .attr("title", %s)
//                        .addClass("button mailcow-link")
//                        .html("<span class=\"button-inner\"><span class=\"button-content\">Mailcow</span></span>");
//                    $("#taskbar").append(btn);
//                }
//            });
//            </script>',
//            json_encode($mailcow_url),
//            json_encode($title)
//        );
//
//        $rcmail->output->add_footer($script);
//
//        return $args;
//    }
//}