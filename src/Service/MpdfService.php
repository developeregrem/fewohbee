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

    public function getMpdf()
    {
        $locale = $this->requestStack->getCurrentRequest()->getLocale();

        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0775, true);
        }

        $config = [
            'mode' => $locale,
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 25,
            'margin_right' => 20,
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_header' => 9,
            'margin_footer' => 9,
            'tempDir' => $this->tempDir,
        ];

        return new \Mpdf\Mpdf($config);
    }
}
