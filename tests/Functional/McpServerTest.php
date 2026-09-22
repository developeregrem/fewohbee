<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\Enum\McpToolCallOutcome;
use App\Entity\Log;
use App\Entity\McpToolCallLog;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\Workflow;
use App\Service\ApiTokenService;
use App\Service\AppSettingsService;
use App\Workflow\WorkflowSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
        self::assertContains('create_reservation', $names);
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

    public function testBookingFlowCreatesReservationNotifiesStaffAndIsIdempotent(): void
    {
        $this->configureMcp(enabled: true, write: true);
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ, ApiScope::RESERVATIONS_WRITE]);
        $args = $this->bookingArguments('Gast-Idempotenz', '+500 days');

        // Without preview there is no booking.
        $withoutPreview = $this->callTool($token, 'create_reservation', $args + ['previewToken' => 'pv1.1.invalid']);
        self::assertTrue($withoutPreview['isError'] ?? false);

        $preview = $this->callTool($token, 'preview_reservation', $args);
        self::assertFalse($preview['isError'] ?? false, (string) json_encode($preview));
        $previewToken = $preview['structuredContent']['previewToken'];

        // The confirmation only covers exactly the previewed request.
        $changed = $this->callTool($token, 'create_reservation', ['bookerLastname' => 'Someone Else'] + $args + ['previewToken' => $previewToken]);
        self::assertTrue($changed['isError'] ?? false);

        $created = $this->callTool($token, 'create_reservation', $args + ['previewToken' => $previewToken]);
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
        $retry = $this->callTool($token, 'create_reservation', $args + ['previewToken' => $previewToken]);
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
        $changed = $this->callTool($token, 'create_reservation', ['extras' => [$dog, $breakfast]] + $args + ['previewToken' => $preview['structuredContent']['previewToken']]);
        self::assertTrue($changed['isError'] ?? false);

        $created = $this->callTool($token, 'create_reservation', $args + ['previewToken' => $preview['structuredContent']['previewToken']]);
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

    public function testCreateReservationOptionFollowsTheGlobalSwitch(): void
    {
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);
        $writeOption = sprintf('input[name="api_token[mcpScopes][]"][value="%s"]', ApiScope::RESERVATIONS_WRITE->value);

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

    public function testAiTokenIsCreatedFromTheProfileDialog(): void
    {
        $this->configureMcp(enabled: true, write: true);
        $admin = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::RESERVATIONS_READ], null)[0];
        $this->client->loginUser($admin);

        $this->submitTokenForm([
            'name' => 'Claude',
            'kind' => 'mcp',
            'expiresIn' => '+30 days',
            'scopes' => [ApiScope::RESERVATIONS_READ->value],
            'mcpScopes' => [ApiScope::RESERVATIONS_WRITE->value],
        ]);

        $token = $this->em()->getRepository(\App\Entity\ApiToken::class)->findOneBy(['user' => $admin, 'name' => 'Claude']);
        self::assertNotNull($token);
        self::assertSame(
            [ApiScope::RESERVATIONS_READ->value, ApiScope::MCP_ACCESS->value, ApiScope::RESERVATIONS_WRITE->value],
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
            'scopes' => [ApiScope::CALENDAR_READ->value],
            'mcpScopes' => [ApiScope::GUESTS_READ->value],
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

    private function bookReservation(string $lastname): Reservation
    {
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_WRITE]);
        $args = $this->bookingArguments($lastname, '+540 days') + ['remark' => 'Arrives late'];
        $preview = $this->callTool($token, 'preview_reservation', $args);
        self::assertFalse($preview['isError'] ?? false, (string) json_encode($preview));
        $created = $this->callTool($token, 'create_reservation', $args + ['previewToken' => $preview['structuredContent']['previewToken']]);
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
