<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Price;
use App\Entity\PriceComponent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DecimalFieldHydrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    /** @param class-string<InvoiceAppartment|InvoicePosition|Price|PriceComponent> $class */
    #[DataProvider('decimalFields')]
    public function testHydrationDoesNotScheduleAnUpdateButRealChangesAreSaved(string $class, string $field, int $scale): void
    {
        $componentId = null;
        if (PriceComponent::class === $class) {
            $price = $this->em->getRepository(Price::class)->findOneBy([]);
            self::assertInstanceOf(Price::class, $price);
            $component = new PriceComponent();
            $component->setPrice($price);
            $component->setDescription('Decimal hydration regression');
            $component->setVat(7.5);
            $component->setAllocationValue(33.3333);
            $this->em->persist($component);
            $this->em->flush();
            $componentId = $component->getId();
            self::assertNotNull($componentId);
        }

        $this->em->clear();
        $entity = null === $componentId
            ? $this->em->getRepository($class)->findOneBy([])
            : $this->em->find($class, $componentId);
        self::assertInstanceOf($class, $entity);
        $metadata = $this->em->getClassMetadata($class);
        $id = $metadata->getIdentifierValues($entity);
        $getter = 'get'.ucfirst($field);
        $setter = 'set'.ucfirst($field);
        $uow = $this->em->getUnitOfWork();
        $original = $uow->getOriginalEntityData($entity)[$field];
        self::assertIsString($original);
        $value = $entity->$getter();
        self::assertIsFloat($value);
        self::assertSame((float) $original, $value);

        $uow->computeChangeSets();

        self::assertSame([], $uow->getEntityChangeSet($entity), 'Loading a decimal must not turn an unchanged entity into an update.');
        self::assertNotContains($entity, $uow->getScheduledEntityUpdates());
        $this->em->flush();

        $changedValue = round($value + 10 ** -$scale, $scale);
        $entity->$setter($changedValue);
        $this->em->flush();
        $this->em->clear();
        $reloaded = $this->em->find($class, $id);

        self::assertInstanceOf($class, $reloaded);
        self::assertSame($changedValue, $reloaded->$getter());
        self::assertSame(number_format($changedValue, $scale, '.', ''), $this->em->getUnitOfWork()->getOriginalEntityData($reloaded)[$field]);
        $this->em->getUnitOfWork()->computeChangeSets();
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($reloaded));
    }

    /** @return iterable<string, array{class-string<InvoiceAppartment|InvoicePosition|Price|PriceComponent>, string, int}> */
    public static function decimalFields(): iterable
    {
        yield 'room VAT' => [InvoiceAppartment::class, 'vat', 2];
        yield 'miscellaneous VAT' => [InvoicePosition::class, 'vat', 2];
        yield 'price VAT' => [Price::class, 'vat', 2];
        yield 'component VAT' => [PriceComponent::class, 'vat', 2];
        yield 'component allocation' => [PriceComponent::class, 'allocationValue', 4];
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $connection = $this->em->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        parent::tearDown();
    }
}
