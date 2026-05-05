<?php

declare(strict_types=1);

namespace Andreo\EventSauceBundle\Tests\DependencyInjection;

use Andreo\EventSauceBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    /**
     * @test
     */
    public function should_build_config_tree(): void
    {
        $configuration = new Configuration();

        $treeBuilder = $configuration->getConfigTreeBuilder();

        self::assertSame('andreo_event_sauce', $treeBuilder->buildTree()->getName());
    }
}
