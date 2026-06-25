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

        // Optional per-tenant key prefix for a shared bucket (e.g. a UUID). Empty
        // → bucket root (single-tenant). Must stay in sync with ImageUrlGenerator,
        // which prepends the same prefix when building public URLs.
        $base = trim($_SERVER['STORAGE_S3_PREFIX'] ?? '', '/');
        $keyPrefix = static fn (string $sub): string => '' === $base ? $sub : $base . '/' . $sub;

        $container->extension('oneup_flysystem', [
            'adapters' => [
                'images.export.adapter' => [
                    'awss3v3' => [
                        'client' => 'storage.s3_client',
                        'bucket' => $bucket,
                        'prefix' => $keyPrefix('export'),
                    ],
                ],
                'images.roomcat.adapter' => [
                    'awss3v3' => [
                        'client' => 'storage.s3_client',
                        'bucket' => $bucket,
                        'prefix' => $keyPrefix('room-categories'),
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
                    // Uploads are public assets served directly by the web server,
                    // which may run as a different OS user than PHP-FPM (e.g. in the
                    // Kubernetes nginx+php-fpm pod). Force world-readable modes so the
                    // web server can read them; the default 'private' visibility would
                    // write 0600/0700 and yield 404s.
                    'permissions' => [
                        'file' => ['public' => 0644, 'private' => 0644],
                        'dir' => ['public' => 0755, 'private' => 0755],
                    ],
                ],
            ],
            'images.roomcat.adapter' => [
                'local' => [
                    'location' => '%kernel.project_dir%/public/resources/images/room-categories',
                    'permissions' => [
                        'file' => ['public' => 0644, 'private' => 0644],
                        'dir' => ['public' => 0755, 'private' => 0755],
                    ],
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
