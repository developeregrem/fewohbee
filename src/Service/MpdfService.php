<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

class MpdfService
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CustomFontManager $customFonts,
        #[Autowire('%kernel.cache_dir%/mpdf')]
        private readonly string $tempDir,
        #[Autowire('%locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    public function getMpdf(string $format = 'A4'): Mpdf
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? $this->defaultLocale;

        $customFontData = $this->customFonts->getMpdfFontData();
        $tempDir = [] === $customFontData
            ? $this->tempDir
            : $this->tempDir.'/fonts-'.$this->customFonts->getCacheFingerprint();

        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $isA5 = 'A5' === strtoupper($format);
        $isA6 = 'A6' === strtoupper($format);
        $config = [
            'mode'          => $locale,
            'format'        => strtoupper($format),
            'orientation'   => 'P',
            'margin_left'   => $isA6 ? 10 : ($isA5 ? 15 : 25),
            'margin_right'  => $isA6 ? 8  : ($isA5 ? 12 : 20),
            'margin_top'    => $isA6 ? 8  : ($isA5 ? 12 : 20),
            'margin_bottom' => $isA6 ? 8  : ($isA5 ? 12 : 20),
            'margin_header' => $isA6 ? 4  : ($isA5 ? 6  : 9),
            'margin_footer' => $isA6 ? 4  : ($isA5 ? 6  : 9),
            'tempDir'       => $tempDir,
        ];

        if ([] !== $customFontData) {
            $defaultConfig = (new ConfigVariables())->getDefaults();
            $defaultFontConfig = (new FontVariables())->getDefaults();
            $config['fontDir'] = array_merge($defaultConfig['fontDir'], [$this->customFonts->getFontDirectory()]);
            $config['fontdata'] = $defaultFontConfig['fontdata'] + $customFontData;
        }

        return new Mpdf($config);
    }
}
