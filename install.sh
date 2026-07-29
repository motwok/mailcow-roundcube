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

RC_DIR="$(cd "$(dirname "${BASH_SOURCE}")" && pwd)"
MAILCOW_DIR="$(dirname "$RC_DIR")"

EXTENSION_FILE_NAME="docker-compose.extension.yml"
CONF_FILE="$MAILCOW_DIR/mailcow.conf"

echo "=== Mailcow Roundcube Full Automation Installer ==="
echo ""
echo "Roundcube directory: $RC_DIR"
echo "Mailcow directory: $MAILCOW_DIR"
echo ""

cd "$MAILCOW_DIR"

echo "Checking installation requirements..."

if [ ! -f "$CONF_FILE" ]; then
    echo "Error: mailcow.conf not found in $MAILCOW_DIR!"
    exit 1
fi
echo "-> mailcow.conf found"

if [ ! -f "$RC_DIR/$EXTENSION_FILE_NAME" ]; then
    echo "Error: $EXTENSION_FILE_NAME is missing in $RC_DIR!"
    exit 1
fi
echo "-> $EXTENSION_FILE_NAME found"

DBROOT=$(grep -E "^DBROOT=" "$CONF_FILE" | cut -d'=' -f2)
if [ -z "$DBROOT" ]; then
    echo "Error: DBROOT is missing in $CONF_FILE"
    exit 1
fi
echo "-> Database credentials loaded"

if ! docker compose ps mysql-mailcow | grep -q "Up"; then
    echo "Error: Mailcow MariaDB container is not running!"
    echo "Please start Mailcow first, then rerun this script to apply DB and OAuth2 changes."
    exit 1
fi
echo "-> MariaDB container is running"
echo ""

echo "Setting up Roundcube database password..."
if ! grep -q "ROUNDCUBEMAIL_DB_PASSWORD" "$CONF_FILE"; then
    echo "Generating secure database password for Roundcube..."
    RC_DB_PASS=$(openssl rand -hex 24)
    echo "# Database password for roundcube" >> "$CONF_FILE" 
    echo "ROUNDCUBEMAIL_DB_PASSWORD=$RC_DB_PASS" >> "$CONF_FILE"
    echo "-> Password generated and saved to mailcow.conf"
else
    echo "Reading existing database password from mailcow.conf..."
    RC_DB_PASS=$(grep -E "^ROUNDCUBEMAIL_DB_PASSWORD=" "$CONF_FILE" | cut -d'=' -f2)
    echo "-> Password loaded from mailcow.conf"
fi
echo ""

echo "Creating Roundcube database and user..."

DB_SETUP_SQL="CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
CREATE USER IF NOT EXISTS 'roundcube'@'%' IDENTIFIED BY '$RC_DB_PASS'; \
GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'%'; \
FLUSH PRIVILEGES;"

docker compose exec -T mysql-mailcow mysql -uroot -p${DBROOT} -e "$DB_SETUP_SQL"
echo "-> Database 'roundcube' and user 'roundcube' verified/created"
echo ""

EXTENSION_COMPOSE="mailcow-roundcube/$EXTENSION_FILE_NAME"

echo "Updating COMPOSE_FILE in mailcow.conf..."
if ! grep -q "^COMPOSE_FILE=" "$CONF_FILE"; then

    COMPOSE_ARRAY=("docker-compose.yml")

    if [ -f "docker-compose.override.yml" ]; then
        COMPOSE_ARRAY+=("docker-compose.override.yml")
    fi

    echo "" >> "$CONF_FILE"
    echo "# Compose file configuration" >> "$CONF_FILE" 
    echo "COMPOSE_FILE=$(IFS=:; echo "${COMPOSE_ARRAY[*]}")" >> "$CONF_FILE"
fi

EXISTING_COMPOSE=$(grep -E "^COMPOSE_FILE=" "$CONF_FILE" | cut -d'=' -f2)
IFS=':' read -ra COMPOSE_ARRAY <<< "$EXISTING_COMPOSE"

FOUND=false
for item in "${COMPOSE_ARRAY[@]}"; do
    if [ "$item" = "$EXTENSION_COMPOSE" ]; then
        FOUND=true
        break
    fi
done

if [ "$FOUND" = false ]; then
    echo "Adding $EXTENSION_COMPOSE to COMPOSE_FILE..."
    NEW_ARRAY=()
    LAST_IDX=$((${#COMPOSE_ARRAY[@]} - 1))

    for i in "${!COMPOSE_ARRAY[@]}"; do
        if [ $i -eq $LAST_IDX ]; then
            NEW_ARRAY+=("$EXTENSION_COMPOSE")
        fi
        NEW_ARRAY+=("${COMPOSE_ARRAY[$i]}")
    done

    COMPOSE_ARRAY=("${NEW_ARRAY[@]}")

    NEW_COMPOSE="COMPOSE_FILE=$(IFS=:; echo "${COMPOSE_ARRAY[*]}")"
    sed -i "s#^COMPOSE_FILE=.*#$NEW_COMPOSE#" "$CONF_FILE"
    echo "-> $EXTENSION_COMPOSE added to COMPOSE_FILE"
else
    echo "-> $EXTENSION_COMPOSE already present in COMPOSE_FILE"
fi
echo ""

echo "--------------------------------------------------------"
echo "Installation completed successfully!"
echo "--------------------------------------------------------"
echo ""

read -p "Do you want to restart the Mailcow stack now? (Y/n): " RESTART_CONFIRM
if [[ ! "$RESTART_CONFIRM" =~ ^[Nn]$ ]]; then
    echo ""
    echo "Restarting Mailcow stack..."
    docker compose down
    docker compose up -d
    echo "-> Mailcow stack restarted successfully"
else
    echo ""
    echo "Skipped restart. To restart manually, run:"
    echo "cd $MAILCOW_DIR && docker compose down && docker compose up -d"
fi
