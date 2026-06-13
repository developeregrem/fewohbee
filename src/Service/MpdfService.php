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

use Symfony\Component\HttpFoundation\RequestStack;

class MpdfService
{
    private $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function getMpdf(string $format = 'A4')
    {
        $locale = $this->requestStack->getCurrentRequest()->getLocale();
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
        ];

        return new \Mpdf\Mpdf($config);
    }
}
