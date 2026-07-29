#!/bin/bash
set -e

# GNU AFFERO GENERAL PUBLIC LICENSE
# Version 3, 19 November 2007
#
# Copyright (c) 2026 Emmo "mo2000" Emminghaus mo2000 at mo2000 dot de
#
# This project is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This project is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this project. If not, see <https://www.gnu.org/licenses/>.
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# 1. Determine paths dynamically and relatively
RC_DIR="$(cd "$(dirname "${BASH_SOURCE}")" && pwd)"
MAILCOW_DIR="$(dirname "$RC_DIR")"

EXTENSION_FILE_NAME="docker-compose.extension.yml"
CONF_FILE="$MAILCOW_DIR/mailcow.conf"

echo "=== Mailcow Roundcube Full Automation Installer ==="

# Check if mailcow.conf and extension file exist
if [ ! -f "$CONF_FILE" ]; then
    echo "Error: mailcow.conf not found in $MAILCOW_DIR!"
    exit 1
fi

if [ ! -f "$RC_DIR/$EXTENSION_FILE_NAME" ]; then
    echo "Error: $EXTENSION_FILE_NAME is missing in $RC_DIR!"
    exit 1
fi

# 2. Load existing configuration to get the Mailcow hostname
MAILCOW_HOSTNAME=$(grep -E "^MAILCOW_HOSTNAME=" "$CONF_FILE" | cut -d'=' -f2)
if [ -z "$MAILCOW_HOSTNAME" ]; then
    echo "Error: Could not read MAILCOW_HOSTNAME from mailcow.conf."
    exit 1
fi

ROUNDCUBE_URL="https://$MAILCOW_HOSTNAME/roundcube"
REDIRECT_URI="https://$MAILCOW_HOSTNAME/roundcube/index.php/login/oauth"

# 3. Handle Roundcube Database Password
if ! grep -q "ROUNDCUBE_DB_PASSWORD" "$CONF_FILE"; then
    echo "Generating secure database password for Roundcube..."
    RC_DB_PASS=$(openssl rand -hex 24)
    echo "ROUNDCUBE_DB_PASSWORD=$RC_DB_PASS" >> "$CONF_FILE"
else
    RC_DB_PASS=$(grep -E "^ROUNDCUBE_DB_PASSWORD=" "$CONF_FILE" | cut -d'=' -f2)
fi

# 4. Request or automatically generate OAuth2 credentials
if grep -q "ROUNDCUBE_CLIENT_ID" "$CONF_FILE"; then
    echo "OAuth2 settings already exist in mailcow.conf. Using existing data."
    CLIENT_ID=$(grep -E "^ROUNDCUBE_CLIENT_ID=" "$CONF_FILE" | cut -d'=' -f2)
    CLIENT_SECRET=$(grep -E "^ROUNDCUBE_CLIENT_SECRET=" "$CONF_FILE" | cut -d'=' -f2)
else
    echo "Please enter your OAuth2 credentials."
    echo "NOTE: Leave the fields EMPTY to automatically generate secure keys!"
    echo "Redirect URI for manual creation: $REDIRECT_URI"
    echo "--------------------------------------------------------"
    read -p "Mailcow Client ID (leave empty for auto-generation): " USER_ID
    read -p "Mailcow Client Secret (leave empty for auto-generation): " USER_SECRET
    echo "--------------------------------------------------------"

    if [ -z "$USER_ID" ] || [ -z "$USER_SECRET" ]; then
        echo "No input detected. Generating secure random keys..."
        CLIENT_ID=$(openssl rand -hex 32)
        CLIENT_SECRET=$(openssl rand -hex 32)
        AUTO_GENERATED=true
    else
        echo "Manual input accepted."
        CLIENT_ID="$USER_ID"
        CLIENT_SECRET="$USER_SECRET"
        AUTO_GENERATED=false
    fi

    # Write values to mailcow.conf
    echo "" >> "$CONF_FILE"
    echo "# Roundcube SSO Settings" >> "$CONF_FILE"
    echo "ROUNDCUBE_CLIENT_ID=$CLIENT_ID" >> "$CONF_FILE"
    echo "ROUNDCUBE_CLIENT_SECRET=$CLIENT_SECRET" >> "$CONF_FILE"
    echo "-> IDs successfully added to mailcow.conf."
fi

# 5. Inject OAuth2 Client and Create Database/User in MariaDB
cd "$MAILCOW_DIR"

if docker compose ps mysql-mailcow | grep -q "Up"; then
    echo "Configuring MariaDB database and OAuth2 client..."
    
    DB_SETUP_SQL="CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
    CREATE USER IF NOT EXISTS 'roundcube'@'%' IDENTIFIED BY '$RC_DB_PASS'; \
    GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'%'; \
    FLUSH PRIVILEGES;"
    
    docker compose exec -T mysql-mailcow mysql -u\${DBUSER} -p\${DBPASS} \${DBNAME} -e "$DB_SETUP_SQL"
    echo "-> Database 'roundcube' and user 'roundcube' verified/created."

    if [ "$AUTO_GENERATED" = true ]; then
        OAUTH_SQL="INSERT INTO oauth_clients (client_id, client_secret, redirect_uri, scope) \
        VALUES ('$CLIENT_ID', '$CLIENT_SECRET', '$REDIRECT_URI', 'profile email openid') \
        ON DUPLICATE KEY UPDATE client_id='$CLIENT_ID', client_secret='$CLIENT_SECRET', scope='profile email openid';"
        
        docker compose exec -T mysql-mailcow mysql -u\${DBUSER} -p\${DBPASS} \${DBNAME} -e "$OAUTH_SQL"
        docker compose exec -T mysql-mailcow mysql -u\${DBUSER} -p\${DBPASS} \${DBNAME} -e "$OAUTH_SQL"
        echo "-> OAuth2 client credentials injected successfully."
    fi
else
    echo "Error: Mailcow MariaDB container is not running!"
    echo "Please start Mailcow first, then rerun this script to apply DB and OAuth2 changes."
    exit 1
fi

# 7. Configure Mailcow UI App Links Override (vars.local.inc.php)
echo "Overriding Mailcow UI Apps menu shortcuts..."
VARS_PHP_PATH="$MAILCOW_DIR/data/web/inc/vars.local.inc.php"

cat << 'EOF' > "$VARS_PHP_PATH"
<?php
// Override default Mailcow App links to prioritize Roundcube Webmail
$MAILCOW_APPS = array(
    array(
        'name' => 'Roundcube Webmail',
        'link' => '/roundcube/'
    ),
    array(
        'name' => 'SOGo Groupware',
        'link' => '/SOGo/'
    )
);
EOF
echo "-> Mailcow UI links successfully modified inside vars.local.inc.php"

# 8. Adjust / set COMPOSE_FILE variable in mailcow.conf
TARGET_COMPOSE="COMPOSE_FILE=docker-compose.yml:mailcow-roundcube/$EXTENSION_FILE_NAME:docker-compose.override.yml"

if [ ! -f "docker-compose.override.yml" ]; then
    echo "services:" > "docker-compose.override.yml"
fi

if grep -q "^COMPOSE_FILE=" "$CONF_FILE"; then
    sed -i "s#^COMPOSE_FILE=.*#$TARGET_COMPOSE#" "$CONF_FILE"
else
    echo "" >> "$CONF_FILE"
    echo "# Controlled compose order for Roundcube extension" >> "$CONF_FILE"
    echo "$TARGET_COMPOSE" >> "$CONF_FILE"
fi
echo "-> COMPOSE_FILE order updated in mailcow.conf."

echo "--------------------------------------------------------"
echo "DONE! The setup has been prepared and integrated successfully."
echo "Roundcube is accessible via: $ROUNDCUBE_URL"
echo "You can now restart your Mailcow stack by running:"
echo "cd $MAILCOW_DIR && docker compose down && docker compose up -d"
