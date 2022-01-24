<?php

declare(strict_types=1);

namespace Andreo\EventSauceBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsUpcaster
{
    public function __construct(
        public string $aggregate,
        public int $version
    ) {
    }
}
