<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Widgets\Tab;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

class TabTest extends TestCase
{
    private function navigation(string $html): array
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($document);
        $items = $xpath->query('//ul[contains(@class,"nav-tabs")]/li/a[contains(@class,"nav-link") and not(contains(@class,"dropdown-toggle"))]');

        return array_map(fn ($item) => trim($item->textContent), iterator_to_array($items));
    }

    public function test_manual_tabs_keep_their_order_when_a_middle_tab_is_active(): void
    {
        $tab = Tab::make()->addLink('First', '/first')->add('Middle', 'Current content', true)->addLink('Last', '/last');
        $this->assertSame(['First', 'Middle', 'Last'], $this->navigation($tab->render()));
    }

    public function test_link_navigation_keeps_its_order_and_renders_only_the_selected_content(): void
    {
        $calls = 0;
        $tab = Tab::make()->addLinks([
            'first' => ['title' => 'First', 'href' => '/detail?_t=first'],
            'middle' => ['title' => 'Middle', 'href' => '/detail?_t=middle'],
            'last' => ['title' => 'Last', 'href' => '/detail?_t=last'],
        ], 'middle', function () use (&$calls) { $calls++; return 'Selected content'; });
        $html = $tab->render();
        $this->assertSame(1, $calls);
        $this->assertSame(['First', 'Middle', 'Last'], $this->navigation($html));
        $this->assertStringContainsString('Selected content', $html);
        $this->assertStringContainsString('/detail?_t=first', $html);
        $this->assertStringContainsString('/detail?_t=last', $html);
    }

    public function test_unknown_selected_tabs_are_rejected_before_content_is_rendered(): void
    {
        $called = false;
        try {
            Tab::make()->addLinks(['allowed' => ['title' => 'Allowed', 'href' => '/allowed']], 'forbidden', function () use (&$called) { $called = true; });
            $this->fail('An unknown tab was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertFalse($called);
        }
    }

    public function test_search_is_opt_in_and_can_be_disabled(): void
    {
        $tab = Tab::make()->add('Title', 'Content');
        $this->assertStringNotContainsString('data-tab-search-toggle', $tab->render());
        $this->assertStringContainsString('data-tab-search-toggle', $tab->searchable()->render());
        $this->assertStringNotContainsString('data-tab-search-toggle', $tab->searchable(false)->render());
    }

    public function test_multiple_search_widgets_have_distinct_ids_and_keep_explicit_ids(): void
    {
        $first = Tab::make()->searchable()->add('First', 'Content');
        $second = Tab::make()->searchable()->add('Second', 'Content');
        $first->render();
        $second->render();
        $this->assertNotSame($first->getHtmlAttribute('id'), $second->getHtmlAttribute('id'));
        $explicit = Tab::make()->id('detail-tabs')->searchable()->add('Title', 'Content')->render();
        $this->assertStringContainsString('id="detail-tabs"', $explicit);
        $this->assertStringContainsString('detail-tabs-search', $explicit);
    }
}
