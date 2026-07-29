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

# Determine paths dynamically and relatively
RC_DIR="$(cd "$(dirname "${BASH_SOURCE}")" && pwd)"
MAILCOW_DIR="$(dirname "$RC_DIR")"

CONF_FILE="$MAILCOW_DIR/mailcow.conf"
NGINX_CONF_PATH="$MAILCOW_DIR/data/conf/nginx/custom_locations.conf"
VARS_PHP_PATH="$MAILCOW_DIR/data/web/inc/vars.local.inc.php"

echo "=== Mailcow Roundcube Extension Uninstaller ==="
read -p "Are you sure you want to completely remove Roundcube? (y/N): " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo "Uninstallation cancelled."
    exit 0
fi

# 1. Stop and remove the Roundcube container
echo "Stopping Roundcube containers..."
cd "$MAILCOW_DIR"
docker compose down

# 2. Clean up mailcow.conf (Remove variables & reset COMPOSE_FILE)
echo "Cleaning up mailcow.conf..."
if [ -f "$CONF_FILE" ]; then
    # Remove our custom variables
    sed -i '/# Roundcube SSO Settings/d' "$CONF_FILE"
    sed -i '/ROUNDCUBE_CLIENT_ID=/d' "$CONF_FILE"
    sed -i '/ROUNDCUBE_CLIENT_SECRET=/d' "$CONF_FILE"
    sed -i '/ROUNDCUBE_DB_PASSWORD=/d' "$CONF_FILE"
    sed -i '/# Controlled compose order for Roundcube extension/d' "$CONF_FILE"
    
    # Reset COMPOSE_FILE back to standard default
    if grep -q "^COMPOSE_FILE=" "$CONF_FILE"; then
        sed -i 's/^COMPOSE_FILE=.*/COMPOSE_FILE=docker-compose.yml/' "$CONF_FILE"
    fi
fi

# 4. Remove Mailcow UI Apps menu shortcuts override
echo "Removing Mailcow UI Apps shortcuts..."
if [ -f "$VARS_PHP_PATH" ]; then
    rm "$VARS_PHP_PATH"
fi

# 5. Optional: Clean Database and OAuth2 credentials inside MariaDB
read -p "Do you also want to delete the Roundcube database and OAuth2 client from MariaDB? (y/N): " DB_CONFIRM
if [[ "$DB_CONFIRM" =~ ^[Yy]$ ]]; then
    echo "Purging MariaDB tables and OAuth2 clients..."
    if docker compose ps mysql-mailcow | grep -q "Up"; then
        CLIENT_ID=$(grep -E "^ROUNDCUBE_CLIENT_ID=" "$CONF_FILE" | cut -d'=' -f2 || true)
        
        # SQL to drop database, user and oauth entry
        DROP_SQL="DROP DATABASE IF EXISTS roundcube; DROP USER IF EXISTS 'roundcube'@'%';"
        if [ -n "$CLIENT_ID" ]; then
            DROP_SQL="$DROP_SQL DELETE FROM oauth_clients WHERE client_id='$CLIENT_ID';"
        fi
        
        docker compose exec -T mysql-mailcow mysql -u\${DBUSER} -p\${DBPASS} \${DBNAME} -e "$DROP_SQL"
        echo "-> Database components successfully purged."
    else
        echo "Warning: MariaDB container not running. Could not purge SQL tables dynamically."
    fi
fi

# 6. Restart the remaining Mailcow stack to apply changes
echo "Restarting Mailcow stack to clean up routing..."
docker compose up -d

echo "--------------------------------------------------------"
echo "SET UP REMOVED! The extension has been uninstalled."
echo "You can now safely delete this directory: rm -rf $RC_DIR"
