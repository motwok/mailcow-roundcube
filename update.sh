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

echo "=== Mailcow Roundcube Extension Updater ==="

# 1. Pull newest repository changes from Git
if [ -d "$RC_DIR/.git" ]; then
    echo "Pulling newest extension files from Git..."
    cd "$RC_DIR"
    git pull
else
    echo "Note: mailcow-roundcube is not a git repository. Skipping git pull."
fi

# 2. Pull newest official Roundcube Docker Image
echo "Pulling newest Roundcube Docker image..."
cd "$MAILCOW_DIR"
docker compose pull roundcube-mailcow

# 3. Restart the container stack to apply updates
echo "Restarting the container stack..."
docker compose down
docker compose up -d

echo "--------------------------------------------------------"
echo "UPDATE DONE! Roundcube has been updated successfully."
