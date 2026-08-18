<?php
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

class mailcow extends rcube_plugin {

    private $redirect_query;

    function init() {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('login_after', [$this, 'login_after']);
        $this->add_hook('logout_after', [$this, 'logout_after']);
    }

    function startup($args) {
        $rcmail = rcmail::get_instance();

        // Add Mailcow UI button to the taskbar
        if (!$rcmail->output->framed) {
            $this->include_stylesheet('mailcow.css');
            $this->add_texts('localization/', false);
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

        $rcmail->add_shutdown_function(['mailcow', 'shutdown']);

        if (empty($_SESSION['user_id'])) {
            $args['action'] = 'login';
            $this->redirect_query = $_SERVER['QUERY_STRING'];
        }
        elseif( !empty($_SERVER['HTTP_X_AUTH'])) {
            $xauth = $_SERVER['HTTP_X_AUTH'];
            $xauth = str_replace('Basic ', '', $xauth);
            $decoded = base64_decode($xauth);
            list($user, $pass) = explode(':', $decoded, 2);

            if( !empty($_SESSION['user_id']) && $_SESSION['user_id'] !== $user ) {
                $args['action'] = 'login';
                $this->redirect_query = $_SERVER['QUERY_STRING'];
            }
            else {
                $_SESSION['password'] = $rcmail->encrypt($pass);
            }
        }

        return $args;
    }

    function authenticate($args) {
        if (!empty($_SERVER['HTTP_X_AUTH'])) {
            $xauth = $_SERVER['HTTP_X_AUTH'];
            $xauth = str_replace('Basic ', '', $xauth);
            $decoded = base64_decode($xauth);
            list($user, $pass) = explode(':', $decoded, 2);

            $args['user'] = $user;
            $args['pass'] = $pass;

            $args['cookiecheck'] = false;
            $args['valid'] = true;

            return $args;
        }
        header('Location: /');
        exit;
    }

    public function login_after($args)
    {
        if ($this->redirect_query) {
            header('Location: ./?' . $this->redirect_query);
            exit;
        }

        return $args;
    }

    public function logout_after($args)
    {
        $hasLocation = false;
        if (!empty($_SERVER['HTTP_X_AUTH'])) {

            $cookies = [];
            foreach ($_COOKIE as $name => $value) {
                $cookies[] = "$name=" . urlencode($value);
            }
            $cookieHeader = implode('; ', $cookies);

            $ch = curl_init('http://nginx/');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 'logout=1');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Cookie: ' . $cookieHeader
            ]);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);            curl_close($ch);

            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            http_response_code($statusCode);
            foreach (explode("\r\n", $headers) as $header) {
                if (stripos($header, 'Location:') === 0) {
                    $hasLocation = true;
                    break;
                }
                header($header, false);
            }
            echo $body;
        }

        rcmail::get_instance()->kill_session();

        if (!$hasLocation) {
            header('Location: /');
            exit;
        }
    }

    public static function shutdown()
    {
        //rcmail::get_instance()->session->remove('password');
    }
}