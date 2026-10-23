<?php

namespace Dcat\Admin\Support;

/** Compatibility for the common resize callbacks when migrating from Image 2. */
class ImageConstraint
{
    public bool $preserveAspectRatio = false;
    public bool $preventUpsize = false;

    public function aspectRatio(): void
    {
        $this->preserveAspectRatio = true;
    }

    public function upsize(): void
    {
        $this->preventUpsize = true;
    }
}
