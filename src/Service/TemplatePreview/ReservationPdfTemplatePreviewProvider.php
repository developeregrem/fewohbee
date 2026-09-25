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

namespace App\Service\TemplatePreview;

/**
 * Preview provider for reservation PDF templates.
 */
class ReservationPdfTemplatePreviewProvider extends AbstractReservationTemplatePreviewProvider
{
    protected function getSupportedTemplateType(): string
    {
        return 'TEMPLATE_RESERVATION_PDF';
    }

    public function getAvailableSnippets(): array
    {
        $snippets = parent::getAvailableSnippets();
        // PDF only: mail clients drop data: images, mails get the link snippet instead.
        $snippets[] = [
            'id' => 'reservation.guest_checkin_qr',
            'label' => 'templates.editor.guest_checkin_qr',
            'description' => 'templates.editor.guest_checkin_qr.desc',
            'group' => 'Reservation',
            'complexity' => 'easy',
            'content' => "<div data-if=\"guest_checkin_url(reservation1)\"><img src=\"[[ guest_checkin_qr(reservation1, 300) ]]\" alt=\"\" width=\"30mm\"></div>",
        ];
        $snippets[] = [
            'id' => 'pdf.header',
            'label' => 'templates.preview.snippet.pdf_header',
            'description' => 'templates.preview.snippet.pdf_header.desc',
            'group' => 'PDF',
            'complexity' => 'simple',
            'content' => '<div class="header">\n  <p>Header</p>\n</div>',
        ];
        $snippets[] = [
            'id' => 'pdf.footer',
            'label' => 'templates.preview.snippet.pdf_footer',
            'description' => 'templates.editor.footer.desc',
            'group' => 'PDF',
            'complexity' => 'simple',
            'content' => '<div class="footer">\n  <p>Footer</p>\n</div>',
        ];

        return $snippets;
    }
}
