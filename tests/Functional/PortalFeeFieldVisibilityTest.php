<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\ReservationOrigin;
use App\Entity\User;
use App\Repository\ReservationOriginRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Covers portal-fee field visibility and the price-review notice shown during setup. */
final class PortalFeeFieldVisibilityTest extends WebTestCase
{
    public function testPortalFeeControlsOnlyAppearWhenAnOriginUsesOtaFees(): void
    {
        $client = self::createClient();
        $client->loginUser($this->admin(), 'main');

        [$snapshot, $originId] = $this->disableAllOtaFees();
        $invoiceId = $this->createInvoice();

        try {
            $priceForm = $client->request('GET', '/settings/prices/new');
            self::assertResponseIsSuccessful();
            self::assertCount(0, $priceForm->filter('#brokered-new'));

            $invoiceForm = $client->request('GET', sprintf('/invoices/%d/new/miscellaneous', $invoiceId));
            self::assertResponseIsSuccessful();
            self::assertCount(0, $invoiceForm->filter('#invoice_misc_position_brokered'));

            $origin = $this->em()->find(ReservationOrigin::class, $originId);
            self::assertNotNull($origin);
            $origin->setCommissionPercent('12.00');
            $this->em()->flush();

            $priceForm = $client->request('GET', '/settings/prices/new');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $priceForm->filter('#brokered-new'));

            $invoiceForm = $client->request('GET', sprintf('/invoices/%d/new/miscellaneous', $invoiceId));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $invoiceForm->filter('#invoice_misc_position_brokered'));
        } finally {
            $this->restoreOtaFees($snapshot);
            $invoice = $this->em()->find(Invoice::class, $invoiceId);
            if (null !== $invoice) {
                $this->em()->remove($invoice);
                $this->em()->flush();
            }
        }
    }

    public function testEnablingOtaFeesOncePromptsToReviewExistingPrices(): void
    {
        $client = self::createClient();
        $client->loginUser($this->admin(), 'main');
        $originId = $this->createOriginWithoutFees();

        try {
            $this->submitOrigin($client, $originId, '12.00');
            $overview = $client->request('GET', '/settings/reservationorigin/');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Bei Portalgebühren berücksichtigen', $overview->text());
            self::assertStringContainsString('Prüfe daher die vorhandenen Preise', $overview->text());

            $this->submitOrigin($client, $originId, '12.00');
            $overview = $client->request('GET', '/settings/reservationorigin/');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Prüfe daher die vorhandenen Preise', $overview->text());
        } finally {
            $origin = $this->em()->find(ReservationOrigin::class, $originId);
            if (null !== $origin) {
                $this->em()->remove($origin);
                $this->em()->flush();
            }
        }
    }

    /** @return array{0: array<int, array{commission: ?string, payment: ?string}>, 1: int} */
    private function disableAllOtaFees(): array
    {
        $snapshot = [];
        $origins = $this->origins()->findAll();
        self::assertNotEmpty($origins, 'The prepared database needs a reservation origin.');

        foreach ($origins as $origin) {
            $snapshot[(int) $origin->getId()] = [
                'commission' => $origin->getCommissionPercent(),
                'payment' => $origin->getPaymentFeePercent(),
            ];
            $origin->setCommissionPercent(null);
            $origin->setPaymentFeePercent(null);
        }
        $this->em()->flush();

        return [$snapshot, (int) $origins[0]->getId()];
    }

    /** @param array<int, array{commission: ?string, payment: ?string}> $snapshot */
    private function restoreOtaFees(array $snapshot): void
    {
        foreach ($snapshot as $id => $fees) {
            $origin = $this->em()->find(ReservationOrigin::class, $id);
            if (null === $origin) {
                continue;
            }
            $origin->setCommissionPercent($fees['commission']);
            $origin->setPaymentFeePercent($fees['payment']);
        }
        $this->em()->flush();
    }

    private function createInvoice(): int
    {
        $invoice = new Invoice();
        $invoice->setNumber('PORTAL-FIELDS-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime('2026-09-20'));
        $invoice->setStatus(1);
        $invoice->setLastname('Portal field test');
        $this->em()->persist($invoice);
        $this->em()->flush();

        return (int) $invoice->getId();
    }

    private function createOriginWithoutFees(): int
    {
        $origin = new ReservationOrigin();
        $origin->setName('OTA notice '.bin2hex(random_bytes(4)));
        $this->em()->persist($origin);
        $this->em()->flush();

        return (int) $origin->getId();
    }

    private function submitOrigin(KernelBrowser $client, int $originId, string $commission): void
    {
        $form = $client->request('GET', sprintf('/settings/reservationorigin/%d/get', $originId));
        self::assertResponseIsSuccessful();
        self::assertSame(
            '/settings/reservationorigin/',
            $form->filter(sprintf('#entry-form-%d', $originId))->attr('data-success-url'),
        );
        $token = $form->filter('input[name="_csrf_token"]')->attr('value');
        $origin = $this->em()->find(ReservationOrigin::class, $originId);
        self::assertNotNull($origin);

        $client->request('POST', sprintf('/settings/reservationorigin/%d/edit', $originId), [
            '_csrf_token' => $token,
            'name-'.$originId => $origin->getName(),
            'surcharge-enabled-'.$originId => '1',
            'commission-'.$originId => $commission,
            'payment-fee-'.$originId => '',
            'payment-collection-'.$originId => 'property',
            'tourist-tax-collection-'.$originId => 'property',
        ]);
        self::assertResponseIsSuccessful();
    }

    private function admin(): User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin'])
            ?? throw new \RuntimeException('Prepared test admin missing.');
    }

    private function origins(): ReservationOriginRepository
    {
        return self::getContainer()->get(ReservationOriginRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
