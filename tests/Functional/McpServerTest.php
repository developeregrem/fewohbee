<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingAccount;
use App\Entity\Appartment;
use App\Entity\BankImportDraft;
use App\Entity\Enum\ApiScope;
use App\Entity\Enum\HousekeepingStatus;
use App\Entity\Enum\McpToolCallOutcome;
use App\Entity\Log;
use App\Entity\McpToolCallLog;
use App\Entity\Notification;
use App\Entity\Price;
use App\Entity\PricePeriod;
use App\Entity\PriceRule;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Role;
use App\Entity\RoomDayStatus;
use App\Entity\User;
use App\Entity\Workflow;
use App\Service\ApiTokenService;
use App\Service\AppSettingsService;
use App\Workflow\WorkflowSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end tests of the MCP endpoint: gatekeeping, authentication, scopes, data minimisation,
 * the booking flow with its notification workflow, and the settings UI.
 */
final class McpServerTest extends WebTestCase
{
    private const MODERN_VERSION = '2026-07-28';
    private const HANDSHAKE_VERSION = '2025-11-25';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->configureMcp(enabled: false, write: false);
    }

    public function testEndpointIsNotFoundWhileSwitchedOff(): void
    {
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);

        $this->callTool($token, 'get_property_overview');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedRequestGetsBearerChallengeOnly(): void
    {
        $this->configureMcp(enabled: true);

        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(401);
        $challenge = (string) $this->client->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('Bearer', $challenge);
        self::assertStringNotContainsString('Basic', $challenge);
    }

    public function testBasicAuthIsRejected(): void
    {
        $this->configureMcp(enabled: true);
        [$user, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);

        $this->client->request('POST', '/mcp', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'PHP_AUTH_USER' => $user->getUserIdentifier(),
            'PHP_AUTH_PW' => $token,
        ], '{}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRestTokenWithoutMcpScopeIsRejected(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null);

        $this->callTool($token, 'get_property_overview');

        self::assertResponseStatusCodeSame(401);
    }

    public function testForeignBrowserOriginIsRejected(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);

        $this->callTool($token, 'get_property_overview', [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testHandshakeClientCanListToolsInASession(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);

        $this->postJson($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::HANDSHAKE_VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'functional-test', 'version' => '1.0'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $sessionId = (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $sessionId);

        $sessionHeaders = ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::HANDSHAKE_VERSION];
        $this->postJson($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionHeaders);
        $this->postJson($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sessionHeaders);

        self::assertResponseIsSuccessful();
        $names = array_column($this->decode()['result']['tools'] ?? [], 'name');
        self::assertContains('search_reservations', $names);
        // Only what the token may call is offered.
        self::assertNotContains('create_reservation', $names);
        self::assertNotContains('get_turnover', $names);
    }

    public function testToolListGrowsWithThePermissionsOfTheToken(): void
    {
        $this->configureMcp(enabled: true, write: true);
        $list = function (array $scopes): array {
            [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ...$scopes]);
            $this->postJson($token, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => ['_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    'io.modelcontextprotocol/clientInfo' => ['name' => 'functional-test', 'version' => '1.0'],
                ]],
            ], ['HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_VERSION, 'HTTP_MCP_METHOD' => 'tools/list']);
            self::assertResponseIsSuccessful();

            return array_column($this->decode()['result']['tools'] ?? [], 'name');
        };

        self::assertSame([], $list([]));
        $writer = $list([ApiScope::RESERVATIONS_WRITE, ApiScope::STATISTICS_READ]);
        self::assertContains('create_reservation', $writer);
        self::assertContains('get_revenue_forecast', $writer);
        self::assertNotContains('search_reservations', $writer);
        self::assertNotContains('update_bank_import_lines', $writer);
    }

    public function testNoNotificationStreamsAreOffered(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);
        $meta = [
            'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $headers = static fn (string $method): array => ['HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_VERSION, 'HTTP_MCP_METHOD' => $method];

        $this->postJson($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => $meta]], $headers('server/discover'));
        self::assertResponseIsSuccessful();
        $capabilities = $this->decode()['result']['capabilities'] ?? [];
        self::assertEmpty($capabilities['tools']['listChanged'] ?? null);

        // A listen stream would pin a PHP worker; it is refused immediately instead.
        $this->postJson($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'subscriptions/listen', 'params' => ['_meta' => $meta]], $headers('subscriptions/listen'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(-32601, $this->decode()['error']['code'] ?? null);
    }

    public function testToolWithoutScopeIsDeniedAndAudited(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS]);

        $result = $this->callTool($token, 'get_property_overview');

        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('reservations:read', $result['content'][0]['text'] ?? '');
        self::assertSame(McpToolCallOutcome::DENIED, $this->latestAudit('get_property_overview')?->getOutcome());
    }

    public function testScopeDoesNotExceedTheOwnersRoles(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);

        $result = $this->callTool($token, 'get_property_overview');

        self::assertTrue($result['isError'] ?? false);
    }

    public function testReservationsWithholdGuestDataUnlessPermitted(): void
    {
        $this->configureMcp(enabled: true, write: true);
        $reservation = $this->bookReservation('Mustermann-Datenschutz');
        $day = $reservation->getStartDate()->format('Y-m-d');

        [, $plainToken] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);
        $withoutGuestData = $this->callTool($plainToken, 'search_reservations', ['start' => $day]);
        $found = $this->findReservation($withoutGuestData, (int) $reservation->getId());
        self::assertNull($found['booker']['name']);
        self::assertNull($found['remark']);
        self::assertStringNotContainsString('Mustermann-Datenschutz', (string) json_encode($withoutGuestData));

        [, $guestToken] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ, ApiScope::GUESTS_READ]);
        $withGuestData = $this->callTool($guestToken, 'search_reservations', ['start' => $day]);
        $found = $this->findReservation($withGuestData, (int) $reservation->getId());
        self::assertStringContainsString('Mustermann-Datenschutz', (string) $found['booker']['name']);
        self::assertSame('Arrives late', $found['remark']['untrusted_text']);
    }

    public function testOperationsReportShowsTheRoomPlanAndSharesNamesOnlyWhenPermitted(): void
    {
        $this->configureMcp(enabled: true, write: true);
        $reservation = $this->bookReservation('Mustermann-Betrieb', '+600 days');
        $day = $reservation->getStartDate()->format('Y-m-d');
        $apartmentId = (int) $reservation->getAppartment()?->getId();

        [$staff, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::OPERATIONS_READ]);
        $em = $this->em();
        $apartment = $em->find(Appartment::class, $apartmentId);
        self::assertInstanceOf(Appartment::class, $apartment);
        $status = new RoomDayStatus();
        $status->setAppartment($apartment);
        $status->setDate(new \DateTimeImmutable($day));
        $status->setHkStatus(HousekeepingStatus::CLEANED);
        $status->setAssignedTo($staff);
        $status->setNote('Extra towels');
        $em->persist($status);
        $em->flush();

        $result = $this->callTool($token, 'get_operations_report', ['start' => $day, 'occupancyTypes' => ['ARRIVAL']]);
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));
        $room = $this->findRoom($result['structuredContent'], $day, $apartmentId);
        self::assertSame('ARRIVAL', $room['occupancy']);
        self::assertSame([(int) $reservation->getId()], $room['reservationIds']);
        self::assertSame('CLEANED', $room['housekeeping']['status']);
        self::assertNull($room['housekeeping']['assignedTo']);
        self::assertNull($room['housekeeping']['note']);
        self::assertContains((int) $reservation->getId(), array_column($result['structuredContent']['reservations'], 'id'));
        self::assertStringNotContainsString('Mustermann-Betrieb', (string) json_encode($result));

        [, $guestToken] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::OPERATIONS_READ, ApiScope::GUESTS_READ]);
        $result = $this->callTool($guestToken, 'get_operations_report', ['start' => $day]);
        $room = $this->findRoom($result['structuredContent'], $day, $apartmentId);
        self::assertSame('Mcp Tester', $room['housekeeping']['assignedTo']);
        self::assertSame('Extra towels', $room['housekeeping']['note']['untrusted_text']);
        self::assertStringContainsString('Mustermann-Betrieb', (string) json_encode($result['structuredContent']['reservations']));
    }

    public function testOperationsReportNeedsTheOperationsRoleAndABoundedRange(): void
    {
        $this->configureMcp(enabled: true);

        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS'], [ApiScope::MCP_ACCESS, ApiScope::OPERATIONS_READ]);
        $result = $this->callTool($token, 'get_operations_report', ['start' => '2026-10-01']);
        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('operations:read', $result['content'][0]['text'] ?? '');

        [, $token] = $this->createUserWithToken(['ROLE_OPERATIONS'], [ApiScope::MCP_ACCESS, ApiScope::OPERATIONS_READ]);
        $result = $this->callTool($token, 'get_operations_report', ['start' => '2026-10-01', 'end' => '2026-10-15']);
        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('14 days', $result['content'][0]['text'] ?? '');

        $result = $this->callTool($token, 'get_operations_report', ['start' => '2026-10-01', 'end' => '2026-10-14']);
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));
        self::assertCount(14, $result['structuredContent']['days']);
    }

    public function testOccupancyForecastAndBookingPaceCountANewBooking(): void
    {
        $this->configureMcp(enabled: true, write: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ, ApiScope::STATISTICS_READ]);
        $arrival = new \DateTimeImmutable('+620 days');
        $range = ['start' => $arrival->format('Y-m-d'), 'end' => $arrival->modify('+2 days')->format('Y-m-d')];
        $pace = fn (string $asOf): array => $this->callTool($token, 'get_booking_pace', $range + ['asOf' => $asOf])['structuredContent'];

        $before = $this->callTool($token, 'get_occupancy_forecast', $range)['structuredContent'];
        $this->bookReservation('Gast-Prognose', '+620 days');
        $after = $this->callTool($token, 'get_occupancy_forecast', $range)['structuredContent'];

        // Two nights booked; the departure day is free again.
        self::assertSame([1, 1, 0], array_map(
            static fn (array $old, array $new): int => $new['booked'] - $old['booked'],
            $before['nights'],
            $after['nights'],
        ));
        self::assertSame(2, $after['totals']['booked'] - $before['totals']['booked']);
        foreach ($after['nights'] as $night) {
            self::assertSame($night['rooms'], $night['booked'] + $night['blocked'] + $night['available']);
        }

        $today = $pace((new \DateTimeImmutable('today'))->format('Y-m-d'));
        $yesterday = $pace((new \DateTimeImmutable('yesterday'))->format('Y-m-d'));
        self::assertSame(2, $today['current']['bookedByCutOff']['roomNights'] - $yesterday['current']['bookedByCutOff']['roomNights']);
        self::assertSame(1, $today['current']['bookedByCutOff']['reservations'] - $yesterday['current']['bookedByCutOff']['reservations']);
        self::assertSame($arrival->modify('-1 year')->format('Y-m-d'), $today['previousYear']['firstNight']);
    }

    public function testRateCalendarAndPriceRulesDescribeThePriceList(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::PRICES_READ]);
        $apartment = $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC']);
        self::assertInstanceOf(Appartment::class, $apartment);
        $origin = $this->em()->getRepository(\App\Entity\ReservationOrigin::class)->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($origin);
        $start = new \DateTimeImmutable('+30 days');
        $end = $start->modify('+29 days');

        $result = $this->callTool($token, 'get_rate_calendar', [
            'apartmentId' => (int) $apartment->getId(),
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'originId' => (int) $origin->getId(),
        ]);
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));
        $periods = $result['structuredContent']['periods'];
        self::assertSame($start->format('Y-m-d'), $periods[0]['firstNight']);
        self::assertSame($end->format('Y-m-d'), $periods[array_key_last($periods)]['lastNight']);
        foreach ($periods as $period) {
            self::assertNotEmpty($period['rates']);
        }

        $rules = $this->callTool($token, 'get_price_rules', ['type' => 'apartment'])['structuredContent'];
        self::assertSame(['apartment'], array_values(array_unique(array_column($rules['prices'], 'type'))));
        self::assertContains($periods[0]['rates'][0]['priceId'], array_column($rules['prices'], 'id'));

        $invalid = $this->callTool($token, 'get_price_rules', ['roomCategoryId' => 999999]);
        self::assertTrue($invalid['isError'] ?? false);
        self::assertStringContainsString('Unknown room category id.', $invalid['content'][0]['text'] ?? '');
    }

    public function testSpecialPriceNeedsAnAdministrator(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS'], [ApiScope::MCP_ACCESS, ApiScope::PRICES_WRITE]);

        $result = $this->callTool($token, 'preview_special_price', $this->specialPriceArguments('+700 days', '+700 days') + ['amount' => 90.0]);

        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('prices:write', $result['content'][0]['text'] ?? '');
    }

    public function testSpecialPriceIsSavedAfterPreviewAndOverwritesOnlyWithConsent(): void
    {
        $this->configureMcp(enabled: true, write: true);
        $reservation = $this->bookReservation('Gast-Messe', '+720 days');
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::PRICES_WRITE]);

        // A new row copied from the year-round single room price, over the booked nights.
        $fair = $this->specialPriceArguments('+720 days', '+722 days') + ['amount' => 99.0];
        $preview = $this->callTool($token, 'preview_special_price', $fair)['structuredContent'];
        self::assertSame([], $preview['conflicts']);
        self::assertContains((int) $reservation->getId(), array_column($preview['affectedReservations']['reservations'], 'id'));
        $created = $this->callTool($token, 'create_special_price', $fair + ['previewToken' => $preview['previewToken']]);
        self::assertFalse($created['isError'] ?? false, (string) json_encode($created));
        $fairRow = $created['structuredContent']['price'];
        self::assertSame(99.0, (float) $fairRow['amount']);
        self::assertSame(99.0, (float) $created['structuredContent']['rates'][0]['rates'][0]['perNight']);
        self::assertTrue($this->callTool($token, 'create_special_price', $fair + ['previewToken' => $preview['previewToken']])['structuredContent']['alreadyCreated']);

        // The booking keeps the price it was promised; the special price is for new bookings.
        $this->em()->clear();
        $booked = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertInstanceOf(Reservation::class, $booked);
        self::assertNotNull($booked->getPricePromise());
        $positions = static::getContainer()->get(\App\Service\InvoiceService::class)->buildAppartmentPositions($booked);
        self::assertNotEmpty($positions);
        foreach ($positions as $position) {
            self::assertNotSame(99.0, (float) $position->getPrice());
        }

        // A second special price inside the first one conflicts and is refused without consent.
        $festival = $this->specialPriceArguments('+721 days', '+721 days', 'Stadtfest') + ['amount' => 120.0];
        $preview = $this->callTool($token, 'preview_special_price', $festival)['structuredContent'];
        self::assertFalse($preview['canApply']);
        self::assertArrayNotHasKey('previewToken', $preview);
        self::assertSame($fairRow['id'], $preview['conflicts'][0]['id']);
        self::assertSame('split', $preview['conflicts'][0]['periods'][0]['whenOverwritten']);
        $refused = $this->callTool($token, 'create_special_price', $festival + ['previewToken' => 'pv1.0.forged']);
        self::assertTrue($refused['isError'] ?? false);

        $preview = $this->callTool($token, 'preview_special_price', $festival + ['overwriteConflicts' => true])['structuredContent'];
        $created = $this->callTool($token, 'create_special_price', $festival + ['overwriteConflicts' => true, 'previewToken' => $preview['previewToken']]);
        self::assertFalse($created['isError'] ?? false, (string) json_encode($created));

        $this->em()->clear();
        $fairPrice = $this->em()->find(Price::class, $fairRow['id']);
        self::assertInstanceOf(Price::class, $fairPrice);
        $ranges = array_map(
            static fn (PricePeriod $period): string => $period->getStart()?->format('Y-m-d').'/'.$period->getEnd()?->format('Y-m-d').'/'.$period->getDescription(),
            $fairPrice->getPricePeriods()->toArray(),
        );
        sort($ranges);
        self::assertSame([
            $fair['firstNight'].'/'.$fair['firstNight'].'/Messe',
            $fair['lastNight'].'/'.$fair['lastNight'].'/Messe',
        ], $ranges);

        // A special price for longer stays on the same nights is no conflict: it wins for those stays.
        $longStays = $this->specialPriceArguments('+721 days', '+721 days', 'Stadtfest lang') + ['amount' => 150.0];
        $longStays['priceId'] = $this->createLongStayRoomPrice($longStays['priceId'], 2);
        $preview = $this->callTool($token, 'preview_special_price', $longStays)['structuredContent'];
        self::assertSame([], $preview['conflicts']);
        self::assertTrue($preview['canApply']);
        $festivalRow = $this->findRowCovering($preview['rowsForOtherStayLengths'], 'Stadtfest');
        self::assertStringStartsWith('Stays of 2 or more nights get the new price', $festivalRow['stays']);

        // Adding a further period to the special row needs no amount; a year-round row refuses it.
        $again = $this->specialPriceArguments('+730 days', '+731 days', 'Messe Herbst');
        $preview = $this->callTool($token, 'preview_special_price', ['priceId' => $fairRow['id']] + $again)['structuredContent'];
        self::assertSame('add_period_to_row', $preview['action']);
        $yearRound = $this->callTool($token, 'preview_special_price', $again);
        self::assertStringContainsString('applies all year', $yearRound['content'][0]['text'] ?? '');
    }

    public function testAssistantPreparesABankImportDraftOfItsOwner(): void
    {
        $this->configureMcp(enabled: true);
        [$owner, $token] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS, ApiScope::BANK_IMPORT_WRITE]);
        [$bankId, $expenseId] = $this->createBankAndExpenseAccount();
        $draftId = $this->createBankImportDraft($owner, $bankId);

        $drafts = $this->callTool($token, 'list_bank_import_drafts')['structuredContent']['drafts'];
        self::assertSame([$draftId], array_column($drafts, 'draftId'));
        self::assertSame('DE…0001', $drafts[0]['bankAccount']['iban']);

        $result = $this->callTool($token, 'get_bank_import_lines', ['draftId' => $draftId]);
        $lines = $result['structuredContent']['lines'];
        self::assertCount(2, $lines);
        self::assertSame('Rechnung Reinigung Mai', $lines[0]['purpose']['untrusted_text']);
        self::assertSame('DE…3456', $lines[0]['counterpartyIban']);
        self::assertStringNotContainsString('DE89370400440532013456', (string) json_encode($result));

        $context = $this->callTool($token, 'get_bank_import_context', ['draftId' => $draftId])['structuredContent'];
        self::assertContains($expenseId, array_column($context['accounts'], 'id'));

        $invalid = $this->callTool($token, 'update_bank_import_lines', ['draftId' => $draftId, 'changes' => [['idx' => 0, 'debitAccountId' => 999999]]]);
        self::assertTrue($invalid['isError'] ?? false);
        self::assertStringContainsString('chart of accounts', $invalid['content'][0]['text'] ?? '');

        $updated = $this->callTool($token, 'update_bank_import_lines', ['draftId' => $draftId, 'changes' => [
            ['idx' => 0, 'debitAccountId' => $expenseId, 'creditAccountId' => $bankId, 'remark' => 'Reinigung Mai'],
            ['idx' => 1, 'ignore' => true],
        ]]);
        self::assertFalse($updated['isError'] ?? false, (string) json_encode($updated));
        self::assertSame(['ready', 'ignored'], array_column($updated['structuredContent']['updated'], 'status'));
        $this->em()->clear();
        $draft = $this->em()->find(BankImportDraft::class, $draftId);
        self::assertInstanceOf(BankImportDraft::class, $draft);
        self::assertSame('Reinigung Mai', $draft->getState()['lines'][0]['userRemark']);

        // Drafts are private: another cash journal user's assistant neither lists nor reads them.
        [, $colleagueToken] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS, ApiScope::BANK_IMPORT_WRITE]);
        self::assertSame([], $this->callTool($colleagueToken, 'list_bank_import_drafts')['structuredContent']['drafts']);
        self::assertTrue($this->callTool($colleagueToken, 'get_bank_import_lines', ['draftId' => $draftId])['isError'] ?? false);
    }

    public function testBankImportToolsNeedTheCashJournalRole(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS'], [ApiScope::MCP_ACCESS, ApiScope::BANK_IMPORT_WRITE]);

        $result = $this->callTool($token, 'list_bank_import_drafts');

        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('bank-import:write', $result['content'][0]['text'] ?? '');
    }

    public function testRevenueForecastValuesReservationsLikeTheirInvoiceInTheDepartureMonth(): void
    {
        $this->configureMcp(enabled: true, write: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::STATISTICS_READ, ApiScope::PRICES_READ]);
        $month = (new \DateTimeImmutable('+642 days'))->format('Y-m');
        $forecast = fn (): array => $this->callTool($token, 'get_revenue_forecast', ['startMonth' => $month])['structuredContent']['months'][0];

        $before = $forecast();
        $reservation = $this->bookReservation('Gast-Forecast', '+640 days');
        $after = $forecast();

        $quote = $this->callTool($token, 'get_price_quote', [
            'apartmentId' => (int) $reservation->getAppartment()?->getId(),
            'arrival' => $reservation->getStartDate()->format('Y-m-d'),
            'departure' => $reservation->getEndDate()->format('Y-m-d'),
            'persons' => $reservation->getPersons(),
            'originId' => (int) $reservation->getReservationOrigin()?->getId(),
        ])['structuredContent'];
        $bookedExtraIds = array_map(static fn (Price $price): int => (int) $price->getId(), $reservation->getPrices()->toArray());
        $bookedExtras = array_sum(array_map(
            static fn (array $extra): float => \in_array((int) $extra['id'], $bookedExtraIds, true) ? (float) $extra['total'] : 0.0,
            $quote['extras'],
        ));

        self::assertSame($month, $after['month']);
        self::assertSame(1, $after['withoutInvoice']['reservations'] - $before['withoutInvoice']['reservations']);
        self::assertEqualsWithDelta((float) $quote['room']['gross'], $after['withoutInvoice']['room'] - $before['withoutInvoice']['room'], 0.005);
        self::assertGreaterThan(0.0, $bookedExtras);
        self::assertEqualsWithDelta($bookedExtras, $after['withoutInvoice']['extras'] - $before['withoutInvoice']['extras'], 0.005);

        // Once invoiced, the reservation is reported separately; a canceled invoice does not count.
        $invoice = $this->createInvoiceFor($reservation);
        $invoiced = $forecast();
        self::assertSame($before['withoutInvoice']['reservations'], $invoiced['withoutInvoice']['reservations']);
        self::assertSame($before['withInvoice']['reservations'] + 1, $invoiced['withInvoice']['reservations']);
        self::assertEqualsWithDelta($after['total'], $invoiced['total'], 0.005);

        // The request above ran in a new kernel: reload the invoice before changing it.
        $invoice = $this->em()->find(\App\Entity\Invoice::class, $invoice->getId());
        self::assertInstanceOf(\App\Entity\Invoice::class, $invoice);
        $invoice->setStatus(\App\Entity\Enum\InvoiceStatus::CANCELED->value);
        $this->em()->flush();
        self::assertSame($after['withoutInvoice']['reservations'], $forecast()['withoutInvoice']['reservations']);
    }

    public function testBookingFlowCreatesReservationNotifiesStaffAndIsIdempotent(): void
    {
        $this->configureMcp(enabled: true, write: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ, ApiScope::RESERVATIONS_WRITE]);
        $args = $this->bookingArguments('Gast-Idempotenz', '+500 days');

        // Without preview there is no booking.
        $withoutPreview = $this->callTool($token, 'create_reservation', $args + ['previewToken' => 'pv1.1.invalid', 'expectedTotal' => 100.0]);
        self::assertTrue($withoutPreview['isError'] ?? false);

        $preview = $this->callTool($token, 'preview_reservation', $args);
        self::assertFalse($preview['isError'] ?? false, (string) json_encode($preview));
        $previewToken = $preview['structuredContent']['previewToken'];
        $confirmation = ['previewToken' => $previewToken, 'expectedTotal' => $preview['structuredContent']['estimatedTotal']];

        // The confirmation only covers exactly the previewed request and price.
        $changed = $this->callTool($token, 'create_reservation', ['bookerLastname' => 'Someone Else'] + $args + $confirmation);
        self::assertTrue($changed['isError'] ?? false);
        $cheaper = $this->callTool($token, 'create_reservation', $args + ['expectedTotal' => $confirmation['expectedTotal'] - 1] + $confirmation);
        self::assertTrue($cheaper['isError'] ?? false);

        $created = $this->callTool($token, 'create_reservation', $args + $confirmation);
        self::assertFalse($created['isError'] ?? false, (string) json_encode($created));
        self::assertTrue($created['structuredContent']['created']);
        $reservationId = (int) $created['structuredContent']['reservation']['id'];

        $em = $this->em();
        $reservation = $em->find(Reservation::class, $reservationId);
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame('Gast-Idempotenz', $reservation->getBooker()?->getLastname());

        $notification = $em->getRepository(Notification::class)->findOneBy(['entityClass' => Reservation::class, 'entityId' => (string) $reservationId]);
        self::assertInstanceOf(Notification::class, $notification, 'Staff must be notified about an assistant booking.');

        $audit = $this->latestAudit('create_reservation');
        self::assertInstanceOf(McpToolCallLog::class, $audit);
        self::assertSame(McpToolCallOutcome::OK, $audit->getOutcome());
        self::assertSame($reservationId, $audit->getDetails()['reservationId'] ?? null);

        $changeLog = $em->getRepository(Log::class)->findOneBy(['entityClass' => Reservation::class, 'entityId' => (string) $reservationId]);
        self::assertSame('mcp', $changeLog?->getChannel());

        // A retry with the same confirmation returns the same reservation instead of a duplicate.
        $retry = $this->callTool($token, 'create_reservation', $args + $confirmation);
        self::assertTrue($retry['structuredContent']['alreadyCreated']);
        self::assertSame($reservationId, $retry['structuredContent']['reservation']['id']);

        // The room is taken now.
        $again = $this->callTool($token, 'preview_reservation', $args);
        self::assertTrue($again['isError'] ?? false);
        self::assertStringContainsString('not available', $again['content'][0]['text'] ?? '');
    }

    public function testBookingWithExtras(): void
    {
        $this->configureMcp(enabled: true, write: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ, ApiScope::RESERVATIONS_WRITE]);
        $breakfast = $this->createMiscPrice('Frühstück MCP-Test', 12.5, defaultActive: true, flat: false);
        $dog = $this->createMiscPrice('Hund MCP-Test', 30.0, defaultActive: false, flat: true);
        $args = $this->bookingArguments('Gast-Extras', '+560 days');

        // Without a choice the preselected extras apply, as in the back-office form.
        $defaults = $this->callTool($token, 'preview_reservation', $args);
        self::assertFalse($defaults['isError'] ?? false, (string) json_encode($defaults));
        $bookedIds = array_column($defaults['structuredContent']['bookedExtras'], 'id');
        self::assertContains($breakfast, $bookedIds);
        self::assertNotContains($dog, $bookedIds);
        self::assertContains($dog, array_column($defaults['structuredContent']['availableExtras'], 'id'));

        // An explicit choice replaces the preselection.
        $args['extras'] = [$dog];
        $preview = $this->callTool($token, 'preview_reservation', $args);
        self::assertSame([$dog], array_column($preview['structuredContent']['bookedExtras'], 'id'));
        self::assertEqualsWithDelta(30.0, $preview['structuredContent']['extrasTotal'], 0.001);

        // The confirmation covers the extras as well.
        $confirmation = ['previewToken' => $preview['structuredContent']['previewToken'], 'expectedTotal' => $preview['structuredContent']['estimatedTotal']];
        $changed = $this->callTool($token, 'create_reservation', ['extras' => [$dog, $breakfast]] + $args + $confirmation);
        self::assertTrue($changed['isError'] ?? false);

        $created = $this->callTool($token, 'create_reservation', $args + $confirmation);
        self::assertFalse($created['isError'] ?? false, (string) json_encode($created));
        self::assertSame([$dog], array_column($created['structuredContent']['reservation']['extras'], 'id'));

        $em = $this->em();
        $em->clear();
        $reservation = $em->find(Reservation::class, (int) $created['structuredContent']['reservation']['id']);
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame([$dog], array_map(static fn ($price): int => (int) $price->getId(), $reservation->getPrices()->toArray()));

        // Only extras that apply to the stay can be booked.
        $invalid = $this->callTool($token, 'preview_reservation', ['extras' => [999999]] + $this->bookingArguments('Gast-Extras-2', '+580 days'));
        self::assertTrue($invalid['isError'] ?? false);
        self::assertStringContainsString('not available', $invalid['content'][0]['text'] ?? '');
    }

    public function testBookingIsRefusedWhileWriteAccessIsSwitchedOff(): void
    {
        $this->configureMcp(enabled: true, write: false);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ, ApiScope::RESERVATIONS_WRITE]);

        $result = $this->callTool($token, 'preview_reservation', $this->bookingArguments('Gast-Aus', '+520 days'));

        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('switched off', $result['content'][0]['text'] ?? '');
    }

    public function testActivationSeedsTheNotificationWorkflowOnce(): void
    {
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $this->submitSettings(enabled: true);
        $this->submitSettings(enabled: false);
        $this->submitSettings(enabled: true);

        $workflows = $this->em()->getRepository(Workflow::class)->findBy(['systemCode' => 'notify_assistant_booking']);
        self::assertCount(1, $workflows);
        self::assertSame('assistant_booking.created', $workflows[0]->getTriggerType());
    }

    public function testSettingsPagePointsUsersToTheirProfile(): void
    {
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/settings/mcp');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/profile/#api-tokens"]'));
        self::assertCount(0, $crawler->filter('#mcp-endpoint'));
    }

    public function testProfileShowsConnectionDetailsOnlyWhileMcpIsOn(): void
    {
        $user = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/');
        self::assertCount(0, $crawler->filter('#mcp-endpoint'));

        $this->configureMcp(enabled: true);
        $crawler = $this->client->request('GET', '/profile/');
        self::assertStringEndsWith('/mcp', (string) $crawler->filter('#mcp-endpoint')->attr('value'));
    }

    public function testAdministratorsSetTheAllowedHosts(): void
    {
        $this->configureMcp(enabled: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);
        $publicHost = ['HTTP_HOST' => 'fewohbee.example.com'];

        // Not listed yet: refused before authentication.
        $this->callTool($token, 'get_property_overview', [], $publicHost);
        self::assertResponseStatusCodeSame(403);

        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/settings/mcp');
        $form = $crawler->filter('form[name="mcp_settings"]')->form();
        $form['mcp_settings[allowedHosts]']->setValue("https://FeWoHBee.example.com/mcp\nlocalhost");
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/mcp');

        $this->em()->clear();
        self::assertSame(['fewohbee.example.com'], $this->em()->getRepository(\App\Entity\AppSettings::class)->findOneBy([])?->getMcpAllowedHosts());

        $result = $this->callTool($token, 'get_property_overview', [], $publicHost);
        self::assertResponseIsSuccessful();
        self::assertFalse($result['isError'] ?? false);
    }

    public function testInvalidHostsAreNotSaved(): void
    {
        $this->configureMcp(enabled: true);
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/settings/mcp');
        $form = $crawler->filter('form[name="mcp_settings"]')->form();
        $form['mcp_settings[allowedHosts]']->setValue('fewohbee.example.com *.evil.example');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('*.evil.example', (string) $this->client->getResponse()->getContent());
        $this->em()->clear();
        self::assertSame([], $this->em()->getRepository(\App\Entity\AppSettings::class)->findOneBy([])?->getMcpAllowedHosts());
    }

    public function testSettingsSuggestTheCurrentHost(): void
    {
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/settings/mcp', server: ['HTTP_HOST' => 'hotel.example.org']);

        self::assertSame('hotel.example.org', trim($crawler->filter('textarea[name="mcp_settings[allowedHosts]"]')->text()));
    }

    public function testActionLogIsPaginated(): void
    {
        $this->configureMcp(enabled: true);
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        // Future timestamps put these entries ahead of anything other tests logged.
        $em = $this->em();
        $marker = 'paging_'.bin2hex(random_bytes(4));
        for ($i = 0; $i < 30; ++$i) {
            $em->persist(new McpToolCallLog($marker, McpToolCallOutcome::OK, new \DateTimeImmutable(sprintf('+1 day +%d minutes', $i))));
        }
        $em->flush();
        $total = $em->getRepository(McpToolCallLog::class)->count([]);
        $pages = (int) ceil($total / 25);
        $markedRows = static fn (Crawler $crawler): Crawler => $crawler->filter('tbody tr')->reduce(
            static fn (Crawler $row): bool => str_contains($row->text(), $marker),
        );

        $crawler = $this->client->request('GET', '/settings/mcp');
        self::assertResponseIsSuccessful();
        self::assertCount(25, $crawler->filter('[data-controller="log-pagination"] tbody tr'));
        self::assertCount(25, $markedRows($crawler));
        self::assertSame('1', $crawler->filter('[data-controller="log-pagination"] .pagination .active')->text());

        $crawler = $this->client->request('GET', '/settings/mcp/logs?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(5, $markedRows($crawler));
        self::assertSame('2', $crawler->filter('.pagination .active')->text());

        // A page beyond the end shows the last one.
        $crawler = $this->client->request('GET', '/settings/mcp/logs?page=999');
        self::assertCount($total - ($pages - 1) * 25, $crawler->filter('tbody tr'));
        self::assertSame((string) $pages, $crawler->filter('.pagination .active')->text());
    }

    public function testActionLogIsForAdministratorsOnly(): void
    {
        $this->configureMcp(enabled: true);
        $user = $this->createUserWithToken(['ROLE_RESERVATIONS'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($user);

        $this->client->request('GET', '/settings/mcp/logs');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMcpUiIsHiddenWhileSwitchedOff(): void
    {
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $this->client->request('GET', '/settings/workflows/new');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('assistant_booking.created', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/profile/');
        self::assertCount(0, $crawler->filter('input[name="api_token[kind]"]'));

        $this->configureMcp(enabled: true);

        $this->client->request('GET', '/settings/workflows/new');
        self::assertStringContainsString('assistant_booking.created', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/profile/');
        self::assertCount(2, $crawler->filter('input[name="api_token[kind]"]'));
    }

    public function testABookingIsRefusedWhenThePriceChangedAfterThePreview(): void
    {
        $this->configureMcp(enabled: true, write: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_WRITE]);
        $args = $this->bookingArguments('Gast-Preisregel', '+760 days');
        $preview = $this->callTool($token, 'preview_reservation', $args)['structuredContent'];

        // A price rule for exactly these nights appears between preview and confirmation.
        $rule = new PriceRule();
        $rule->setName('mcp-test');
        $rule->setPercent(20.0);
        $rule->setPeriod(new \DateTimeImmutable($args['arrival']), new \DateTimeImmutable($args['departure']));
        $this->em()->persist($rule);
        $this->em()->flush();

        try {
            $created = $this->callTool($token, 'create_reservation', $args + ['previewToken' => $preview['previewToken'], 'expectedTotal' => $preview['estimatedTotal']]);
            self::assertTrue($created['isError'] ?? false);
            self::assertStringContainsString('price has changed', (string) json_encode($created));

            $again = $this->callTool($token, 'preview_reservation', $args)['structuredContent'];
            self::assertGreaterThan($preview['estimatedTotal'], $again['estimatedTotal']);
        } finally {
            $this->em()->remove($this->em()->find(PriceRule::class, $rule->getId()));
            $this->em()->flush();
        }
    }

    public function testCreateReservationOptionFollowsTheGlobalSwitch(): void
    {
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);
        $writeOption = sprintf('input[name="api_token[scopes][]"][value="%s"]', ApiScope::RESERVATIONS_WRITE->value);

        $this->configureMcp(enabled: true, write: false);
        $crawler = $this->client->request('GET', '/profile/');
        self::assertCount(0, $crawler->filter($writeOption));
        self::assertStringContainsString(
            (string) static::getContainer()->get('translator')->trans('profile.apitokens.mcp.write_disabled'),
            $crawler->filter('#apiTokenOffcanvas')->text()
        );

        $this->configureMcp(enabled: true, write: true);
        $crawler = $this->client->request('GET', '/profile/');
        self::assertCount(1, $crawler->filter($writeOption));
    }

    public function testSpecialPriceOptionIsOfferedToAdministratorsOnly(): void
    {
        $option = sprintf('input[name="api_token[scopes][]"][value="%s"]', ApiScope::PRICES_WRITE->value);
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $staff = $this->createUserWithToken(['ROLE_RESERVATIONS'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->configureMcp(enabled: true);

        $this->client->loginUser($admin);
        self::assertCount(1, $this->client->request('GET', '/profile/')->filter($option));

        // Without the role the permission is shown greyed out with its reason and cannot be submitted.
        $this->client->loginUser($staff);
        $crawler = $this->client->request('GET', '/profile/');
        self::assertCount(0, $crawler->filter($option));
        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString(
            (string) $translator->trans('profile.apitokens.unavailable.role', ['%role%' => $translator->trans('ROLE_ADMIN')]),
            $crawler->filter('#apiTokenOffcanvas')->text()
        );
        $this->submitTokenForm([
            'name' => 'Too much',
            'kind' => 'mcp',
            'expiresIn' => '+30 days',
            'scopes' => [ApiScope::RESERVATIONS_READ->value, ApiScope::PRICES_WRITE->value],
        ]);
        self::assertNull($this->em()->getRepository(\App\Entity\ApiToken::class)->findOneBy(['user' => $staff, 'name' => 'Too much']));
    }

    public function testTokenListSumsUpPermissionsInPlainLanguage(): void
    {
        $this->configureMcp(enabled: true);
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ, ApiScope::PRICES_READ, ApiScope::BANK_IMPORT_WRITE], '+30 days')[0];
        $this->client->loginUser($admin);
        $translator = static::getContainer()->get('translator');
        $and = ' '.$translator->trans('profile.apitokens.summary.and').' ';
        $expected = [
            $translator->trans('profile.apitokens.summary.see.mcp', ['%list%' => $translator->trans('profile.apitokens.summary.scope.reservations_read').$and.$translator->trans('profile.apitokens.summary.scope.prices_read')]),
            $translator->trans('profile.apitokens.summary.personal.bank'),
            $translator->trans('profile.apitokens.summary.change.some', ['%list%' => $translator->trans('profile.apitokens.summary.scope.bank_import_write')]),
        ];

        $profile = $this->client->request('GET', '/profile/')->filter('#api-tokens table')->text();
        $settings = $this->client->request('GET', '/settings/mcp')->text();

        foreach ($expected as $sentence) {
            self::assertStringContainsString($sentence, $profile);
            self::assertStringContainsString($sentence, $settings);
        }
        self::assertStringNotContainsString('bank-import:write', $profile);
    }

    public function testAiTokenIsCreatedFromTheProfileDialog(): void
    {
        $this->configureMcp(enabled: true, write: true);
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $this->submitTokenForm([
            'name' => 'Claude',
            'kind' => 'mcp',
            'expiresIn' => '+30 days',
            'scopes' => [ApiScope::RESERVATIONS_READ->value, ApiScope::RESERVATIONS_WRITE->value],
        ]);

        $token = $this->em()->getRepository(\App\Entity\ApiToken::class)->findOneBy(['user' => $admin, 'name' => 'Claude']);
        self::assertNotNull($token);
        self::assertSame(
            [ApiScope::RESERVATIONS_READ->value, ApiScope::RESERVATIONS_WRITE->value, ApiScope::MCP_ACCESS->value],
            $token->getScopes()
        );

        // The one-time display explains the MCP usage, not the REST/calendar one.
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(
            (string) static::getContainer()->get('translator')->trans('profile.apitokens.mcp.usage_hint'),
            $crawler->filter('#api-tokens .alert-success')->text()
        );
    }

    public function testRestTokenIgnoresAiOptions(): void
    {
        $this->configureMcp(enabled: true, write: true);
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $this->submitTokenForm([
            'name' => 'Calendar',
            'kind' => 'api',
            'expiresIn' => '',
            'scopes' => [ApiScope::CALENDAR_READ->value, ApiScope::GUESTS_READ->value],
        ]);

        $token = $this->em()->getRepository(\App\Entity\ApiToken::class)->findOneBy(['user' => $admin, 'name' => 'Calendar']);
        self::assertNotNull($token);
        self::assertSame([ApiScope::CALENDAR_READ->value], $token->getScopes());
    }

    public function testRestApiRejectsAiTokens(): void
    {
        [$user, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);

        $this->client->request('GET', '/api/v1/reservations', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/v1/reservations', [], [], [
            'PHP_AUTH_USER' => $user->getUserIdentifier(),
            'PHP_AUTH_PW' => $token,
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testMcpTokenNeedsAnExpiry(): void
    {
        $this->configureMcp(enabled: true);
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $this->submitTokenForm([
            'name' => 'Claude',
            'kind' => 'mcp',
            'expiresIn' => '',
            'scopes' => [ApiScope::RESERVATIONS_READ->value],
        ]);

        self::assertNull($this->em()->getRepository(\App\Entity\ApiToken::class)->findOneBy(['user' => $admin, 'name' => 'Claude']));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function submitTokenForm(array $fields): void
    {
        $crawler = $this->client->request('GET', '/profile/');
        $form = $crawler->filter('#apiTokenOffcanvas form')->form();
        $values = $form->getPhpValues();
        $values['api_token'] = $fields + ['_token' => $values['api_token']['_token'] ?? ''];
        $this->client->request('POST', (string) $form->getUri(), $values);
        self::assertResponseRedirects();
    }

    private function submitSettings(bool $enabled): void
    {
        $crawler = $this->client->request('GET', '/settings/mcp');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="mcp_settings"]')->form();
        $form['mcp_settings[mcpEnabled]']->setValue($enabled ? '1' : false);
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/mcp');
    }

    private function bookReservation(string $lastname, string $arrivalOffset = '+540 days'): Reservation
    {
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_WRITE]);
        $args = $this->bookingArguments($lastname, $arrivalOffset) + ['remark' => 'Arrives late'];
        $preview = $this->callTool($token, 'preview_reservation', $args);
        self::assertFalse($preview['isError'] ?? false, (string) json_encode($preview));
        $created = $this->callTool($token, 'create_reservation', $args + ['previewToken' => $preview['structuredContent']['previewToken'], 'expectedTotal' => $preview['structuredContent']['estimatedTotal']]);
        self::assertFalse($created['isError'] ?? false, (string) json_encode($created));

        $reservation = $this->em()->find(Reservation::class, (int) $created['structuredContent']['reservation']['id']);
        self::assertInstanceOf(Reservation::class, $reservation);

        return $reservation;
    }

    private function createMiscPrice(string $description, float $amount, bool $defaultActive, bool $flat): int
    {
        $em = $this->em();
        $price = new \App\Entity\Price();
        $price->setActive(true);
        $price->setType(1);
        $price->setAllDays(true);
        $price->setAllPeriods(true);
        $price->setVat(19);
        $price->setPrice($amount);
        $price->setDescription($description);
        $price->setIsPerRoom(false);
        $price->setIsFlatPrice($flat);
        $price->setIsDefaultActiveInReservationCreation($defaultActive);
        foreach ($em->getRepository(\App\Entity\ReservationOrigin::class)->findAll() as $origin) {
            $price->addReservationOrigin($origin);
        }
        $em->persist($price);
        $em->flush();

        return (int) $price->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingArguments(string $lastname, string $arrivalOffset): array
    {
        $em = $this->em();
        $arrival = new \DateTimeImmutable($arrivalOffset);
        $apartment = $em->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC']);
        self::assertInstanceOf(Appartment::class, $apartment);
        $status = $em->getRepository(ReservationStatus::class)->findOneBy(['isBlocking' => true], ['id' => 'ASC']);
        self::assertInstanceOf(ReservationStatus::class, $status);
        $origin = $em->getRepository(\App\Entity\ReservationOrigin::class)->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($origin);

        return [
            'apartmentId' => (int) $apartment->getId(),
            'arrival' => $arrival->format('Y-m-d'),
            'departure' => $arrival->modify('+2 days')->format('Y-m-d'),
            'statusId' => (int) $status->getId(),
            'originId' => (int) $origin->getId(),
            'persons' => 1,
            'bookerLastname' => $lastname,
            'bookerEmail' => strtolower($lastname).'-'.bin2hex(random_bytes(3)).'@example.com',
        ];
    }

    private function createInvoiceFor(Reservation $reservation): \App\Entity\Invoice
    {
        $em = $this->em();
        $invoice = new \App\Entity\Invoice();
        $invoice->setNumber('MCP-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime());
        $invoice->setStatus(\App\Entity\Enum\InvoiceStatus::OPEN->value);
        $invoice->setFirstname('Max');
        $invoice->setLastname('Mustermann');
        $em->persist($invoice);
        $managed = $em->find(Reservation::class, $reservation->getId());
        self::assertInstanceOf(Reservation::class, $managed);
        $managed->addInvoice($invoice);
        $em->flush();

        return $invoice;
    }

    /**
     * @return array{0: int, 1: int} ids of a new bank account and a new expense account
     */
    private function createBankAndExpenseAccount(): array
    {
        $em = $this->em();
        $bank = new AccountingAccount();
        $bank->setAccountNumber((string) random_int(900000, 999999));
        $bank->setName('MCP Testbank');
        $bank->setType(AccountingAccount::TYPE_ASSET);
        $bank->setIsBankAccount(true);
        $bank->setIban('DE00 1234 5678 0000 0000 01');
        $expense = new AccountingAccount();
        $expense->setAccountNumber((string) random_int(700000, 799999));
        $expense->setName('MCP Reinigung');
        $expense->setType(AccountingAccount::TYPE_EXPENSE);
        $em->persist($bank);
        $em->persist($expense);
        $em->flush();

        return [(int) $bank->getId(), (int) $expense->getId()];
    }

    private function createBankImportDraft(User $owner, int $bankAccountId): string
    {
        $line = static fn (int $idx, string $amount, string $name, ?string $iban, string $purpose): array => [
            'idx' => $idx, 'bookDate' => '2026-05-03', 'valueDate' => '2026-05-03', 'amount' => $amount,
            'counterpartyName' => $name, 'counterpartyIban' => $iban, 'purpose' => $purpose,
            'fingerprint' => hash('sha256', $purpose), 'status' => ImportState::LINE_STATUS_PENDING,
            'isIgnored' => false, 'isDuplicate' => false, 'forceImportDuplicate' => false,
            'userDebitAccountId' => null, 'userCreditAccountId' => null, 'userTaxRateId' => null,
            'userRemark' => null, 'userInvoiceNumber' => null, 'appliedRuleId' => null,
            'matchedInvoiceId' => null, 'matchedInvoiceNumber' => null, 'matchedInvoiceAmountMatches' => false,
            'splits' => [],
        ];
        $state = new ImportState(
            sessionImportId: (string) \Symfony\Component\Uid\Uuid::v4(),
            bankAccountId: $bankAccountId,
            fileFormat: 'camt',
            bankCsvProfileId: null,
            originalFilename: 'statement.xml',
            sourceIban: null,
            periodFrom: '2026-05-01',
            periodTo: '2026-05-31',
            createdAt: new \DateTimeImmutable(),
            lines: [
                $line(0, '-119.00', 'Putzteufel GmbH', 'DE89370400440532013456', 'Rechnung Reinigung Mai'),
                $line(1, '-4.90', 'Bank', null, 'Kontofuehrung'),
            ],
        );
        $em = $this->em();
        $em->persist(new BankImportDraft($state->sessionImportId, $owner, $state->toArray()));
        $em->flush();

        return $state->sessionImportId;
    }

    /**
     * A year-round copy of the given room price with another minimum stay.
     */
    private function createLongStayRoomPrice(int $sourceId, int $minStay): int
    {
        $em = $this->em();
        $source = $em->find(Price::class, $sourceId);
        self::assertInstanceOf(Price::class, $source);
        $price = new Price();
        $price->setType(2);
        $price->setActive(true);
        $price->setAllDays(true);
        $price->setAllPeriods(true);
        $price->setVat(7);
        $price->setPrice(30);
        $price->setDescription('Long stay test');
        $price->setIsPerRoom(true);
        $price->setNumberOfPersons($source->getNumberOfPersons());
        $price->setMinStay($minStay);
        foreach ($source->getReservationOrigins() as $origin) {
            $price->addReservationOrigin($origin);
        }
        foreach ($source->getRoomCategories() as $category) {
            $price->addRoomCategory($category);
        }
        $em->persist($price);
        $em->flush();

        return (int) $price->getId();
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function findRowCovering(array $rows, string $descriptionPart): array
    {
        foreach ($rows as $row) {
            if (str_contains((string) $row['description'], $descriptionPart)) {
                return $row;
            }
        }
        self::fail(\sprintf('No row containing "%s".', $descriptionPart));
    }

    /**
     * Special price arguments based on the year-round price of the room bookReservation() uses.
     *
     * @return array{priceId: int, firstNight: string, lastNight: string, periodDescription: string}
     */
    private function specialPriceArguments(string $firstOffset, string $lastOffset, string $label = 'Messe'): array
    {
        $apartment = $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC']);
        self::assertInstanceOf(Appartment::class, $apartment);
        $price = null;
        foreach ($this->em()->getRepository(Price::class)->findBy(['type' => 2, 'allPeriods' => true, 'numberOfPersons' => 1, 'active' => true], ['id' => 'ASC']) as $candidate) {
            if ($candidate->getRoomCategories()->contains($apartment->getRoomCategory())) {
                $price = $candidate;
                break;
            }
        }
        self::assertInstanceOf(Price::class, $price, 'Sample data must contain a year-round price for one person in the first room.');

        return [
            'priceId' => (int) $price->getId(),
            'firstNight' => (new \DateTimeImmutable($firstOffset))->format('Y-m-d'),
            'lastNight' => (new \DateTimeImmutable($lastOffset))->format('Y-m-d'),
            'periodDescription' => $label,
        ];
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function findRoom(array $report, string $day, int $apartmentId): array
    {
        foreach ($report['days'] as $dayReport) {
            if ($dayReport['date'] !== $day) {
                continue;
            }
            foreach ($dayReport['rooms'] as $room) {
                if ($room['apartmentId'] === $apartmentId) {
                    return $room;
                }
            }
        }
        self::fail(\sprintf('Room %d is missing on %s.', $apartmentId, $day));
    }

    /**
     * @param array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function findReservation(array $result, int $id): array
    {
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));
        foreach ($result['structuredContent']['reservations'] as $reservation) {
            if ($id === $reservation['id']) {
                return $reservation;
            }
        }
        self::fail(sprintf('Reservation %d not found.', $id));
    }

    /**
     * Calls a tool through the stateless 2026-07-28 revision.
     *
     * @param array<string, mixed>  $arguments
     * @param array<string, string> $server
     *
     * @return array<string, mixed> the tool result
     */
    private function callTool(string $token, string $name, array $arguments = [], array $server = []): array
    {
        $this->postJson($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => [] === $arguments ? new \stdClass() : $arguments,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    'io.modelcontextprotocol/clientInfo' => ['name' => 'functional-test', 'version' => '1.0'],
                ],
            ],
        ], $server + [
            'HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_VERSION,
            'HTTP_MCP_METHOD' => 'tools/call',
            'HTTP_MCP_NAME' => $name,
        ]);

        if (200 !== $this->client->getResponse()->getStatusCode()) {
            return [];
        }

        return $this->decode()['result'] ?? ['isError' => true, 'content' => [['text' => (string) json_encode($this->decode())]]];
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $server
     */
    private function postJson(string $token, array $message, array $server = []): void
    {
        $this->client->request('POST', '/mcp', [], [], $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], (string) json_encode($message));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function configureMcp(bool $enabled, bool $write = false): void
    {
        $container = static::getContainer();
        $settingsService = $container->get(AppSettingsService::class);
        $settings = $settingsService->getSettings();
        $settings->setMcpEnabled($enabled);
        $settings->setMcpWriteEnabled($enabled && $write);
        $settings->setMcpAllowedHosts([]);
        $settingsService->saveSettings($settings);
        if ($enabled) {
            $container->get(WorkflowSeeder::class)->seedAssistantWorkflows();
        }
    }

    private function latestAudit(string $toolName): ?McpToolCallLog
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(McpToolCallLog::class)->findOneBy(['toolName' => $toolName], ['id' => 'DESC']);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * @param list<string>   $roleCodes
     * @param list<ApiScope> $scopes
     *
     * @return array{0: User, 1: string}
     */
    private function createUserWithToken(array $roleCodes, array $scopes, ?string $expiresIn = '+30 days'): array
    {
        $container = static::getContainer();
        $em = $this->em();
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('mcp_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Mcp');
        $user->setLastname('Tester');
        $user->setEmail(sprintf('mcp+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $em->getRepository(Role::class)->findOneBy(['role' => $roleCode]);
            self::assertNotNull($role, sprintf('Role %s must exist in database.', $roleCode));
            $roles[] = $role;
        }
        $user->setRoleEntities($roles);
        $em->persist($user);
        $em->flush();

        $result = $container->get(ApiTokenService::class)->createToken(
            $user,
            'functional-test',
            array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
            null !== $expiresIn ? new \DateTimeImmutable($expiresIn) : null,
        );

        return [$user, $result->plainToken];
    }
}
