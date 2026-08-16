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

echo "=== Mailcow Roundcube Extension Uninstaller ==="
echo ""
echo "Roundcube directory: $RC_DIR"
echo "Mailcow directory: $MAILCOW_DIR"
echo ""

cd "$MAILCOW_DIR"

echo "Checking uninstallation requirements..."

if [ ! -f "$CONF_FILE" ]; then
    echo "Warning: mailcow.conf not found in $MAILCOW_DIR"
    exit 1
fi
echo "-> mailcow.conf found"

DBROOT=$(grep -E "^DBROOT=" "$CONF_FILE" | cut -d'=' -f2)
if [ -z "$DBROOT" ]; then
    echo "Error: DBROOT is missing in $CONF_FILE"
    exit 1
fi
echo "-> Database credentials loaded"

if ! docker compose ps mysql-mailcow | grep -q "Up"; then
    echo "Error: Mailcow MariaDB container is not running!"
    echo "Please start Mailcow first, then rerun this script to apply DB changes."
    exit 1
fi
echo "-> MariaDB container is running"
echo ""

read -p "Do you want to delete the Roundcube database and database user? (y/N): " DB_CONFIRM
echo ""

echo "Cleaning up mailcow.conf..."

if grep -q "^COMPOSE_FILE=" "$CONF_FILE"; then
    EXTENSION_COMPOSE="mailcow-roundcube/$EXTENSION_FILE_NAME"
    EXISTING_COMPOSE=$(grep -E "^COMPOSE_FILE=" "$CONF_FILE" | cut -d'=' -f2)
    IFS=':' read -ra COMPOSE_ARRAY <<< "$EXISTING_COMPOSE"

    NEW_ARRAY=()
    for item in "${COMPOSE_ARRAY[@]}"; do
        if [ "$item" != "$EXTENSION_COMPOSE" ]; then
            NEW_ARRAY+=("$item")
        fi
    done

    if [ ${#NEW_ARRAY[@]} -eq 0 ]; then
        sed -i '/^COMPOSE_FILE=.*/d' "$CONF_FILE"
        sed -i '/# Compose file configuration/d' "$CONF_FILE"
        echo "-> COMPOSE_FILE removed (was empty)"
    elif [ ${#NEW_ARRAY[@]} -eq 1 ] && [ "${NEW_ARRAY[0]}" = "docker-compose.yml" ]; then
        sed -i '/# Compose file configuration/d' "$CONF_FILE"
        sed -i '/^COMPOSE_FILE=.*/d' "$CONF_FILE"
        echo "-> COMPOSE_FILE removed (only default files remain)"
    elif [ ${#NEW_ARRAY[@]} -eq 2 ] && [ "${NEW_ARRAY[0]}" = "docker-compose.yml" ] && [ "${NEW_ARRAY[1]}" = "docker-compose.override.yml" ]; then
        sed -i '/# Compose file configuration/d' "$CONF_FILE"
        sed -i '/^COMPOSE_FILE=.*/d' "$CONF_FILE"
        echo "-> COMPOSE_FILE removed (only default files remain)"
    else
        NEW_COMPOSE="COMPOSE_FILE=$(IFS=:; echo "${NEW_ARRAY[*]}")"
        sed -i "s#^COMPOSE_FILE=.*#$NEW_COMPOSE#" "$CONF_FILE"
        echo "-> $EXTENSION_COMPOSE removed from COMPOSE_FILE"
    fi
else
    echo "-> COMPOSE_FILE not found in mailcow.conf"
fi
echo ""

if [[ "$DB_CONFIRM" =~ ^[Yy]$ ]]; then

    echo "Stopping Roundcube container..."
    docker compose stop roundcube 2>/dev/null || true
    echo "-> Roundcube container stopped"

    echo "Deleting Roundcube database and user..."
    DROP_SQL="DROP DATABASE IF EXISTS roundcube; DROP USER IF EXISTS 'roundcube'@'%'; FLUSH PRIVILEGES;"
    docker compose exec -T mysql-mailcow mysql -uroot -p${DBROOT} -e "$DROP_SQL"

    echo "-> Roundcube database and user deleted"
    sed -i '/# Database password for roundcube/d' "$CONF_FILE"
    sed -i '/ROUNDCUBEMAIL_DB_PASSWORD=/d' "$CONF_FILE"
    echo "-> ROUNDCUBEMAIL_DB_PASSWORD removed"
    echo ""
fi

if [ -d "$RC_DIR/data/hooks" ]; then
    echo "Removing hooks from mailcow data directory..."

    find "$RC_DIR/data/hooks" -type f | while read -r SOURCE_PATH; do
        REL_PATH="${SOURCE_PATH#$RC_DIR/data/hooks/}"
        TARGET_PATH="$MAILCOW_DIR/data/hooks/$REL_PATH"

        if [ -f "$TARGET_PATH" ]; then
            rm -f "$TARGET_PATH"
        fi
    done

    echo "-> Hooks removed"
    echo ""
fi

echo "--------------------------------------------------------"
echo "Uninstallation completed successfully!"
echo "--------------------------------------------------------"
echo ""
read -p "Do you want to restart the Mailcow stack now? (Y/n): " RESTART_CONFIRM
if [[ ! "$RESTART_CONFIRM" =~ ^[Nn]$ ]]; then
    echo ""
    echo "Restarting Mailcow stack..."
    docker compose down
    docker compose up -d
    echo "-> Mailcow stack restarted successfully"
    echo ""
    echo "You can now safely delete the extension directory:"
    echo "rm -rf $RC_DIR"
else
    echo ""
    echo "Skipped restart. To restart manually, run:"
    echo "cd $MAILCOW_DIR && docker compose down && docker compose up -d"
    echo ""
    echo "After restarting you can now safely delete the extension directory:"
    echo "rm -rf $RC_DIR"
fi
