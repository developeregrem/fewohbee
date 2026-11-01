<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use PHPUnit\Framework\TestCase;

final class CustomerEmailsTest extends TestCase
{
    public function testEmailsKeepAddressOrderAndListEachAddressOnce(): void
    {
        $customer = new Customer();
        foreach (['anna@example.org', null, ' ', 'Anna@Example.org', ' work@example.org '] as $email) {
            $customer->addCustomerAddress((new CustomerAddresses())->setEmail($email));
        }

        self::assertSame(['anna@example.org', 'work@example.org'], $customer->getEmails());
    }

    public function testACustomerWithoutAnyEmailHasNone(): void
    {
        $customer = new Customer();
        $customer->addCustomerAddress(new CustomerAddresses());

        self::assertSame([], $customer->getEmails());
    }
}
