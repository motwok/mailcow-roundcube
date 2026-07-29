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
 */
class mailcow_link extends rcube_plugin
{
    public function init()
    {
        // Hook into the top taskbar container of Roundcube
        $this->add_hook('template_container', array($this, 'add_link'));
    }

    public function add_link($args)
    {
        // Only inject the link if Roundcube is rendering the top navigation bar
        if ($args['name'] == 'taskbar') {
            // Fetch the Mailcow Hostname from the environment
            $mailcow_host = getenv('MAILCOW_HOSTNAME');
            
            // Build the HTML using native Roundcube CSS classes for structural icons (button + icon class)
            // The 'settings' class automatically applies a wrench/gear icon via Roundcube's internal webfont/SVG assets
            $args['content'] .= sprintf(
                '<a class="button settings" href="https://%s/user" target="_top" style="background-color: #3f51b5; color: white; font-weight: bold; border-radius: 4px; margin-left: 10px; padding: 0 10px;" title="Mailcow UI"><span class="button-inner">Mailcow UI</span></a>',
                $mailcow_host
            );
        }
        return $args;
    }
}
