<?php

namespace Dcat\Admin\Widgets;

use Dcat\Admin\Admin;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Tab extends Widget
{
    const TYPE_CONTENT = 1;
    const TYPE_LINK = 2;

    /**
     * @var string
     */
    protected $view = 'admin::widgets.tab';

    /**
     * @var array
     */
    protected $data = [
        'id'       => '',
        'title'    => '',
        'tabs'     => [],
        'dropDown' => [],
        'active'   => 0,
        'padding'  => null,
        'tabStyle' => '',
        'searchable' => false,
    ];

    /**
     * Add a tab and its contents.
     *
     * @param  string  $title
     * @param  string|Renderable  $content
     * @param  bool  $active
     * @param  string|null  $id
     * @return $this
     */
    public function add($title, $content, $active = false, $id = null)
    {
        $this->data['tabs'][] = [
            'id'      => $id ?: mt_rand(),
            'title'   => $title,
            'content' => $this->toString($this->formatRenderable($content)),
            'type'    => static::TYPE_CONTENT,
        ];

        if ($active) {
            $this->data['active'] = count($this->data['tabs']) - 1;
        }

        return $this;
    }

    /**
     * Add a link on tab.
     *
     * @param  string  $title
     * @param  string  $href
     * @param  bool  $active
     * @return $this
     */
    public function addLink($title, $href, $active = false)
    {
        $this->data['tabs'][] = [
            'id'      => mt_rand(),
            'title'   => $title,
            'href'    => $href,
            'type'    => static::TYPE_LINK,
        ];

        if ($active) {
            $this->data['active'] = count($this->data['tabs']) - 1;
        }

        return $this;
    }

    /**
     * Build link navigation in the supplied order, rendering only the active content.
     *
     * @param array<string|int, array{title: string, href: string}> $links
     * @param string|int|null $active
     * @param mixed $content
     * @return $this
     */
    public function addLinks(array $links, $active = null, $content = null)
    {
        $active = $active ?? array_key_first($links);
        if ($active !== null && ! array_key_exists($active, $links)) {
            throw new InvalidArgumentException('The active tab must be present in the supplied links.');
        }

        foreach ($links as $key => $link) {
            $selected = (string) $key === (string) $active;
            if ($selected && $content !== null) {
                $this->add($link['title'], $content, true);
            } else {
                $this->addLink($link['title'], $link['href'], $selected);
            }
            $this->data['tabs'][array_key_last($this->data['tabs'])]['search'] = (string) $key;
        }

        return $this;
    }

    public function searchable(bool $enabled = true)
    {
        $this->data['searchable'] = $enabled;

        return $this;
    }

    /**
     * Set tab content padding.
     *
     * @param  string  $padding
     */
    public function padding(string $padding)
    {
        $this->data['padding'] = 'padding:'.$padding;

        return $this;
    }

    public function noPadding()
    {
        return $this->padding('0');
    }

    /**
     * Set title.
     *
     * @param  string  $title
     */
    public function title($title = '')
    {
        $this->data['title'] = $title;

        return $this;
    }

    /**
     * Set drop-down items.
     *
     * @param  array  $links
     * @return $this
     */
    public function dropdown(array $links)
    {
        if (is_array($links[0])) {
            foreach ($links as $link) {
                call_user_func([$this, 'dropDown'], $link);
            }

            return $this;
        }

        $this->data['dropDown'][] = [
            'name' => $links[0],
            'href' => $links[1],
        ];

        return $this;
    }

    public function withCard()
    {
        return $this
            ->class('card', true)
            ->style('padding:.25rem .4rem .4rem');
    }

    public function vertical()
    {
        return $this
            ->class('nav-vertical d-block', true)
            ->style('padding:0!important;')
            ->tabStyle('nav-left flex-column');
    }

    public function theme(string $style = 'primary')
    {
        return $this
            ->class('nav-theme-'.$style, true)
            ->style('padding:0!important;');
    }

    public function tabStyle($type)
    {
        $this->data['tabStyle'] = $type;

        return $this;
    }

    /**
     * Render Tab.
     *
     * @return string
     */
    public function render()
    {
        $this->defaultHtmlAttribute('id', 'dcat-tabs-'.Str::random(10));
        $this->setHtmlAttribute('data-dcat-tabs', '');
        $data = array_merge(
            $this->data,
            ['attributes' => $this->formatHtmlAttributes(), 'searchId' => $this->getHtmlAttribute('id').'-search']
        );

        if ($this->runScript) {
            $this->setupScript();
        }

        return view($this->view, $data)->render();
    }

    /**
     * Setup script.
     */
    protected function setupScript()
    {
        $id = json_encode($this->getHtmlAttribute('id'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        Admin::script("Dcat.Tab(document.getElementById({$id}));");
    }
}
