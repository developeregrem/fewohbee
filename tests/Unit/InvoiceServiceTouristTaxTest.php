<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\TouristTaxBreakdown;
use App\Entity\AccountingAccount;
use App\Entity\AppSettings;
use App\Entity\Reservation;
use App\Entity\TaxRate;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\PriceService;
use App\Service\TouristTaxService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceServiceTouristTaxTest extends TestCase
{
    public function testReturnsEmptyWhenNoTouristTaxServiceConfigured(): void
    {
        $service = $this->createService(null);
        self::assertSame([], $service->buildTouristTaxPositions([new Reservation()]));
    }

    public function testBuildsOnePositionPerBreakdownRow(): void
    {
        $r1 = new Reservation();
        $rows = [
            $this->makeBreakdown(1, 'Kurtaxe', 1, 'Erwachsene', 3.0, 2, 2, taxRate: $this->makeTaxRate(7.0)),
            $this->makeBreakdown(1, 'Kurtaxe', 2, 'Kind 6-17', 1.5, 2, 1),
        ];

        $touristTaxService = $this->createMock(TouristTaxService::class);
        $touristTaxService->method('calculateForReservation')->with($r1)->willReturn($rows);

        $service = $this->createService($touristTaxService);
        $positions = $service->buildTouristTaxPositions([$r1]);

        self::assertCount(2, $positions);

        // Adult line: 2 nights × 2 persons = 4 amount, 3.00 unit
        self::assertSame(4, $positions[0]->getAmount());
        self::assertSame('3.00', $positions[0]->getPrice());
        self::assertSame(7.0, $positions[0]->getVat());
        self::assertSame('tourist_tax', $positions[0]->getPositionGroup());

        // Child line: 2 × 1 = 2, 1.50 unit, no taxRate => vat 0.0
        self::assertSame(2, $positions[1]->getAmount());
        self::assertSame('1.50', $positions[1]->getPrice());
        self::assertSame(0.0, $positions[1]->getVat());
        self::assertSame('tourist_tax', $positions[1]->getPositionGroup());
    }

    public function testIncludesVatAndRevenueAccountAreCopied(): void
    {
        $account = new AccountingAccount();
        $row = $this->makeBreakdown(
            taxId: 1, taxName: 'Kurtaxe',
            categoryId: 1, categoryName: 'Erwachsene',
            pricePerNight: 2.0, nights: 1, count: 1,
            includesVat: true, revenueAccount: $account,
        );

        $touristTaxService = $this->createStub(TouristTaxService::class);
        $touristTaxService->method('calculateForReservation')->willReturn([$row]);

        $service = $this->createService($touristTaxService);
        $positions = $service->buildTouristTaxPositions([new Reservation()]);

        self::assertTrue($positions[0]->getIncludesVat());
        self::assertSame($account, $positions[0]->getRevenueAccount());
    }

    public function testTouristTaxDescriptionUsesSingularAndPluralLabels(): void
    {
        $rows = [
            $this->makeBreakdown(1, 'Kurtaxe', 1, 'Erwachsene', 3.0, 1, 1),
            $this->makeBreakdown(1, 'Kurtaxe', 1, 'Erwachsene', 3.0, 2, 2),
        ];

        $touristTaxService = $this->createStub(TouristTaxService::class);
        $touristTaxService->method('calculateForReservation')->willReturn($rows);

        $translator = new Translator('de');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'invoice.tourist_tax.position' => '%tax% - %category% (%nights% x %count%)',
            'invoice.tourist_tax.position.nights' => '{0} %count% Nächte|{1} %count% Nacht|]1,Inf] %count% Nächte',
            'invoice.tourist_tax.position.persons' => '{0} %count% Personen|{1} %count% Person|]1,Inf] %count% Personen',
        ], 'de');

        $service = $this->createService($touristTaxService, $translator);
        $positions = $service->buildTouristTaxPositions([new Reservation()]);

        self::assertSame('Kurtaxe - Erwachsene (1 Nacht x 1 Person)', $positions[0]->getDescription());
        self::assertSame('Kurtaxe - Erwachsene (2 Nächte x 2 Personen)', $positions[1]->getDescription());
    }

    public function testWaivedReservationProducesNoPositions(): void
    {
        $r = new Reservation();

        // Service returns empty (TouristTaxService respects kurtaxeWaived itself).
        $touristTaxService = $this->createMock(TouristTaxService::class);
        $touristTaxService->method('calculateForReservation')->with($r)->willReturn([]);

        $service = $this->createService($touristTaxService);
        self::assertSame([], $service->buildTouristTaxPositions([$r]));
    }

    private function createService(?TouristTaxService $touristTaxService, ?TranslatorInterface $translator = null): InvoiceService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $priceService = $this->createStub(PriceService::class);

        if (null === $translator) {
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(
                fn (string $id, array $params = []) => $id.':'.implode(',', array_values($params))
            );
        }

        $appSettings = new AppSettings();
        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn($appSettings);

        return new InvoiceService($em, $priceService, $translator, $appSettingsService, $touristTaxService);
    }

    private function makeBreakdown(
        int $taxId,
        string $taxName,
        int $categoryId,
        string $categoryName,
        float $pricePerNight,
        int $nights,
        int $count,
        ?string $reportGroup = null,
        ?TaxRate $taxRate = null,
        ?AccountingAccount $revenueAccount = null,
        bool $includesVat = false,
    ): TouristTaxBreakdown {
        return new TouristTaxBreakdown(
            taxId: $taxId,
            taxName: $taxName,
            categoryId: $categoryId,
            categoryName: $categoryName,
            pricePerNight: $pricePerNight,
            nights: $nights,
            count: $count,
            reportGroup: $reportGroup,
            taxRate: $taxRate,
            revenueAccount: $revenueAccount,
            includesVat: $includesVat,
        );
    }

    private function makeTaxRate(float $rate): TaxRate
    {
        $tr = new TaxRate();
        $tr->setName($rate.' %');
        $tr->setRate(number_format($rate, 2, '.', ''));

        return $tr;
    }
}
