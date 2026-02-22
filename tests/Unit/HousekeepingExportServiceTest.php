<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\HousekeepingStatus;
use App\Entity\RoomDayStatus;
use App\Entity\User;
use App\Service\HousekeepingExportService;
use App\Service\HousekeepingViewService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HousekeepingExportServiceTest extends TestCase
{
    public function testBuildDayCsvResponseWritesHeaderAndRow(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $viewService = $this->createStub(HousekeepingViewService::class);
        $viewService->method('getOccupancyLabels')->willReturn([
            'FREE' => 'housekeeping.occupancy.free',
        ]);
        $viewService->method('getStatusLabels')->willReturn([
            'OPEN' => 'housekeeping.status.open',
        ]);

        $export = new HousekeepingExportService($translator, $viewService);

        $apartment = new Appartment();
        $apartment->setNumber('101');
        $apartment->setDescription('Test Room');

        $status = new RoomDayStatus();
        $status->setAppartment($apartment);
        $status->setDate(new \DateTimeImmutable('2024-01-05'));
        $status->setHkStatus(HousekeepingStatus::OPEN);
        $status->setAssignedTo((new User())->setFirstname('Sam')->setLastname('Bee'));
        $status->setNote('Note');

        $dayView = [
            'date' => new \DateTimeImmutable('2024-01-05'),
            'rows' => [[
                'apartment' => $apartment,
                'occupancyType' => 'FREE',
                'guestCount' => 2,
                'reservationSummary' => 'Company',
                'status' => $status,
            ]],
        ];

        $response = $export->buildDayCsvResponse($dayView, 'all', 'en');

        $output = $this->captureStreamedResponse($response);

        self::assertStringContainsString('housekeeping.date', $output);
        self::assertStringContainsString('101 Test Room', $output);
        self::assertStringContainsString('Company', $output);
        self::assertStringContainsString('Sam Bee', $output);
    }

    private function captureStreamedResponse($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
