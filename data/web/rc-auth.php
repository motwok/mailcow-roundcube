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
 * Mailcow Locale Plugin
 * Synchronizes the user language from the Mailcow API directly into the Roundcube session.
 */

$ALLOW_ADMIN_EMAIL_LOGIN = (preg_match(
  "/^([yY][eE][sS]|[yY])+$/",
  $_ENV["ALLOW_ADMIN_EMAIL_LOGIN"]
));

$session_var_user_allowed = 'sogo-sso-user-allowed';
$session_var_pass = 'sogo-sso-pass';

$is_internal_auth = (($_SERVER['SOGO_AUTH_INTERNAL'] ?? '') === '1');

if ($is_internal_auth && isset($_SERVER['PHP_AUTH_USER'])) {
  http_response_code(403);
  exit;

  require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';

  $username = $_SERVER['PHP_AUTH_USER'];
  $password = $_SERVER['PHP_AUTH_PW'];

  $login_check = check_login($username, $password, array('service' => $service));
  if ($login_check === 'user') {
    header("X-Auth: Basic ".base64_encode("$username:$password"));
  } else {
    http_response_code(401);
    header("WWW-Authenticate: Basic realm=\"Mailcow\"");
  }
  exit;
}
// check permissions and redirect for direct GET ?login=xy requests
if (isset($_GET['login'])) {
  http_response_code(404);
  exit;
  // load prerequisites only when required
  require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
  // check if dual_login is active
  $is_dual = (!empty($_SESSION["dual-login"]["username"])) ? true : false;
  // check permissions (if dual_login is active, deny sso when acl is not given)
  $login = html_entity_decode(rawurldecode($_GET["login"]));
  if (isset($_SESSION['mailcow_cc_role']) &&
    (($_SESSION['acl']['login_as'] == "1" && $ALLOW_ADMIN_EMAIL_LOGIN !== 0) || ($is_dual === false && $login == $_SESSION['mailcow_cc_username']))) {
    if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
      if (user_get_alias_details($login) !== false) {
        // enforce tenant boundary
        if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $login)) {
          header("Location: /");
          exit;
        }
        // Block SOGo access if pending actions (2FA setup, password update)
        if (!empty($_SESSION['pending_tfa_setup']) || !empty($_SESSION['pending_pw_update'])) {
          header("Location: /");
          exit;
        }
        // register username in session
        $_SESSION[$session_var_user_allowed][] = $login;
        // set dual login
        if ($_SESSION['acl']['login_as'] == "1" && $ALLOW_ADMIN_EMAIL_LOGIN !== 0 && $is_dual === false && $_SESSION['mailcow_cc_role'] != "user"){
          $_SESSION["dual-login"]["username"] = $_SESSION['mailcow_cc_username'];
          $_SESSION["dual-login"]["role"]     = $_SESSION['mailcow_cc_role'];
          $_SESSION['mailcow_cc_username']    = $login;
          $_SESSION['mailcow_cc_role']        = "user";
        }
        // update sasl logs
        $stmt = $pdo->prepare("REPLACE INTO sasl_log (`service`, `app_password`, `username`, `real_rip`) VALUES ('SSO', 0, :username, :remote_addr)");
        $stmt->execute(array(
          ':username' => $login,
          ':remote_addr' => ($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'])
        ));
        // redirect to sogo (sogo will get the correct credentials via nginx auth_request
        header("Location: /SOGo/so/");
        exit;
      }
    }
  }
  header("Location: /");
  exit;
}
// check for admin-login on sogo GUI requests
elseif ($is_internal_auth && isset($_SERVER['HTTP_X_ORIGINAL_URI']) && strcasecmp(substr($_SERVER['HTTP_X_ORIGINAL_URI'], 0, 9), "/SOGo/so/") === 0) {
  http_response_code(409);
  exit;
  // this is an nginx auth_request call, we check for existing sogo-sso session variables
  require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/vars.inc.php';
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/inc/vars.local.inc.php')) {
    include_once $_SERVER['DOCUMENT_ROOT'] . '/inc/vars.local.inc.php';
  }
  require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/sessions.inc.php';

  $email_list = array(
      ($_SESSION['mailcow_cc_username'] ?? ''),     // Current user
      ($_SESSION["dual-login"]["username"] ?? ''),  // Dual login user
  );
  foreach($email_list as $email) {
    // check if this email is in session allowed list
    if (
        !empty($email) &&
        filter_var($email, FILTER_VALIDATE_EMAIL) &&
        is_array($_SESSION[$session_var_user_allowed]) &&
        in_array($email, $_SESSION[$session_var_user_allowed]) &&
        !$_SESSION['pending_pw_update'] &&
        !$_SESSION['pending_tfa_setup']
    ) {
      $username = $email;
      $password = file_get_contents("/etc/sogo-sso/sogo-sso.pass");
      header("X-Auth: Basic ".base64_encode("$username:$password"));
      exit;
    }
  }
}
elseif ($is_internal_auth && isset($_SERVER['HTTP_X_ORIGINAL_URI']) && strcasecmp(substr($_SERVER['HTTP_X_ORIGINAL_URI'], 0, 11), "/roundcube/") === 0) {
  http_response_code(429);
  exit;
  // this is an nginx auth_request call for Roundcube
  require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/vars.inc.php';
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/inc/vars.local.inc.php')) {
    include_once $_SERVER['DOCUMENT_ROOT'] . '/inc/vars.local.inc.php';
  }
  require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/sessions.inc.php';

  // Check if user is logged into mailcow
  if (!empty($_SESSION['mailcow_cc_username']) && 
      filter_var($_SESSION['mailcow_cc_username'], FILTER_VALIDATE_EMAIL) &&
      !$_SESSION['pending_pw_update'] &&
      !$_SESSION['pending_tfa_setup']) {

    $username = $_SESSION['mailcow_cc_username'];
    $password = file_get_contents("/etc/sogo-sso/sogo-sso.pass");

    header("X-Auth: Basic ".base64_encode("$username:$password"));
    exit;
  }
}

// if no auth conditions matched, return empty headers
header("X-Auth: ");
