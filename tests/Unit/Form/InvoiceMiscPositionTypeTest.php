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
    public function testSubmitValidatesPosition(string $price, string $amount, string $vat, bool $valid, ?string $invalidField, bool $existing = false): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
        $position = new InvoicePosition();
        if ($existing) {
            $position->setVat(19);
        }
        $form = $factory->create(InvoiceMiscPositionType::class, $position, ['show_brokered' => false]);

        $form->submit([
            'description' => 'Room discount',
            'amount' => $amount,
            'price' => $price,
            'vat' => $vat,
        ]);

        self::assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
        if ($valid) {
            self::assertEquals((float) $price, $position->getPrice());
            self::assertSame((float) $vat, $position->getVat());
        } else {
            self::assertNotNull($invalidField);
            self::assertGreaterThan(0, $form->get($invalidField)->getErrors()->count());
        }
    }

    public function testHiddenPortalFeeFieldPreservesTheStoredAnswer(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
        $position = (new InvoicePosition())->markBrokered(false);
        $form = $factory->create(InvoiceMiscPositionType::class, $position, ['show_brokered' => false]);

        self::assertFalse($form->has('brokered'));
        $form->submit([
            'description' => 'Breakfast sold at reception',
            'amount' => '1',
            'price' => '12.50',
            'vat' => '7',
        ]);

        self::assertFalse($position->isBrokered());
        self::assertFalse($position->isCommissionable());
    }

    /** @return iterable<string, array{string, string, string, bool, ?string, 5?: bool}> */
    public static function positionInputs(): iterable
    {
        yield 'negative price' => ['-100', '1', '7', true, null];
        yield 'negative cents' => ['-0.01', '1', '7', true, null];
        yield 'zero price' => ['0', '1', '7', true, null];
        yield 'positive price' => ['150', '1', '7', true, null];
        yield 'invalid price' => ['invalid', '1', '7', false, 'price'];
        yield 'missing price' => ['', '1', '7', false, 'price'];
        yield 'missing quantity' => ['10', '', '7', false, 'amount'];
        yield 'negative quantity' => ['-100', '-1', '7', false, 'amount'];
        yield 'zero quantity' => ['-100', '0', '7', false, 'amount'];
        foreach ([false, true] as $existing) {
            $context = $existing ? 'existing' : 'new';
            yield $context.' missing VAT rate' => ['10', '1', '', false, 'vat', $existing];
            yield $context.' zero VAT rate' => ['10', '1', '0', true, null, $existing];
            yield $context.' fractional VAT rate' => ['10', '1', '7.5', true, null, $existing];
        }
        yield 'negative VAT rate' => ['-100', '1', '-7', false, 'vat'];
    }
}
