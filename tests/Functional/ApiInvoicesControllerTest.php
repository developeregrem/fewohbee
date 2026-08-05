<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\ApiScope;
use App\Entity\Invoice;
use App\Entity\Role;
use App\Entity\User;
use App\Service\ApiTokenService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiInvoicesControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/invoices');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenWithoutInvoiceScopeReturns403(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_INVOICES'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/invoices', $plainToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testScopeWithoutInvoiceRoleReturns403(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::INVOICES_READ->value]);

        $this->requestWithBearer($client, '/api/v1/invoices', $plainToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListReturnsInvoicesWithTotalsAndLineItems(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_INVOICES'], [ApiScope::INVOICES_READ->value]);
        $invoice = $this->getAnyInvoice();
        $date = $invoice->getDate()->format('Y-m-d');

        $this->requestWithBearer($client, sprintf('/api/v1/invoices?start=%s&end=%s', $date, $date), $plainToken);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload['data'] ?? [], 'Expected at least the fixture invoice.');
        $numbers = array_column($payload['data'], 'number');
        self::assertContains($invoice->getNumber(), $numbers);

        $row = $payload['data'][0];
        foreach (['id', 'number', 'date', 'status', 'totals', 'vatRates', 'apartments', 'positions', 'reservations'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        foreach (['gross', 'net', 'vat'] as $key) {
            self::assertArrayHasKey($key, $row['totals']);
        }
        // gross = net + vat must hold for every invoice.
        self::assertEqualsWithDelta($row['totals']['gross'], $row['totals']['net'] + $row['totals']['vat'], 0.01);
    }

    public function testPaymentCredentialsAreNeverExposed(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_INVOICES'], [ApiScope::INVOICES_READ->value]);
        $invoice = $this->getAnyInvoice();

        $this->requestWithBearer($client, sprintf('/api/v1/invoices/%d', $invoice->getId()), $plainToken);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        foreach (['cardNumber', 'customerIBAN', 'cardHolder', 'mandateReference'] as $field) {
            self::assertStringNotContainsString($field, $content);
        }
    }

    public function testDetailUnknownInvoiceReturns404(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_INVOICES'], [ApiScope::INVOICES_READ->value]);

        $this->requestWithBearer($client, '/api/v1/invoices/999999', $plainToken);

        self::assertResponseStatusCodeSame(404);
    }

    public function testMalformedDateAndTooLargeRangeReturn400(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_INVOICES'], [ApiScope::INVOICES_READ->value]);

        $this->requestWithBearer($client, '/api/v1/invoices?start=nope', $plainToken);
        self::assertResponseStatusCodeSame(400);

        $this->requestWithBearer($client, '/api/v1/invoices?start=2024-01-01&end=2026-12-31', $plainToken);
        self::assertResponseStatusCodeSame(400);

        $this->requestWithBearer($client, '/api/v1/invoices?status=99', $plainToken);
        self::assertResponseStatusCodeSame(400);
    }

    public function testReservationsExposeInvoiceRefsOnlyWithInvoiceScope(): void
    {
        // With both scopes: the linked invoice shows up as a compact reference.
        $client = static::createClient();
        [, $bothToken] = $this->createUserWithToken(
            ['ROLE_RESERVATIONS_RO', 'ROLE_INVOICES'],
            [ApiScope::RESERVATIONS_READ->value, ApiScope::INVOICES_READ->value]
        );

        // Read everything needed up front: the second half of this test restarts
        // the kernel, which detaches the entity from its entity manager.
        $invoice = $this->getInvoiceWithReservation();
        $invoiceId = (int) $invoice->getId();
        $invoiceNumber = $invoice->getNumber();
        $day = $invoice->getReservations()->first()->getStartDate()->format('Y-m-d');
        $uri = sprintf('/api/v1/reservations?start=%s&end=%s', $day, $day);

        $this->requestWithBearer($client, $uri, $bothToken);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $found = null;
        foreach ($payload['data'] as $row) {
            foreach ($row['invoices'] ?? [] as $ref) {
                if ($ref['id'] === $invoiceId) {
                    $found = $ref;
                }
            }
        }
        self::assertNotNull($found, 'Linked invoice must be listed on the reservation.');
        self::assertSame($invoiceNumber, $found['number']);
        self::assertArrayHasKey('code', $found['status']);
        self::assertArrayNotHasKey('totals', $found, 'Reservation refs must stay metadata-only.');

        // Without the invoice scope: null instead of a list, so callers can tell
        // "not permitted" apart from "no invoices".
        self::ensureKernelShutdown();
        $client = static::createClient();
        [, $resOnlyToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);
        $this->requestWithBearer($client, $uri, $resOnlyToken);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload['data']);
        foreach ($payload['data'] as $row) {
            self::assertNull($row['invoices']);
        }
    }

    private function getAnyInvoice(): Invoice
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $invoice = $em->getRepository(Invoice::class)->findOneBy([]);
        self::assertNotNull($invoice, 'An invoice must exist in fixtures.');

        return $invoice;
    }

    private function getInvoiceWithReservation(): Invoice
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        foreach ($em->getRepository(Invoice::class)->findAll() as $invoice) {
            if (!$invoice->getReservations()->isEmpty()) {
                return $invoice;
            }
        }
        self::fail('An invoice linked to a reservation must exist in fixtures.');
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
