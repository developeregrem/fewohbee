<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReleaseNote;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Reads the release notes that ship with the application.
 *
 * Notes live as markdown in docs/release-notes/ and are named
 * "<version>.<locale>.md" (e.g. "4.12.0.de.md"). They are part of the
 * repository and therefore part of the Docker image, so they always match the
 * deployed version and work without outbound network access.
 *
 * An optional YAML front matter block carries the release date:
 *
 *     ---
 *     date: 2026-09-15
 *     ---
 */
class ReleaseNotesService
{
    private const SUBDIRECTORY = 'docs/release-notes';

    /** Locales tried after the requested one, in order. Mirrors the translator fallback. */
    private const FALLBACK_LOCALES = ['de', 'en'];

    /** @var array<string, string[]>|null version => locales, sorted newest version first */
    private ?array $index = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $version,
        private readonly CacheInterface $cache,
    ) {
    }

    /** The version this installation runs, as configured in config/services.yaml. */
    public function getCurrentVersion(): string
    {
        return $this->version;
    }

    /**
     * All versions that have at least one release note file, newest first.
     *
     * @return string[]
     */
    public function getAvailableVersions(): array
    {
        return array_keys($this->getIndex());
    }

    public function hasNotesFor(string $version): bool
    {
        return isset($this->getIndex()[$version]);
    }

    /**
     * Returns the note for a version, falling back to another locale when the
     * requested one has not been translated yet. Null when the version has no
     * notes at all.
     */
    public function get(string $version, string $locale): ?ReleaseNote
    {
        $available = $this->getIndex()[$version] ?? null;
        if (null === $available) {
            return null;
        }

        foreach ([$locale, ...self::FALLBACK_LOCALES] as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $this->read($version, $candidate);
            }
        }

        return null;
    }

    /**
     * All available notes for the given locale, newest version first.
     *
     * @return ReleaseNote[]
     */
    public function getAll(string $locale): array
    {
        $notes = [];
        foreach ($this->getAvailableVersions() as $version) {
            $note = $this->get($version, $locale);
            if (null !== $note) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * Renders a note to HTML. Cached, because the content of a released version
     * never changes while that version is deployed.
     */
    public function getHtml(ReleaseNote $note): string
    {
        $key = 'release_notes.html.' . str_replace('.', '_', $note->version) . '.' . $note->locale;

        return $this->cache->get($key, function (ItemInterface $item) use ($note): string {
            $item->expiresAfter(null);

            // html_input: strip removes any raw HTML from the markdown, so the
            // rendered string is safe to print with |raw in the template even
            // though the source is repository-authored and already trusted.
            $converter = new CommonMarkConverter([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);

            return $converter->convert($note->markdown)->getContent();
        });
    }

    /**
     * Scans the release notes directory once per request.
     *
     * @return array<string, string[]> version => locales, sorted newest version first
     */
    private function getIndex(): array
    {
        if (null !== $this->index) {
            return $this->index;
        }

        $index = [];
        $directory = $this->getDirectory();

        foreach (glob($directory . '/*.md') ?: [] as $path) {
            if (!preg_match('/^(.+)\.([a-z]{2})\.md$/', basename($path), $matches)) {
                continue;
            }
            $index[$matches[1]][] = $matches[2];
        }

        uksort($index, static fn (string $a, string $b): int => version_compare($b, $a));

        return $this->index = $index;
    }

    private function getDirectory(): string
    {
        return $this->projectDir . '/' . self::SUBDIRECTORY;
    }

    private function read(string $version, string $locale): ?ReleaseNote
    {
        $content = @file_get_contents(sprintf('%s/%s.%s.md', $this->getDirectory(), $version, $locale));
        if (false === $content) {
            return null;
        }

        [$frontMatter, $markdown] = $this->splitFrontMatter($content);

        return new ReleaseNote($version, $locale, $this->parseDate($frontMatter['date'] ?? null), $markdown);
    }

    /**
     * Normalises whatever the front matter yielded for `date`.
     *
     * Symfony's YAML parser returns a \DateTimeImmutable for an unquoted date once
     * PARSE_DATETIME is set, but a quoted one stays a plain string — accept both,
     * and ignore anything that is neither.
     */
    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && '' !== $value) {
            return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        }

        return null;
    }

    /**
     * Splits an optional leading YAML front matter block off the document.
     *
     * @return array{array<string, mixed>, string}
     */
    private function splitFrontMatter(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (!preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $content, $matches)) {
            return [[], trim($content)];
        }

        try {
            $parsed = Yaml::parse($matches[1], Yaml::PARSE_DATETIME);
        } catch (\Throwable) {
            // A broken front matter block must not take the whole page down.
            return [[], trim($matches[2])];
        }

        return [is_array($parsed) ? $parsed : [], trim($matches[2])];
    }
}
