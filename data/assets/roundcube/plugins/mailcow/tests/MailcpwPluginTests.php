<?php

namespace Roundcube\Plugins\Tests;

use PHPUnit\Framework\TestCase;

class MailcowPluginTests extends TestCase
{
    /**
     * Plugin object construction test
     */
    public function test_constructor()
    {
        $rcube = \rcube::get_instance();
        $plugin = new \mailcow($rcube->plugins);

        $this->assertInstanceOf('mailcow', $plugin);
        $this->assertInstanceOf('rcube_plugin', $plugin);
    }
}
