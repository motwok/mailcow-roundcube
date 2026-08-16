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

echo "=== Mailcow Roundcube Extension Updater ==="
echo ""
echo "Roundcube directory: $RC_DIR"
echo "Mailcow directory: $MAILCOW_DIR"
echo ""

cd "$RC_DIR"

if [ -d "$RC_DIR/.git" ]; then
    echo "Pulling newest extension files from Git..."
    git pull
    echo "-> Extension files updated"
else
    echo "Note: mailcow-roundcube is not a git repository"
    echo "-> Skipping git pull"
fi
echo ""

# remove when set in git repository
shopt -s globstar
chmod +x **/*.sh

cd "$MAILCOW_DIR"

if [ -d "$RC_DIR/data/hooks" ]; then
    echo "Removing hooks from mailcow data directory..."

    find "$RC_DIR/data/hooks" -type f | while read -r SOURCE_PATH; do
        REL_PATH="${SOURCE_PATH#$RC_DIR/data/hooks/}"
        TARGET_PATH="$MAILCOW_DIR/data/hooks/$REL_PATH"

        echo "  Checking: $TARGET_PATH"
        if [ -f "$TARGET_PATH" ]; then
            rm -f "$TARGET_PATH"
            echo "  -> Removed: $TARGET_PATH"
        else
            echo "  -> Not found: $TARGET_PATH"
        fi
    done

    echo "-> Hooks removal completed"
    echo ""
fi

echo "Checking if Roundcube container is configured..."
if docker compose config --services | grep -q "^roundcube$"; then
    echo "-> Roundcube container found"
    echo ""
    echo "Pulling newest Roundcube Docker image..."
    docker compose pull roundcube
    echo "-> Image pulled successfully"
else
    echo "-> Roundcube container not found"
fi
echo ""

if [ -d "$RC_DIR/data/hooks" ]; then
    echo "Adding hooks to mailcow hooks directory..."

    mkdir -p "$MAILCOW_DIR/data/hooks"

    cp -r "$RC_DIR/data/hooks/"* "$MAILCOW_DIR/data/hooks/"

    echo "-> Hooks added successfully"
    echo ""
fi

echo "--------------------------------------------------------"
echo "Update completed successfully!"
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
