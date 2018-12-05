<?php

/*
 * This file is part of the Symfony CMF package.
 *
 * (c) 2011-2017 Symfony CMF
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Cmf\Bundle\MediaBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class CmfMediaExtension extends Extension implements PrependExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container)
    {
        // get all Bundles
        $bundles = $container->getParameter('kernel.bundles');

        // process the configuration of CmfMediaExtension
        $configs = $container->getExtensionConfig($this->getAlias());
        $parameterBag = $container->getParameterBag();
        $configs = $parameterBag->resolveValue($configs);
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($config['persistence']['phpcr']['enabled']) {
            if (isset($bundles['CmfCreateBundle'])) {
                $config = [
                    'persistence' => [
                        'phpcr' => [
                            'image' => [
                                // enable imaging inside CreateBundle, general
                                // phpcr persistence still needs to be enabled
                                // explicitly or by CoreBundle
                                'enabled' => true,
                                'model_class' => $config['persistence']['phpcr']['image_class'],
                                'basepath' => $config['persistence']['phpcr']['media_basepath'],
                            ],
                        ],
                    ],
                ];
                $container->prependExtensionConfig('cmf_create', $config);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // detect bundles
        $bundles = $container->getParameter('kernel.bundles');

        $useJmsSerializer = true === $config['use_jms_serializer']
            || ('auto' === $config['use_jms_serializer']
                && isset($bundles['JMSSerializerBundle'])
            )
        ;

        $useElFinder = true === $config['use_elfinder']
            || ('auto' === $config['use_elfinder']
                && isset($bundles['FMElfinderBundle'])
            )
        ;

        // load config
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        if ($config['persistence']['phpcr']['enabled']) {
            $this->loadPhpcr($config['persistence']['phpcr'], $loader, $container, $useJmsSerializer, $useElFinder);
        }

        $container->setParameter($this->getAlias().'.upload_file_role', $config['upload_file_role']);

        if (isset($config['upload_file_helper_service_id'])) {
            $container->setAlias($this->getAlias().'.upload_file_helper', $config['upload_file_helper_service_id']);
        }
        if (isset($config['upload_image_helper_service_id'])) {
            $container->setAlias($this->getAlias().'.upload_image_helper', $config['upload_image_helper_service_id']);
        }

        if ($useElFinder) {
            $container->setParameter($this->getAlias().'.default_browser', 'elfinder');
        }
    }

    public function loadPhpcr(
        $config,
        XmlFileLoader $loader,
        ContainerBuilder $container,
        $useJmsSerializer,
        $useElFinder
    ) {
        $container->setParameter($this->getAlias().'.backend_type_phpcr', true);
        $prefix = $this->getAlias().'.persistence.phpcr';

        $keys = [
            'media_class' => 'media.class',
            'file_class' => 'file.class',
            'directory_class' => 'directory.class',
            'image_class' => 'image.class',
            'media_basepath' => 'media_basepath',
            'manager_name' => 'manager_name',
        ];

        foreach ($keys as $sourceKey => $targetKey) {
            if (isset($config[$sourceKey])) {
                $container->setParameter(
                    $prefix.'.'.$targetKey,
                    $config[$sourceKey]
                );
            }
        }

        // load phpcr specific configuration
        $loader->load('persistence-phpcr.xml');
        if (!interface_exists('Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface')) {
            $container->getDefinition('cmf_media.file_controller')->replaceArgument(7, new Reference('security.context'));
            $container->getDefinition('cmf_media.image_controller')->replaceArgument(7, new Reference('security.context'));
        }

        // aliases
        $container->setAlias($this->getAlias().'.upload_file_helper', $prefix.'.upload_file_helper');
        $container->setAlias($this->getAlias().'.upload_image_helper', $prefix.'.upload_image_helper');

        if (!$config['event_listeners']['stream_rewind']) {
            $container->removeDefinition('cmf_media.persistence.phpcr.subscriber.stream_rewind');
        }
        if (!$config['event_listeners']['image_dimensions']) {
            $container->removeDefinition('cmf_media.persistence.phpcr.subscriber.image_dimensions');
        } elseif (!function_exists('imagecreatefromstring')) {
            throw new InvalidConfigurationException('persistence.phpcr.subscriber.image_dimensions must be set to false if Imagine is not enabled and the GD PHP extension is not available.');
        }

        if ($useJmsSerializer) {
            // load phpcr specific serializer configuration
            $loader->load('serializer-phpcr.xml');
        }

        if ($useElFinder) {
            // load phpcr specific elfinder configuration
            $loader->load('adapter-elfinder-phpcr.xml');
        }
    }

    /**
     * Returns the base path for the XSD files.
     *
     * @return string The XSD base path
     */
    public function getXsdValidationBasePath()
    {
        return __DIR__.'/../Resources/config/schema';
    }

    public function getNamespace()
    {
        return 'http://cmf.symfony.com/schema/dic/media';
    }
}
