<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Conditional Flysystem storage configuration.
 *
 * STORAGE_ADAPTER=local (default) — uploads stay in the local public/ tree, no behaviour
 *   change for existing self-hosting installations.
 * STORAGE_ADAPTER=s3 — uploads go to an S3-compatible bucket (e.g. Hetzner Object Storage)
 *   for Kubernetes / multi-pod deployments.
 *
 * The adapter is baked into the compiled container; switching it requires a container
 * rebuild (cache:clear), same as USE_REDIS_CACHE.
 *
 * The configured filesystem services are exposed via `alias` so they can be referenced
 * as @images.export.storage / @images.roomcat.storage from services.yaml regardless of
 * the underlying adapter.
 */
return static function (ContainerConfigurator $container): void {
    $adapter = $_SERVER['STORAGE_ADAPTER'] ?? 'local';

    if ('s3' === $adapter) {
        $bucket = $_SERVER['STORAGE_S3_BUCKET'] ?? '';

        $container->extension('oneup_flysystem', [
            'adapters' => [
                'images.export.adapter' => [
                    'awss3v3' => [
                        'client' => 'storage.s3_client',
                        'bucket' => $bucket,
                        'prefix' => 'export',
                    ],
                ],
                'images.roomcat.adapter' => [
                    'awss3v3' => [
                        'client' => 'storage.s3_client',
                        'bucket' => $bucket,
                        'prefix' => 'room-categories',
                    ],
                ],
            ],
            'filesystems' => [
                'images_export' => [
                    'adapter' => 'images.export.adapter',
                    'alias' => 'images.export.storage',
                ],
                'images_roomcat' => [
                    'adapter' => 'images.roomcat.adapter',
                    'alias' => 'images.roomcat.storage',
                ],
            ],
        ]);

        return;
    }

    $container->extension('oneup_flysystem', [
        'adapters' => [
            'images.export.adapter' => [
                'local' => [
                    'location' => '%kernel.project_dir%/public/resources/images/export',
                ],
            ],
            'images.roomcat.adapter' => [
                'local' => [
                    'location' => '%kernel.project_dir%/public/resources/images/room-categories',
                ],
            ],
        ],
        'filesystems' => [
            'images_export' => [
                'adapter' => 'images.export.adapter',
                'alias' => 'images.export.storage',
            ],
            'images_roomcat' => [
                'adapter' => 'images.roomcat.adapter',
                'alias' => 'images.roomcat.storage',
            ],
        ],
    ]);
};
