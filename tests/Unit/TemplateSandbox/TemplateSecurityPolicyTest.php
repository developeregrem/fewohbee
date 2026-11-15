<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateSandbox;

use App\Dto\OriginFee;
use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Reservation;
use App\Entity\User;
use App\Service\TemplateSandbox\TemplateSecurityPolicy;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;

/** Verify the read-only method and property rules of the template sandbox. */
final class TemplateSecurityPolicyTest extends TestCase
{
    #[DataProvider('allowedMethods')]
    public function testReadAccessorsAreAllowed(object $object, string $method): void
    {
        new TemplateSecurityPolicy()->checkMethodAllowed($object, $method);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{object, string}> */
    public static function allowedMethods(): iterable
    {
        yield 'entity getter' => [new Reservation(), 'getRemark'];
        yield 'entity isser' => [new Reservation(), 'isConflict'];
        yield 'entity hasser' => [new Reservation(), 'hasInvoice'];
        yield 'entity string conversion' => [new Reservation(), '__toString'];
        yield 'doctrine proxy of an entity' => [new class extends Reservation {}, 'getRemark'];
        yield 'dto getter' => [new OriginFee(10.0, 100.0), 'getAmount'];
        yield 'date format' => [new \DateTimeImmutable(), 'format'];
        yield 'interval format' => [new \DateInterval('P1D'), 'format'];
        yield 'collection count' => [new ArrayCollection(), 'count'];
        yield 'collection first' => [new ArrayCollection(), 'first'];
    }

    #[DataProvider('deniedMethods')]
    public function testMutatorsServicesAndAccountsAreDenied(object $object, string $method): void
    {
        $this->expectException(SecurityNotAllowedMethodError::class);

        new TemplateSecurityPolicy()->checkMethodAllowed($object, $method);
    }

    /** @return iterable<string, array{object, string}> */
    public static function deniedMethods(): iterable
    {
        yield 'entity setter' => [new Reservation(), 'setRemark'];
        yield 'entity adder' => [new Reservation(), 'addCustomer'];
        yield 'mutator starting with "is"' => [new Reservation(), 'issueInvoice'];
        yield 'mutator starting with "get"' => [new Reservation(), 'getaway'];
        yield 'user getter' => [new User(), 'getPassword'];
        yield 'user string conversion' => [new User(), '__toString'];
        yield 'mutable date' => [new \DateTime(), 'modify'];
        yield 'collection mutation' => [new ArrayCollection(), 'clear'];
        yield 'collection add' => [new ArrayCollection(), 'add'];
        yield 'non-application object' => [new \ArrayObject(), 'getArrayCopy'];
    }

    #[DataProvider('allowedProperties')]
    public function testDataPropertiesAreAllowed(object $object, string $property): void
    {
        new TemplateSecurityPolicy()->checkPropertyAllowed($object, $property);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{object, string}> */
    public static function allowedProperties(): iterable
    {
        yield 'dto property' => [new OriginFee(10.0, 100.0), 'amount'];
        yield 'enum value' => [PaymentMeansCode::CASH, 'value'];
        yield 'enum name' => [PaymentMeansCode::CASH, 'name'];
        yield 'interval days' => [new \DateInterval('P1D'), 'days'];
    }

    #[DataProvider('deniedProperties')]
    public function testOtherPropertiesAreDenied(object $object, string $property): void
    {
        $this->expectException(SecurityNotAllowedPropertyError::class);

        new TemplateSecurityPolicy()->checkPropertyAllowed($object, $property);
    }

    /** @return iterable<string, array{object, string}> */
    public static function deniedProperties(): iterable
    {
        $object = new \stdClass();
        $object->secret = 'x';

        yield 'plain object' => [$object, 'secret'];
        yield 'user' => [new User(), 'password'];
    }
}
