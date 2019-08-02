<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class MpdfService {
    
    private $requestStack;
    public function __construct(RequestStack $requestStack) {
        $this->requestStack = $requestStack;
    }
    //put your code here
    public function getMpdf() {
        $locale = $this->requestStack->getCurrentRequest()->getLocale();
        $config = Array(
            'mode' => $locale,
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 25,
            'margin_right' => 20,
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_header' => 9,
            'margin_footer' => 9        
        );
        return new \Mpdf\Mpdf($config);
    }
}
