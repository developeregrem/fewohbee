<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplatePreview;

use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Service\ReservationService;
use App\Service\TemplatePreview\ReservationEmailTemplatePreviewProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the self-contained fallback data used by reservation template previews.
 */
final class ReservationTemplatePreviewProviderTest extends TestCase
{
    public function testSampleReservationCarriesAConfiguredSubsidiary(): void
    {
        $provider = new ReservationEmailTemplatePreviewProvider(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ReservationService::class),
        );

        $params = $provider->buildPreviewRenderParams(new Template(), []);
        $subsidiary = $params['reservation1']->getAppartment()->getObject();

        self::assertInstanceOf(Subsidiary::class, $subsidiary);
        self::assertNotSame([], $subsidiary->getOpeningHours());
        self::assertNotNull($subsidiary->getOpeningHoursNote());
    }
}
