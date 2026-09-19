<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\InvoicePosition;
use App\Form\InvoiceMiscPositionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * Verifies that invoice forms accept deductions while retaining input validation.
 */
final class InvoiceMiscPositionTypeTest extends TestCase
{
    #[DataProvider('positionInputs')]
    public function testSubmitValidatesPosition(string $price, string $amount, string $vat, bool $valid, ?string $invalidField): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
        $position = new InvoicePosition();
        $form = $factory->create(InvoiceMiscPositionType::class, $position);

        $form->submit([
            'description' => 'Room discount',
            'amount' => $amount,
            'price' => $price,
            'vat' => $vat,
        ]);

        self::assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
        if ($valid) {
            self::assertEquals((float) $price, $position->getPrice());
        } else {
            self::assertNotNull($invalidField);
            self::assertGreaterThan(0, $form->get($invalidField)->getErrors()->count());
        }
    }

    /** @return iterable<string, array{string, string, string, bool, ?string}> */
    public static function positionInputs(): iterable
    {
        yield 'negative price' => ['-100', '1', '7', true, null];
        yield 'negative cents' => ['-0.01', '1', '7', true, null];
        yield 'zero price' => ['0', '1', '7', true, null];
        yield 'positive price' => ['150', '1', '7', true, null];
        yield 'invalid price' => ['invalid', '1', '7', false, 'price'];
        yield 'negative quantity' => ['-100', '-1', '7', false, 'amount'];
        yield 'zero quantity' => ['-100', '0', '7', false, 'amount'];
        yield 'negative VAT rate' => ['-100', '1', '-7', false, 'vat'];
    }
}
