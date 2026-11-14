<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Customer;
use App\Repository\GuestCheckInRepository;
use App\Repository\PostalCodeDataRepository;
use App\Service\Localization\GuestLocaleService;
use App\Service\CustomerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomerServiceNationalityTest extends TestCase
{
    public function testKnownCountryCodeIsStoredInUpperCase(): void
    {
        $customer = $this->service()->getCustomerFromForm($this->request(['nationality-new' => 'at']));

        self::assertSame('AT', $customer->getNationality());
    }

    public function testUnknownCountryCodeIsDropped(): void
    {
        $customer = $this->service()->getCustomerFromForm($this->request(['nationality-new' => 'XX']));

        self::assertNull($customer->getNationality());
    }

    public function testFormsWithoutTheFieldKeepTheStoredNationality(): void
    {
        $existing = new Customer();
        $existing->setNationality('DE');
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($existing);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $service = new CustomerService($em, $this->createStub(PostalCodeDataRepository::class), $this->createStub(GuestCheckInRepository::class), $this->createStub(GuestLocaleService::class));
        $customer = $service->getCustomerFromForm($this->request([], '7'), '7');

        self::assertSame('DE', $customer->getNationality());
    }

    public function testMailLanguageIsStoredAndTheInstallationLanguageMeansNone(): void
    {
        $guestLocales = $this->createStub(GuestLocaleService::class);
        $guestLocales->method('enabledLocales')->willReturn(['de', 'en']);
        $guestLocales->method('baseLocale')->willReturn('de');
        $service = new CustomerService($this->createStub(EntityManagerInterface::class), $this->createStub(PostalCodeDataRepository::class), $this->createStub(GuestCheckInRepository::class), $guestLocales);

        self::assertSame('en', $service->getCustomerFromForm($this->request(['locale-new' => 'en']))->getLocale());
        self::assertNull($service->getCustomerFromForm($this->request(['locale-new' => 'de']))->getLocale());
        self::assertNull($service->getCustomerFromForm($this->request(['locale-new' => 'xx']))->getLocale());
    }

    private function service(): CustomerService
    {
        return new CustomerService($this->createStub(EntityManagerInterface::class), $this->createStub(PostalCodeDataRepository::class), $this->createStub(GuestCheckInRepository::class), $this->createStub(GuestLocaleService::class));
    }

    /** @param array<string, string> $fields */
    private function request(array $fields, string $id = 'new'): Request
    {
        return new Request(request: $fields + [
            'salutation-'.$id => 'Mr',
            'firstname-'.$id => 'Jan',
            'lastname-'.$id => 'Novak',
            'birthday-'.$id => '',
            'id-type-'.$id => '',
            'id-'.$id => '',
            'remark-'.$id => '',
        ]);
    }
}
