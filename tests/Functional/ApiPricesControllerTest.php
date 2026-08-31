<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\Price;
use App\Entity\Role;
use App\Entity\User;
use App\Service\ApiTokenService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiPricesControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = static::createClient();
        foreach (['/api/v1/prices', '/api/v1/prices/quote', '/api/v1/prices/rates'] as $uri) {
            $client->request('GET', $uri);
            self::assertResponseStatusCodeSame(401, $uri);
        }
    }

    public function testTokenWithoutPriceScopeReturns403(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/prices', $token);

        self::assertResponseStatusCodeSame(403);
    }

    public function testScopeWithoutReservationRoleReturns403(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_INVOICES'], [ApiScope::PRICES_READ->value]);

        $this->requestWithBearer($client, '/api/v1/prices', $token);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCatalogueListsConfiguredPriceRows(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);

        $this->requestWithBearer($client, '/api/v1/prices?type=apartment', $token);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload['data'], 'Sample data must contain apartment prices.');

        $row = $payload['data'][0];
        foreach ([
            'id', 'type', 'description', 'price', 'vat', 'includesVat', 'isFlatPrice', 'isPerRoom',
            'numberOfPersons', 'minStay', 'active', 'roomCategory', 'origins', 'allDays', 'weekdays',
            'allPeriods', 'periods', 'isBookableOnline', 'isMandatoryOnline', 'isPackage', 'components',
        ] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame('apartment', $row['type']);
        self::assertArrayHasKey('monday', $row['weekdays']);
        self::assertNotEmpty($row['origins'], 'A price without an origin can never be selected.');

        // The type filter must actually filter.
        foreach ($payload['data'] as $price) {
            self::assertSame('apartment', $price['type']);
        }
    }

    public function testCatalogueRejectsUnknownType(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);

        $this->requestWithBearer($client, '/api/v1/prices?type=nonsense', $token);

        self::assertResponseStatusCodeSame(400);
    }

    public function testQuoteComputesRoomTotalWithConsistentVatSplit(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);
        $fixture = $this->findPricedApartment();

        $this->requestWithBearer($client, $this->quoteUri($fixture, 3), $token);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $data = $payload['data'];

        self::assertTrue($data['priceFound'], 'A price is configured for this category and occupancy.');
        self::assertSame(3, $data['nightCount']);
        self::assertSame($fixture['persons'], $data['persons']);
        self::assertNotEmpty($data['nights']);
        self::assertGreaterThan(0.0, $data['room']['gross']);
        self::assertEqualsWithDelta(
            $data['room']['gross'],
            $data['room']['net'] + $data['room']['vat'],
            0.01,
            'gross must equal net + vat.'
        );
        self::assertEqualsWithDelta(
            $data['room']['gross'],
            array_sum(array_column($data['vatRates'], 'gross')),
            0.01
        );

        // Night stretches carry the actual night span, not the billed unit count.
        $spanned = array_sum(array_column($data['nights'], 'nights'));
        self::assertSame(3, $spanned);
    }

    public function testQuoteWithoutTouristTaxScopeNullsTouristTaxAndGrandTotal(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);
        $fixture = $this->findPricedApartment();

        $this->requestWithBearer($client, $this->quoteUri($fixture, 2), $token);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        // null, not [] or 0.0 — "not permitted" must stay distinguishable from "none applies".
        self::assertNull($payload['data']['touristTax']);
        self::assertNull($payload['data']['grandTotal']);
        self::assertFalse($payload['meta']['touristTaxIncluded']);
    }

    public function testQuoteWithTouristTaxScopeReturnsTouristTaxBlock(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(
            ['ROLE_RESERVATIONS_RO', 'ROLE_OPERATIONS'],
            [ApiScope::PRICES_READ->value, ApiScope::TOURIST_TAX_READ->value]
        );
        $fixture = $this->findPricedApartment();

        $this->requestWithBearer($client, $this->quoteUri($fixture, 2), $token);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $data = $payload['data'];
        self::assertIsArray($data['touristTax']);
        self::assertArrayHasKey('total', $data['touristTax']);
        self::assertArrayHasKey('items', $data['touristTax']);
        self::assertTrue($payload['meta']['touristTaxIncluded']);
        self::assertEqualsWithDelta(
            $data['room']['gross'] + $data['extrasTotal'] + $data['touristTax']['total'],
            $data['grandTotal'],
            0.01
        );
    }

    public function testQuoteWithoutMatchingPriceReportsPriceNotFoundInsteadOfZero(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);
        $fixture = $this->findPricedApartment();

        // numberOfPersons is matched exactly, so a wildly oversized party has no price row.
        $uri = sprintf(
            '/api/v1/prices/quote?apartmentId=%d&start=%s&end=%s&persons=99&originId=%d',
            $fixture['apartmentId'],
            $fixture['start'],
            $fixture['end'],
            $fixture['originId'],
        );
        $this->requestWithBearer($client, $uri, $token);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertFalse($data['priceFound'], 'No price row applies for this occupancy.');
        // JSON carries no int/float distinction — 0.0 arrives as 0.
        self::assertEqualsWithDelta(0.0, $data['room']['gross'], 0.001);
        self::assertSame([], $data['nights']);
    }

    public function testQuoteRejectsInvalidParameters(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);
        $fixture = $this->findPricedApartment();

        $cases = [
            // missing apartmentId
            sprintf('/api/v1/prices/quote?start=%s&end=%s&persons=1', $fixture['start'], $fixture['end']),
            // unknown apartment
            sprintf('/api/v1/prices/quote?apartmentId=999999&start=%s&end=%s&persons=1', $fixture['start'], $fixture['end']),
            // departure before arrival
            sprintf('/api/v1/prices/quote?apartmentId=%d&start=%s&end=%s&persons=1', $fixture['apartmentId'], $fixture['end'], $fixture['start']),
            // malformed date
            sprintf('/api/v1/prices/quote?apartmentId=%d&start=nope&end=%s&persons=1', $fixture['apartmentId'], $fixture['end']),
            // unknown origin
            sprintf('/api/v1/prices/quote?apartmentId=%d&start=%s&end=%s&persons=1&originId=999999', $fixture['apartmentId'], $fixture['start'], $fixture['end']),
            // unknown guest category
            sprintf('/api/v1/prices/quote?apartmentId=%d&start=%s&end=%s&guestCounts[999999]=2&originId=%d', $fixture['apartmentId'], $fixture['start'], $fixture['end'], $fixture['originId']),
        ];

        foreach ($cases as $uri) {
            $this->requestWithBearer($client, $uri, $token);
            self::assertResponseStatusCodeSame(400, $uri);
        }
    }

    public function testRateCalendarReturnsOneEntryPerNight(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);
        $fixture = $this->findPricedApartment();

        $lastNight = (new \DateTimeImmutable($fixture['start']))->modify('+4 days')->format('Y-m-d');
        $uri = sprintf(
            '/api/v1/prices/rates?roomCategoryId=%d&start=%s&end=%s&originId=%d',
            $fixture['roomCategoryId'],
            $fixture['start'],
            $lastNight,
            $fixture['originId'],
        );
        $this->requestWithBearer($client, $uri, $token);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(5, $payload['data'], 'Both boundary nights are inclusive.');
        self::assertSame($fixture['start'], $payload['data'][0]['date']);
        self::assertSame($lastNight, $payload['data'][4]['date']);
        self::assertContains($fixture['persons'], $payload['meta']['occupancies']);

        $rates = $payload['data'][0]['rates'];
        self::assertNotEmpty($rates, 'The fixture price applies on every day.');
        foreach ($rates as $rate) {
            foreach (['occupancy', 'priceId', 'pricingModel', 'unitPrice', 'perNight', 'stayPrice', 'vat', 'includesVat'] as $key) {
                self::assertArrayHasKey($key, $rate);
            }
            if ('flat' === $rate['pricingModel']) {
                self::assertNull($rate['perNight'], 'A flat price has no per-night figure.');
            } else {
                self::assertNotNull($rate['perNight']);
                self::assertNull($rate['stayPrice']);
            }
        }
    }

    public function testRateCalendarRejectsOversizedRange(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);
        $fixture = $this->findPricedApartment();

        $uri = sprintf(
            '/api/v1/prices/rates?roomCategoryId=%d&start=2026-01-01&end=2028-01-01&originId=%d',
            $fixture['roomCategoryId'],
            $fixture['originId'],
        );
        $this->requestWithBearer($client, $uri, $token);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRateCalendarRequiresACategory(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::PRICES_READ->value]);
        $fixture = $this->findPricedApartment();

        $this->requestWithBearer($client, sprintf('/api/v1/prices/rates?originId=%d', $fixture['originId']), $token);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function quoteUri(array $fixture, int $nights): string
    {
        $end = (new \DateTimeImmutable($fixture['start']))->modify('+'.$nights.' days')->format('Y-m-d');

        return sprintf(
            '/api/v1/prices/quote?apartmentId=%d&start=%s&end=%s&persons=%d&originId=%d',
            $fixture['apartmentId'],
            $fixture['start'],
            $end,
            $fixture['persons'],
            $fixture['originId'],
        );
    }

    /**
     * An apartment whose room category has an active apartment price, plus the occupancy
     * and origin that price is bound to. Returned as scalars — the entities would be
     * detached by the kernel restarts these tests do.
     *
     * @return array{apartmentId: int, roomCategoryId: int, persons: int, originId: int, start: string, end: string}
     */
    private function findPricedApartment(): array
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        foreach ($em->getRepository(Price::class)->findBy(['type' => 2, 'active' => true]) as $price) {
            $category = $price->getRoomCategory();
            $origin = $price->getReservationOrigins()->first();
            if (null === $category || false === $origin || null === $price->getNumberOfPersons()) {
                continue;
            }
            $apartment = $em->getRepository(Appartment::class)->findOneBy(['roomCategory' => $category]);
            if (!$apartment instanceof Appartment) {
                continue;
            }

            // Far enough out that sample reservations cannot collide with fixture bookings.
            $start = (new \DateTimeImmutable('today'))->modify('+400 days');

            return [
                'apartmentId' => (int) $apartment->getId(),
                'roomCategoryId' => (int) $category->getId(),
                'persons' => (int) $price->getNumberOfPersons(),
                'originId' => (int) $origin->getId(),
                'start' => $start->format('Y-m-d'),
                'end' => $start->modify('+1 day')->format('Y-m-d'),
            ];
        }

        self::fail('Sample data must contain an apartment price with a room category and origin.');
    }

    private function requestWithBearer(KernelBrowser $client, string $uri, string $token): void
    {
        $client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /**
     * @param string[]     $roleCodes
     * @param list<string> $scopes
     *
     * @return array{0: User, 1: string}
     */
    private function createUserWithToken(array $roleCodes, array $scopes): array
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('api_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Api');
        $user->setLastname('Tester');
        $user->setEmail(sprintf('api+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['role' => $roleCode]);
            self::assertNotNull($role, sprintf('Role %s must exist in database.', $roleCode));
            $roles[] = $role;
        }
        $user->setRoleEntities($roles);

        $em->persist($user);
        $em->flush();

        $result = $container->get(ApiTokenService::class)->createToken($user, 'functional-test', $scopes, null);

        return [$user, $result->plainToken];
    }
}
