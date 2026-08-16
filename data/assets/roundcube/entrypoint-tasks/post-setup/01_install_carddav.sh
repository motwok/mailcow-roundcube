#/bin/bash

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

INSTALLDIR=`pwd`

: "${ROUNDCUBEMAIL_COMPOSER_PLUGINS_FOLDER:=$INSTALLDIR}"
: "${ROUNDCUBEMAIL_PLUGINS_CONFIG_FOLDER:=/var/roundcube/config}"

echo "Installing roundcube/carddav plugin into ${ROUNDCUBEMAIL_COMPOSER_PLUGINS_FOLDER}"

composer \
    --working-dir=${ROUNDCUBEMAIL_COMPOSER_PLUGINS_FOLDER} \
    --prefer-dist \
    --prefer-stable \
    --update-no-dev \
    --no-interaction \
    --optimize-autoloader \
    require \
    -- \
    roundcube/carddav:~5;

echo "Fix config file for roundcube/carddav plugin"
# TODO override destination with include
cp ${ROUNDCUBEMAIL_PLUGINS_CONFIG_FOLDER}/plugins/carddav/config.inc.php ${ROUNDCUBEMAIL_COMPOSER_PLUGINS_FOLDER}/plugins/carddav/config.inc.php
