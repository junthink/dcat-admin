<?php

namespace Dcat\Admin\LogViewer;

use Dcat\Admin\Grid\Filter\Between;

/** Keep existing CLS links compatible while using the native datetime range. */
class ClsLogTimeRange extends Between
{
    protected function formatName($column)
    {
        return ['start' => 'start', 'end' => 'end'];
    }
}
