<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Core\MutableByDesign;

/** The one mutable cell a readonly sitemap source needs: how many lots its index listed last time. */
final class IndexSize implements MutableByDesign
{
    public ?int $value = null;
}
