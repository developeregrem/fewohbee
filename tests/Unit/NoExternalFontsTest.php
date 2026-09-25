<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fonts are served locally (public/resources/css/roboto.css). Loading them from Google transmits
 * the visitor's IP address, which public pages such as the online booking and the guest check-in
 * must not do. Bootswatch ships its themes with a Google Fonts @import; this catches it coming
 * back with a library upgrade.
 */
final class NoExternalFontsTest extends TestCase
{
    private const FORBIDDEN_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];

    public function testNoStylesheetOrTemplateLoadsGoogleFonts(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];

        foreach (['public/resources/css', 'templates', 'assets/styles'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !\in_array($file->getExtension(), ['css', 'twig'], true)) {
                    continue;
                }
                $content = (string) file_get_contents($file->getPathname());
                foreach (self::FORBIDDEN_HOSTS as $host) {
                    if (str_contains($content, $host)) {
                        $offenders[] = substr($file->getPathname(), \strlen($root) + 1).' → '.$host;
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Serve fonts locally instead (see public/resources/css/roboto.css).');
    }
}
