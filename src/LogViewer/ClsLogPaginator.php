<?php

namespace Dcat\Admin\LogViewer;

use Illuminate\Pagination\Paginator;

/** Render Dcat's simple pagination while preserving each direction's CLS cursor. */
class ClsLogPaginator extends Paginator
{
    public function __construct(array $logs, int $size, int $page, string $url, protected ?string $previous, protected ?string $next)
    {
        parent::__construct($logs, $size, $page, ['path' => $url]);
        $this->hasMorePagesWhen($next !== null);
    }

    public function previousPageUrl(): ?string
    {
        return $this->previous;
    }

    public function nextPageUrl(): ?string
    {
        return $this->next;
    }
}
