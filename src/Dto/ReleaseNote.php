<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A single release note document, as read from docs/release-notes/.
 *
 * One instance represents one version in one locale. The markdown body is kept
 * raw here; rendering to HTML is the job of ReleaseNotesService, which caches it.
 */
final readonly class ReleaseNote
{
    public function __construct(
        public string $version,
        public string $locale,
        public ?\DateTimeImmutable $date,
        public string $markdown,
    ) {
    }
}
