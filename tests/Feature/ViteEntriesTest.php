<?php

namespace Tests\Feature;

use Tests\TestCase;

class ViteEntriesTest extends TestCase
{
    public function test_page_entries_are_registered_in_vite_input(): void
    {
        $config = file_get_contents(base_path('vite.config.js'));
        foreach (glob(resource_path('js/*.js')) as $entry) {
            $this->assertStringContainsString('resources/js/'.basename($entry), $config, basename($entry));
        }
    }
}
