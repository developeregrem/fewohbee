<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ReleaseNotesService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ReleaseNotesServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/release-notes-test-' . uniqid();
        mkdir($this->projectDir . '/docs/release-notes', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir . '/docs/release-notes/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->projectDir . '/docs/release-notes');
        @rmdir($this->projectDir . '/docs');
        @rmdir($this->projectDir);
    }

    public function testReturnsNullWhenTheDirectoryDoesNotExist(): void
    {
        $service = new ReleaseNotesService('/nonexistent/project', '4.12.0', new ArrayAdapter());

        self::assertSame([], $service->getAvailableVersions());
        self::assertNull($service->get('4.12.0', 'de'));
        self::assertFalse($service->hasNotesFor('4.12.0'));
    }

    public function testSortsVersionsNewestFirstUsingVersionCompare(): void
    {
        $this->writeNote('4.9.0', 'de', '# alt');
        $this->writeNote('4.10.0', 'de', '# mittel');
        $this->writeNote('4.11.0', 'de', '# neu');

        // Plain string sorting would put 4.9.0 above 4.10.0.
        self::assertSame(['4.11.0', '4.10.0', '4.9.0'], $this->service()->getAvailableVersions());
    }

    public function testParsesTheDateFromFrontMatter(): void
    {
        $this->writeNote('4.12.0', 'de', "---\ndate: 2026-09-15\n---\n\n## Neu\n");

        $note = $this->service()->get('4.12.0', 'de');

        self::assertNotNull($note);
        self::assertSame('2026-09-15', $note->date?->format('Y-m-d'));
        self::assertSame("## Neu", $note->markdown);
    }

    public function testWorksWithoutFrontMatter(): void
    {
        $this->writeNote('4.12.0', 'de', "## Neu\n\nEin Punkt.\n");

        $note = $this->service()->get('4.12.0', 'de');

        self::assertNotNull($note);
        self::assertNull($note->date);
        self::assertStringStartsWith('## Neu', $note->markdown);
    }

    public function testBrokenFrontMatterDoesNotBreakTheDocument(): void
    {
        $this->writeNote('4.12.0', 'de', "---\ndate: [unclosed\n---\n\n## Neu\n");

        $note = $this->service()->get('4.12.0', 'de');

        self::assertNotNull($note);
        self::assertNull($note->date);
        self::assertSame('## Neu', $note->markdown);
    }

    public function testFallsBackToAnotherLocaleWhenTheRequestedOneIsMissing(): void
    {
        $this->writeNote('4.12.0', 'en', '## What is new');

        $note = $this->service()->get('4.12.0', 'de');

        self::assertNotNull($note);
        self::assertSame('en', $note->locale, 'German is not translated yet, so English must be served');
    }

    public function testPrefersTheRequestedLocaleWhenBothExist(): void
    {
        $this->writeNote('4.12.0', 'de', '## Neu');
        $this->writeNote('4.12.0', 'en', '## What is new');

        self::assertSame('de', $this->service()->get('4.12.0', 'de')?->locale);
        self::assertSame('en', $this->service()->get('4.12.0', 'en')?->locale);
    }

    public function testGetAllSkipsNothingAndKeepsVersionOrder(): void
    {
        $this->writeNote('4.10.0', 'en', '## Old');
        $this->writeNote('4.12.0', 'de', '## Neu');

        $notes = $this->service()->getAll('de');

        self::assertCount(2, $notes);
        self::assertSame(['4.12.0', '4.10.0'], array_map(static fn ($n) => $n->version, $notes));
    }

    public function testIgnoresFilesThatDoNotMatchTheNamingScheme(): void
    {
        $this->writeNote('4.12.0', 'de', '## Neu');
        file_put_contents($this->projectDir . '/docs/release-notes/README.md', 'not a release note');

        self::assertSame(['4.12.0'], $this->service()->getAvailableVersions());
    }

    public function testRendersMarkdownToHtml(): void
    {
        $this->writeNote('4.12.0', 'de', "## Neu\n\n* Ein Punkt\n");
        $note = $this->service()->get('4.12.0', 'de');
        self::assertNotNull($note);

        $html = $this->service()->getHtml($note);

        self::assertStringContainsString('<h2>Neu</h2>', $html);
        self::assertStringContainsString('<li>Ein Punkt</li>', $html);
    }

    public function testStripsRawHtmlFromTheMarkdown(): void
    {
        $this->writeNote('4.12.0', 'de', "## Neu\n\n<script>alert(1)</script>\n");
        $note = $this->service()->get('4.12.0', 'de');
        self::assertNotNull($note);

        self::assertStringNotContainsString('<script>', $this->service()->getHtml($note));
    }

    private function service(string $version = '4.12.0'): ReleaseNotesService
    {
        return new ReleaseNotesService($this->projectDir, $version, new ArrayAdapter());
    }

    private function writeNote(string $version, string $locale, string $content): void
    {
        file_put_contents(
            sprintf('%s/docs/release-notes/%s.%s.md', $this->projectDir, $version, $locale),
            $content
        );
    }
}
