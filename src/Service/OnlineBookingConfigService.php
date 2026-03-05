<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Repository\OnlineBookingConfigRepository;
use App\Repository\AppartmentRepository;
use Doctrine\ORM\EntityManagerInterface;

class OnlineBookingConfigService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OnlineBookingConfigRepository $repository,
        private readonly AppartmentRepository $appartmentRepository
    ) {
    }

    /** Return the singleton config row and create a default row on first access. */
    public function getConfig(): OnlineBookingConfig
    {
        $config = $this->repository->findSingleton();
        if ($config instanceof OnlineBookingConfig) {
            return $config;
        }

        $config = new OnlineBookingConfig();
        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }

    /** Persist the online booking configuration. */
    public function saveConfig(OnlineBookingConfig $config): void
    {
        $this->em->persist($config);
        $this->em->flush();
    }

    /**
     * Resolve subsidiary IDs from config mode (ALL or SELECTED) and sanitize missing IDs.
     *
     * @return int[]
     */
    public function getAllowedSubsidiaryIds(?OnlineBookingConfig $config = null): array
    {
        $config ??= $this->getConfig();

        if (OnlineBookingConfig::SUBSIDIARIES_MODE_ALL === $config->getSubsidiariesMode()) {
            return array_map(
                static fn (Subsidiary $subsidiary): int => (int) $subsidiary->getId(),
                $this->em->getRepository(Subsidiary::class)->findAll()
            );
        }

        $ids = $config->getSelectedSubsidiaryIds();
        if ([] === $ids) {
            return [];
        }

        return array_values(array_map(
            static fn (Subsidiary $subsidiary): int => (int) $subsidiary->getId(),
            $this->em->getRepository(Subsidiary::class)->findBy(['id' => $ids])
        ));
    }

    /**
     * Resolve room IDs from config mode (ALL or SELECTED) and sanitize missing IDs.
     *
     * @return int[]
     */
    public function getAllowedRoomIds(?OnlineBookingConfig $config = null): array
    {
        $config ??= $this->getConfig();

        if (OnlineBookingConfig::ROOMS_MODE_ALL === $config->getRoomsMode()) {
            return array_map(
                static fn (Appartment $appartment): int => (int) $appartment->getId(),
                $this->em->getRepository(Appartment::class)->findAll()
            );
        }

        $ids = $config->getSelectedRoomIds();
        if ([] === $ids) {
            return [];
        }

        return array_values(array_map(
            static fn (Appartment $appartment): int => (int) $appartment->getId(),
            $this->appartmentRepository->findBy(['id' => $ids])
        ));
    }

    /** Return the configured confirmation template only if it is a reservation email template. */
    public function getConfirmationEmailTemplate(?OnlineBookingConfig $config = null): ?Template
    {
        $config ??= $this->getConfig();
        $templateId = $config->getConfirmationEmailTemplateId();
        if (null === $templateId) {
            return null;
        }

        $template = $this->em->getRepository(Template::class)->find($templateId);
        if (!$template instanceof Template) {
            return null;
        }

        $type = $template->getTemplateType();
        if (null === $type || 'TEMPLATE_RESERVATION_EMAIL' !== $type->getName()) {
            return null;
        }

        return $template;
    }

    /** Resolve the configured inquiry reservation status or null if missing/invalid. */
    public function getInquiryStatus(?OnlineBookingConfig $config = null): ?ReservationStatus
    {
        $config ??= $this->getConfig();
        $id = $config->getInquiryReservationStatusId();

        return null === $id ? null : $this->em->getRepository(ReservationStatus::class)->find($id);
    }

    /** Resolve the configured booking reservation status or null if missing/invalid. */
    public function getBookingStatus(?OnlineBookingConfig $config = null): ?ReservationStatus
    {
        $config ??= $this->getConfig();
        $id = $config->getBookingReservationStatusId();

        return null === $id ? null : $this->em->getRepository(ReservationStatus::class)->find($id);
    }

    /** Resolve the configured reservation origin for public bookings or null if missing/invalid. */
    public function getReservationOrigin(?OnlineBookingConfig $config = null): ?ReservationOrigin
    {
        $config ??= $this->getConfig();
        $id = $config->getReservationOriginId();

        return null === $id ? null : $this->em->getRepository(ReservationOrigin::class)->find($id);
    }
}
