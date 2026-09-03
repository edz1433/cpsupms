<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SearchableSelectsTest extends TestCase
{
    public function test_all_selects_are_searchable_including_dynamically_added_selects(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/resources/js/app.js');

        $this->assertStringContainsString('select:not([data-no-search])', $script);
        $this->assertStringContainsString('new TomSelect(select', $script);
        $this->assertStringContainsString('new MutationObserver', $script);
        $this->assertStringContainsString('window.initializeSearchableSelects(node)', $script);
    }
}
