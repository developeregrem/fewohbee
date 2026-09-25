<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Repository\GuestCheckInRepository;
use App\Service\PublicUrlService;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Hands out, revokes and resolves online check-in links.
 *
 * A link is created on first use — when a template renders it or staff copy it — so reservations
 * that never get one cost nothing.
 */
class GuestCheckInLinkService
{
    public const ROUTE = 'public.guest_checkin';

    private const QR_MIN_SIZE = 100;
    private const QR_MAX_SIZE = 1000;

    /**
     * Links already built in this process, by reservation id. Templates usually ask twice (the
     * data-if guard and the output), and each lookup would otherwise cost two queries.
     *
     * @var array<int, string|null>
     */
    private array $urls = [];

    public function __construct(
        private readonly GuestCheckInRepository $repository,
        private readonly GuestCheckInConfigService $configService,
        private readonly GuestCheckInPolicy $policy,
        private readonly GuestCheckInTokenSigner $signer,
        private readonly PublicUrlService $publicUrlService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Absolute link for the guest, or null when the reservation cannot be checked in online or
     * no public address is known (a link to an unreachable host must not go out).
     */
    public function url(Reservation $reservation): ?string
    {
        if (!$this->isOfferable($reservation) || null === $this->publicUrlService->getBaseUrl()) {
            return null;
        }

        $id = (int) $reservation->getId();
        if (!\array_key_exists($id, $this->urls)) {
            $selector = $this->repository->ensureSelector($id, $this->signer->newSelector(), $this->clock->now());
            $this->urls[$id] = $this->publicUrlService->generate(self::ROUTE, ['token' => $this->signer->linkToken($selector)]);
        }

        return $this->urls[$id];
    }

    /**
     * Stand-in link for the template editor preview: shows what the link looks like without
     * creating one for whatever reservation the preview happens to use. Null while the feature
     * is off, so the preview hides the block just like the real mail would.
     */
    public function previewUrl(): ?string
    {
        if (!$this->configService->isEnabled()) {
            return null;
        }

        $parameters = ['token' => str_repeat('x', GuestCheckInTokenSigner::TOKEN_LENGTH)];

        return $this->publicUrlService->generate(self::ROUTE, $parameters)
            ?? $this->urlGenerator->generate(self::ROUTE, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function isOfferable(Reservation $reservation): bool
    {
        return GuestCheckInLinkState::UNAVAILABLE !== $this->policy->linkState($reservation, null, $this->configService->isEnabled());
    }

    /** Replaces the selector, so every link sent so far stops working. */
    public function regenerate(Reservation $reservation): GuestCheckIn
    {
        $this->repository->ensureSelector((int) $reservation->getId(), $this->signer->newSelector(), $this->clock->now());
        $checkIn = $this->repository->findOneByReservation($reservation)
            ?? throw new \RuntimeException('Online check-in row missing after creation.');

        $checkIn->replaceSelector($this->signer->newSelector());
        $this->em->flush();
        unset($this->urls[(int) $reservation->getId()]);

        return $checkIn;
    }

    /** The check-in a token belongs to; null for malformed, forged or revoked tokens alike. */
    public function resolve(string $token): ?GuestCheckIn
    {
        $selector = $this->signer->selectorFromLinkToken($token);

        return null === $selector ? null : $this->repository->findOneBySelector($selector);
    }

    /**
     * QR code of a link as PNG data URI. Only for PDFs: mail clients drop data: images.
     *
     * @param int $size edge length in pixels, clamped to 100..1000
     */
    public function qrDataUri(string $url, int $size = 300): string
    {
        return (new Builder(
            writer: new PngWriter(),
            data: $url,
            // Medium keeps a printed confirmation readable after folding, like the payment code.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: max(self::QR_MIN_SIZE, min(self::QR_MAX_SIZE, $size)),
            margin: 0,
        ))->build()->getDataUri();
    }
}
