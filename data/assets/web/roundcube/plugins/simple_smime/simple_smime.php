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
 *
 * Mailcow S/MIME Server-Side Signing Plugin
 * Transparently signs outgoing emails using user certificates stored in MariaDB.
 * Automatically initializes its database table upon the first run.
 */
class mailcow_smime extends rcube_plugin
{
    public $task = 'settings|mail';

    public function init()
    {
        $rcmail = rcmail::get_instance();

        // 1. Settings UI Hooks
        if ($rcmail->task == 'settings') {
            $this->add_hook('preferences_sections_list', array($this, 'settings_section'));
            $this->add_hook('preferences_list', array($this, 'settings_form'));
            $this->add_hook('preferences_save', array($this, 'settings_save'));
        }

        // 2. Mail Delivery Hook (The "Sexy" Server-Side Signing Part)
        if ($rcmail->task == 'mail') {
            $this->add_hook('message_before_send', array($this, 'sign_message'));
        }
    }

    /**
     * Automatically creates the custom S/MIME table if it does not exist yet.
     */
    private function init_db_table($db)
    {
        // Simple DDL query running safe IF NOT EXISTS parameters
        $sql = "CREATE TABLE IF NOT EXISTS roundcube_smime (
            user_id INT(11) NOT NULL PRIMARY KEY,
            cert_data LONGTEXT NOT NULL,
            pkey_data LONGTEXT NOT NULL,
            updated DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $db->query($sql);
    }

    // Add a new section in Roundcube settings menu
    public function settings_section($args)
    {
        $args['list']['smime'] = array(
            'id' => 'smime',
            'section' => 'S/MIME Cryptography'
        );
        return $args;
    }

    // Render the upload form inside the new settings section
    public function settings_form($args)
    {
        if ($args['section'] != 'smime') {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        
        // Ensure the table exists when the user opens the settings tab
        $this->init_db_table($rcmail->get_dbh());

        $args['blocks']['smime_block'] = array(
            'name' => 'Upload S/MIME Certificate (.p12 / .pfx)'
        );

        // Check if a certificate is already stored
        $has_cert = $this->has_stored_cert($rcmail->user->ID);
        $status_text = $has_cert 
            ? '<span style="color: green; font-weight: bold;">✔ Active S/MIME Certificate Stored</span>' 
            : '<span style="color: red;">No certificate uploaded yet.</span>';

        $args['blocks']['smime_block']['options']['status'] = array(
            'title' => 'Current Status',
            'content' => $status_text
        );

        $args['blocks']['smime_block']['options']['p12_file'] = array(
            'title' => 'Certificate File',
            'content' => '<input type="file" name="_smime_p12" accept=".p12,.pfx" />'
        );

        $args['blocks']['smime_block']['options']['p12_pass'] = array(
            'title' => 'Certificate Password',
            'content' => '<input type="password" name="_smime_pass" autocomplete="off" />'
        );

        // Add multipart attribute to the main form so file uploads work
        $rcmail->output->add_gui_object('preferencesform', 'form');

        return $args;
    }

    // Process and save the uploaded certificate
    public function settings_save($args)
    {
        if ($args['section'] != 'smime') {
            return $args;
        }

        if (empty($_FILES['_smime_p12']['tmp_name']) || empty($_POST['_smime_pass'])) {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        $db = $rcmail->get_dbh();
        
        // Safety call to make sure the table exists before attempting a query
        $this->init_db_table($db);

        $p12_content = file_get_contents($_FILES['_smime_p12']['tmp_name']);
        $p12_password = $_POST['_smime_pass'];

        // Try to parse the PKCS12 file using PHP OpenSSL
        $certs = [];
        if (openssl_pkcs12_read($p12_content, $certs, $p12_password)) {
            $private_key = $certs['pkey'];
            $public_cert = $certs['cert'];

            // Protect the private key at rest using the user's active session password
            $session_password = $rcmail->decrypt($_SESSION['password']);
            $encrypted_pkey = $this->encrypt_aes($private_key, $session_password);

            // Store everything in the custom database table
            $db->query(
                "INSERT INTO roundcube_smime (user_id, cert_data, pkey_data, updated) 
                 VALUES (?, ?, ?, NOW()) 
                 ON DUPLICATE KEY UPDATE cert_data=?, pkey_data=?, updated=NOW();",
                $rcmail->user->ID, $public_cert, $encrypted_pkey, $public_cert, $encrypted_pkey
            );

            $rcmail->output->show_message('S/MIME certificate successfully stored and activated!', 'confirmation');
        } else {
            $rcmail->output->show_message('Failed to parse certificate. Wrong password or invalid file.', 'error');
        }

        return $args;
    }

    // Intercept the mail right before transmission and apply the digital signature
    public function sign_message($args)
    {
        $rcmail = rcmail::get_instance();
        $db = $rcmail->get_dbh();

        // Safety initialization check for the DB table on mail send hook
        $this->init_db_table($db);

        // Fetch data from database
        $res = $db->query("SELECT cert_data, pkey_data FROM roundcube_smime WHERE user_id = ?;", $rcmail->user->ID);
        if (!$res || !($row = $db->fetch_assoc($res))) {
            return $args; // No cert found, send unsigned plain mail
        }

        // Decrypt the private key back into RAM using the active session password
        $session_password = $rcmail->decrypt($_SESSION['password']);
        $private_key = $this->decrypt_aes($row['pkey_data'], $session_password);
        $public_cert = $row['cert_data'];

        // Temporary file paths needed by openssl_pkcs7_sign
        $tmp_in = tempnam(sys_get_temp_dir(), 'rcin_');
        $tmp_out = tempnam(sys_get_temp_dir(), 'rcout_');

        // Write raw unencrypted email content to temporary file
        file_put_contents($tmp_in, $args['message']->get_mime_message());

        // Perform the cryptographic server-side signing
        if (openssl_pkcs7_sign($tmp_in, $tmp_out, $public_cert, $private_key, array())) {
            // Replace the standard plain body with the freshly generated S/MIME body
            $signed_mime = file_get_contents($tmp_out);
            $args['message']->set_mime_message($signed_mime);
        }

        // Securely erase disk footprints
        @unlink($tmp_in);
        @unlink($tmp_out);

        return $args;
    }

    // Helper: Verify if user has a certificate
    private function has_stored_cert($user_id) {
        $db = rcmail::get_instance()->get_dbh();
        $res = $db->query("SELECT user_id FROM roundcube_smime WHERE user_id = ?;", $user_id);
        return ($res && $db->fetch_assoc($res)) ? true : false;
    }

    // Helper: AES-256-CBC Encryption
    private function encrypt_aes($data, $password) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', hash('sha256', $password, true), 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    // Helper: AES-256-CBC Decryption
    private function decrypt_aes($data, $password) {
        $data = base64_decode($data);
        $iv_len = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $iv_len);
        $encrypted = substr($data, $iv_len);
        return openssl_decrypt($encrypted, 'aes-256-cbc', hash('sha256', $password, true), 0, $iv);
    }
}
