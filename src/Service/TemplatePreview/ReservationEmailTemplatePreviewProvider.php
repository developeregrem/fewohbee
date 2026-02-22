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
 * Preview provider for reservation email templates.
 */
class ReservationEmailTemplatePreviewProvider extends AbstractReservationTemplatePreviewProvider
{
    protected function getSupportedTemplateType(): string
    {
        return 'TEMPLATE_RESERVATION_EMAIL';
    }
}
