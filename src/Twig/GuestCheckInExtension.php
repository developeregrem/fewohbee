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

namespace App\Twig;

use App\Entity\Reservation;
use App\Service\GuestCheckIn\GuestCheckInLinkService;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Online check-in link and QR code for reservation templates.
 *
 * Both return an empty string when the reservation has no link (feature off, cancelled, stay
 * over, no public address), so a template can guard with data-if. Rendering a link creates it
 * on first use — except in the template editor preview, which shows a stand-in instead of
 * creating links for whatever reservation the preview borrowed.
 */
final class GuestCheckInExtension extends AbstractExtension
{
    private const EDITOR_PREVIEW_ROUTES = ['settings.templates.preview.render', 'settings.templates.preview.pdf'];

    public function __construct(
        private readonly GuestCheckInLinkService $linkService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('guest_checkin_url', $this->url(...)),
            new TwigFunction('guest_checkin_qr', $this->qr(...)),
        ];
    }

    public function url(mixed $reservation): string
    {
        if (!$reservation instanceof Reservation) {
            return '';
        }

        if ($this->isEditorPreview()) {
            return $this->linkService->previewUrl() ?? '';
        }

        return $this->linkService->url($reservation) ?? '';
    }

    /** PNG data URI of the link; for PDF templates only, mail clients drop data: images. */
    public function qr(mixed $reservation, int $size = 300): string
    {
        $url = $this->url($reservation);

        return '' === $url ? '' : $this->linkService->qrDataUri($url, $size);
    }

    private function isEditorPreview(): bool
    {
        $route = $this->requestStack->getMainRequest()?->attributes->get('_route');

        return \in_array($route, self::EDITOR_PREVIEW_ROUTES, true);
    }
}
