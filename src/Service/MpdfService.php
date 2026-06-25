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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

class MpdfService
{
    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.cache_dir%/mpdf')]
        private readonly string $tempDir,
    ) {
    }

    public function getMpdf(string $format = 'A4')
    {
        $locale = $this->requestStack->getCurrentRequest()->getLocale();

        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0775, true);
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
            'tempDir'       => $this->tempDir
        ];

        return new \Mpdf\Mpdf($config);
    }
}
