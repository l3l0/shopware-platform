<?php declare(strict_types=1);

namespace Shopware\Storefront\DependencyInjection;

use Shopware\Storefront\Theme\ConfigLoader\DatabaseAvailableThemeProvider;
use Shopware\Storefront\Theme\ConfigLoader\DatabaseConfigLoader;
use Shopware\Storefront\Theme\MD5ThemePathBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('storefront');

        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('csrf')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->enumNode('mode')
                            ->values(['twig', 'ajax'])
                            ->defaultValue('twig')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('htmlPurifier')
                    ->setDeprecated('shopware/storefront', '6.4.3.0', 'Use html_sanitizer configuration from shopware/core bundle instead')
                    ->children()
                        ->variableNode('cacheDir')
                            ->setDeprecated('shopware/storefront', '6.4.3.0', 'Use html_sanitizer configuration from shopware/core bundle instead')
                        ->end()
                        ->booleanNode('cacheEnabled')
                            ->setDeprecated('shopware/storefront', '6.4.3.0', 'Use html_sanitizer configuration from shopware/core bundle instead')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('reverse_proxy')
                    ->children()
                        ->booleanNode('enabled')->end()
                        ->arrayNode('hosts')->performNoDeepMerging()->scalarPrototype()->end()->end()
                        ->integerNode('max_parallel_invalidations')->defaultValue(2)->end()
                        ->scalarNode('redis_url')->end()
                        ->scalarNode('ban_method')->defaultValue('BAN')->end()
                        ->arrayNode('ban_headers')->performNoDeepMerging()->defaultValue([])->scalarPrototype()->end()->end()
                        ->arrayNode('purge_all')
                            ->children()
                                ->scalarNode('ban_method')->defaultValue('BAN')->end()
                                ->arrayNode('ban_headers')->performNoDeepMerging()->defaultValue([])->scalarPrototype()->end()->end()
                                ->arrayNode('urls')->performNoDeepMerging()->defaultValue(['/'])->scalarPrototype()->end()->end()
                            ->end()
                        ->end()
                        ->arrayNode('fastly')
                            ->children()
                                 ->booleanNode('enabled')->defaultFalse()->end()
                                 ->scalarNode('api_key')->defaultValue('')->end()
                                 ->scalarNode('service_id')->defaultValue('')->end()
                                 ->scalarNode('soft_purge')->defaultValue('0')->end()
                                 ->scalarNode('tag_prefix')->defaultValue('')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('http_cache')
                    ->children()
                        ->scalarNode('stale_while_revalidate')->defaultValue(null)->end()
                        ->scalarNode('stale_if_error')->defaultValue(null)->end()
                        ->arrayNode('ignored_url_parameters')->scalarPrototype()->end()
                    ->end()
                ->end()
                ->end()
                ->arrayNode('theme')
                    ->children()
                        ->scalarNode('config_loader_id')->defaultValue(DatabaseConfigLoader::class)->end()
                        ->scalarNode('theme_path_builder_id')->defaultValue(MD5ThemePathBuilder::class)->end()
                        ->scalarNode('available_theme_provider')->defaultValue(DatabaseAvailableThemeProvider::class)->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
