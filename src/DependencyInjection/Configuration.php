<?php

declare(strict_types=1);

namespace Andreo\EventSauceBundle\DependencyInjection;

use Andreo\EventSauce\Serialization\SymfonyPayloadSerializer;
use Andreo\EventSauce\Snapshotting\ConstructingSnapshotStateSerializer;
use EventSauce\Clock\SystemClock;
use EventSauce\EventSourcing\DotSeparatedSnakeCaseInflector;
use EventSauce\EventSourcing\Serialization\ConstructingMessageSerializer;
use EventSauce\EventSourcing\Serialization\ConstructingPayloadSerializer;
use EventSauce\MessageRepository\TableSchema\DefaultTableSchema;
use EventSauce\UuidEncoding\BinaryUuidEncoder;
use EventSauce\UuidEncoding\StringUuidEncoder;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    private const JSON_OPTIONS = [
        JSON_FORCE_OBJECT,
        JSON_HEX_QUOT,
        JSON_HEX_TAG,
        JSON_HEX_AMP,
        JSON_HEX_APOS,
        JSON_INVALID_UTF8_IGNORE,
        JSON_INVALID_UTF8_SUBSTITUTE,
        JSON_NUMERIC_CHECK,
        JSON_PARTIAL_OUTPUT_ON_ERROR,
        JSON_PRESERVE_ZERO_FRACTION,
        JSON_PRETTY_PRINT,
        JSON_UNESCAPED_LINE_TERMINATORS,
        JSON_UNESCAPED_SLASHES,
        JSON_UNESCAPED_UNICODE,
        JSON_THROW_ON_ERROR,
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('andreo_event_sauce');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->append($this->getTimeSection())
                ->append($this->getMessageSection())
                ->append($this->getSnapshotSection())
                ->append($this->getUpcastSection())
                ->append($this->getAggregatesSection())
                ->append($this->getPayloadSerializerSection())
                ->append($this->getClassNameInflectorSection())
                ->append($this->getUuidEncoderSection())
            ->end();

        return $treeBuilder;
    }

    private function getTimeSection(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('time');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('recording_timezone')
                    ->cannotBeEmpty()
                    ->defaultValue('UTC')
                ->end()
                ?->scalarNode('clock')
                    ->defaultNull()
                    ->info(
                        sprintf(
                            'You can set a custom clock here. Default is: %s',
                            SystemClock::class
                        ))
                ->end()
            ?->end();

        return $node;
    }

    private function getMessageSection(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('message');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->append($this->getMessageRepositorySection())
                ->scalarNode('serializer')
                    ->defaultNull()
                    ->info(
                        sprintf(
                            'You can set a custom message serializer here. Default is: %s',
                            ConstructingMessageSerializer::class
                        ))
                ->end()
                ?->append($this->getMessageDispatcherSection())
                ->booleanNode('decorator')->defaultTrue()->end()
                ?->append($this->getMessageOutboxSection())
            ->end();

        return $node;
    }

    private function getMessageRepositorySection(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('repository');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('json_encode_options')
                    ->normalizeKeys(false)
                    ->scalarPrototype()
                        ->validate()
                            ->ifNotInArray(self::JSON_OPTIONS)
                            ->thenInvalid('Invalid JSON options.')
                        ->end()
                    ->end()
                ?->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('connection')->cannotBeEmpty()->defaultValue('doctrine.dbal.default_connection')->end()
                        ?->scalarNode('table_schema')
                            ->defaultNull()
                            ->info(
                                sprintf(
                                    'You can set a custom message table schema here. Default is: %s',
                                    DefaultTableSchema::class
                                ))
                        ->end()
                        ?->scalarNode('table_name')
                            ->info('Table name postfix.')
                            ->cannotBeEmpty()
                            ->defaultValue('event_message')
                        ->end()
                    ?->end()
                ->end()
            ->end();

        return $node;
    }

    private function getMessageDispatcherSection(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('dispatcher');
        $node
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(function (array $values) {
                    $messenger = $values['messenger'];
                    $dispatchers = $values['chain'];
                    foreach ($dispatchers as $dispatcherId) {
                        if ($messenger['enabled'] && empty($dispatcherId)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->thenInvalid('If you use symfony messenger you must specify your message bus id as key value.')
            ->end()
            ->children()
                ->arrayNode('messenger')
                    ->canBeEnabled()
                    ->children()
                        ->enumNode('mode')
                            ->info('What is to be sent from an aggregate.')
                            ->values(['event', 'message', 'event_with_headers'])
                            ->defaultValue('event')
                        ->end()
                    ?->end()
                ->end()
                ?->arrayNode('chain')
                    ->normalizeKeys(false)
                    ->variablePrototype()->end()
                ?->end()
            ->end();

        return $node;
    }

    private function getMessageOutboxSection(): NodeDefinition
    {
        $backOfStrategies = [
            'exponential',
            'fibonacci',
            'linear_back',
            'no_waiting',
            'immediately',
            'custom',
        ];

        $node = new ArrayNodeDefinition('outbox');
        $node
            ->canBeEnabled()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('back_off')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static function (array $strategies) {
                            $count = 0;
                            foreach ($strategies as $strategy) {
                                if ($strategy['enabled']) {
                                    ++$count;
                                }
                            }

                            return $count > 1;
                        })
                        ->thenInvalid(
                            sprintf(
                                'Only one strategy of outbox back off can be set: %s.',
                                $this->implode($backOfStrategies)
                            )
                        )
                    ->end()
                    ->children()
                        ->arrayNode('exponential')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('initial_delay_ms')->defaultNull()->end()
                                ?->integerNode('max_tries')->defaultNull()->end()
                            ?->end()
                        ->end()
                        ->arrayNode('fibonacci')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('initial_delay_ms')->defaultNull()->end()
                                ?->integerNode('max_tries')->defaultNull()->end()
                            ?->end()
                        ->end()
                        ->arrayNode('linear_back')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('initial_delay_ms')->defaultNull()->end()
                                ?->integerNode('max_tries')->defaultNull()->end()
                            ?->end()
                        ->end()
                        ->arrayNode('no_waiting')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                            ->children()
                                ?->integerNode('max_tries')->defaultNull()->end()
                            ?->end()
                        ->end()
                        ->arrayNode('immediately')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                        ->end()
                        ?->arrayNode('custom')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('id')->isRequired()->end()
                            ?->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('relay_commit')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $config) => $config['mark_consumed']['enabled'] && $config['delete']['enabled'])
                        ->thenInvalid('Only one strategy of outbox relay commit can be set: mark_consumed or delete')
                    ->end()
                    ->children()
                        ->arrayNode('mark_consumed')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                        ->end()
                        ?->arrayNode('delete')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                        ->end()
                    ?->end()
                ->end()
                ->arrayNode('repository')
                    ->info('Only one type of repository can be selected.')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $config) => $config['memory']['enabled'] && $config['doctrine']['enabled'])
                        ->thenInvalid('Only one type of message outbox repository can be set: memory or doctrine')
                    ->end()
                    ->children()
                        ->arrayNode('memory')
                            ->canBeEnabled()
                        ->end()
                        ?->arrayNode('doctrine')
                            ->canBeDisabled()
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('table_name')
                                    ->info('Table name postfix.')
                                    ->cannotBeEmpty()
                                    ->defaultValue('outbox_message')
                                ->end()
                            ?->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function getSnapshotSection(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('snapshot');
        $node
            ->canBeEnabled()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('repository')
                    ->info('Only one type of repository can be selected.')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $config) => $config['memory']['enabled'] && $config['doctrine']['enabled'])
                        ->thenInvalid('Only one type of snapshot repository can be set: memory or doctrine')
                    ->end()
                    ->children()
                        ->arrayNode('memory')
                            ->canBeDisabled()
                        ->end()
                        ?->arrayNode('doctrine')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('table_name')
                                    ->info('Table name postfix.')
                                    ->cannotBeEmpty()
                                    ->defaultValue('snapshot')
                                ->end()
                            ?->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('versioned')->defaultFalse()->end()
                ?->arrayNode('store_strategy')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $config) => $config['every_n_event']['enabled'] && $config['custom']['enabled'])
                        ->thenInvalid('Only one strategy of snapshot store can be set: every_n_event or custom')
                    ->end()
                    ->children()
                        ->arrayNode('every_n_event')
                            ->canBeEnabled()
                            ->children()
                                ->integerNode('number')
                                    ->isRequired()
                                    ->min(10)
                                ?->end()
                            ?->end()
                        ->end()
                        ->arrayNode('custom')
                            ->canBeEnabled()
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('id')->isRequired()
                                ?->end()
                            ?->end()
                        ->end()
                    ?->end()
                ->end()
                ->scalarNode('serializer')
                    ->defaultNull()
                    ->info(
                        sprintf(
                            'You can set a custom snapshot state serializer here. Default is: %s',
                            ConstructingSnapshotStateSerializer::class
                        ))
                ->end()
            ?->end();

        return $node;
    }

    private function getUpcastSection(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('upcast');
        $node
            ->canBeEnabled()
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('context')->values(['payload', 'message'])->defaultValue('payload')->end()
            ?->end();

        return $node;
    }

    private function getAggregatesSection(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('aggregates');

        $node
            ->normalizeKeys(false)
            ->arrayPrototype()
                ->children()
                    ->scalarNode('class')
                        ->info('Aggregate root class')
                        ->cannotBeEmpty()
                        ->isRequired()
                    ->end()
                    ?->scalarNode('repository_alias')
                        ->info('Default "${aggregateName}Repository"')
                        ->defaultNull()
                        ->cannotBeEmpty()
                    ->end()
                    ?->arrayNode('message')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ?->booleanNode('outbox')->defaultFalse()->end()
                            ?->booleanNode('decorator')->defaultTrue()->end()
                            ?->arrayNode('dispatchers')
                                ->normalizeKeys(false)
                                ->scalarPrototype()->end()
                            ?->end()
                        ->end()
                    ->end()
                    ?->booleanNode('upcast')->defaultTrue()->end()
                    ?->booleanNode('snapshot')->defaultFalse()->end()
                ?->end()
            ->end();

        return $node;
    }

    public function getPayloadSerializerSection(): NodeDefinition
    {
        $node = new ScalarNodeDefinition('payload_serializer');
        $node
        ->defaultNull()
        ->info(
            sprintf(
                'You can set a custom serializer here, or choose from one of the existing: %s. Default is: %s',
                $this->implode([ConstructingPayloadSerializer::class, SymfonyPayloadSerializer::class]),
                ConstructingPayloadSerializer::class
            ))
        ->end();

        return $node;
    }

    public function getClassNameInflectorSection(): NodeDefinition
    {
        $node = new ScalarNodeDefinition('class_name_inflector');
        $node
            ->defaultNull()
            ->info(
                sprintf(
                    'You can set a custom class name inflector here. Default is: %s',
                    DotSeparatedSnakeCaseInflector::class
                ))
            ->end();

        return $node;
    }

    public function getUuidEncoderSection(): NodeDefinition
    {
        $node = new ScalarNodeDefinition('uuid_encoder');
        $node
            ->defaultNull()
            ->info(
                sprintf(
                    'You can set a custom uuid encoder here, or choose from one of the existing: %s. Default is: %s',
                    $this->implode([BinaryUuidEncoder::class, StringUuidEncoder::class]),
                    BinaryUuidEncoder::class
                ))
            ->end()
        ?->end();

        return $node;
    }

    private function implode(array $classes): string
    {
        return implode(', ', $classes);
    }
}
