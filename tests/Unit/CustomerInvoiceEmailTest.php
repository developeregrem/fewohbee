<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Repository\GuestCheckInRepository;
use App\Repository\PostalCodeDataRepository;
use App\Service\AppSettingsService;
use App\Service\CustomerService;
use App\Service\InvoiceService;
use App\Service\InvoiceSumCalculator;
use App\Service\OriginFeeCalculator;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A contact can name a separate recipient for invoices; confirmations keep using the
 * regular email, and a contact without one behaves as before.
 */
final class CustomerInvoiceEmailTest extends TestCase
{
    public function testInvoicesGoToTheInvoiceEmailWhenOneIsSet(): void
    {
        $address = (new CustomerAddresses())->setEmail('guest@example.com')->setInvoiceEmail('accounts@example.com');

        self::assertSame('accounts@example.com', $address->getEffectiveInvoiceEmail());
        self::assertSame('guest@example.com', $address->getEmail());
    }

    public function testInvoicesFallBackToTheRegularEmail(): void
    {
        $address = (new CustomerAddresses())->setEmail('guest@example.com')->setInvoiceEmail('   ');

        self::assertNull($address->getInvoiceEmail());
        self::assertSame('guest@example.com', $address->getEffectiveInvoiceEmail());
    }

    public function testNoRecipientWhenNeitherEmailIsFilledIn(): void
    {
        self::assertNull((new CustomerAddresses())->setEmail('')->getEffectiveInvoiceEmail());
    }

    public function testCustomerFormStoresTheInvoiceEmail(): void
    {
        $service = new CustomerService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PostalCodeDataRepository::class),
            $this->createStub(GuestCheckInRepository::class),
        );

        $customer = $service->getCustomerFromForm(new Request(request: [
            'salutation-new' => 'Mr',
            'firstname-new' => 'Jan',
            'lastname-new' => 'Novak',
            'birthday-new' => '',
            'id-type-new' => '',
            'id-new' => '',
            'remark-new' => '',
            'addresstype-new' => ['CUSTOMER_ADDRESS_TYPE_BUSINESS'],
            'company-new' => ['Novak GmbH'],
            'address-new' => [''],
            'zip-new' => [''],
            'city-new' => [''],
            'country-new' => ['DE'],
            'phone-new' => [''],
            'fax-new' => [''],
            'mobilephone-new' => [''],
            'email-new' => ['jan@example.com'],
            'invoiceemail-new' => [' accounts@example.com '],
        ]));

        $address = $customer->getCustomerAddresses()->first();
        self::assertInstanceOf(CustomerAddresses::class, $address);
        self::assertSame('jan@example.com', $address->getEmail());
        self::assertSame('accounts@example.com', $address->getInvoiceEmail());
    }

    public function testNewInvoiceIsAddressedToTheInvoiceEmail(): void
    {
        $customer = new Customer();
        $customer->addCustomerAddress(
            (new CustomerAddresses())->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE')
                ->setEmail('jan@example.com')
                ->setInvoiceEmail('accounts@example.com')
        );
        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        $service = $this->invoiceService();
        $service->setDefaultCustomer($customer, $requestStack);

        self::assertSame('accounts@example.com', $service->getInvoiceInCreation($requestStack)->getEmail());
    }

    private function invoiceService(): InvoiceService
    {
        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn(new AppSettings());

        return new InvoiceService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PriceService::class),
            $this->createStub(TranslatorInterface::class),
            $appSettingsService,
            new InvoiceSumCalculator(),
            new OriginFeeCalculator(new InvoiceSumCalculator()),
        );
    }
}
