<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\InvoicePosition;
use App\Form\InvoiceMiscPositionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Whether a position added in the invoice form counts towards a portal's fees.
 *
 * Such a position has no price to inherit the answer from, and what the house
 * sells on site is exactly what tends to be typed in there - so the form asks,
 * and commission has to follow the answer.
 */
final class InvoiceMiscPositionBrokeredTest extends KernelTestCase
{
    public function testAPositionSwitchedOffCarriesNeitherFee(): void
    {
        $position = $this->submit(new InvoicePosition(), brokered: false);

        self::assertFalse($position->isBrokered());
        self::assertFalse($position->isCommissionable(), 'commission was still charged on a service sold on site');
    }

    public function testAPositionLeftOnCountsTowardsBoth(): void
    {
        $position = $this->submit(new InvoicePosition(), brokered: true);

        self::assertTrue($position->isBrokered());
        self::assertTrue($position->isCommissionable());
    }

    public function testATouristTaxKeepsItsExemptionFromCommission(): void
    {
        // Editing a tourist-tax position goes through the same form. The portal
        // may well have collected it, but commission is never charged on it.
        $tax = new InvoicePosition();
        $tax->setPositionGroup('tourist_tax');
        $tax->setCommissionable(false);

        $position = $this->submit($tax, brokered: true);

        self::assertTrue($position->isBrokered());
        self::assertFalse($position->isCommissionable());
    }

    private function submit(InvoicePosition $position, bool $brokered): InvoicePosition
    {
        self::bootKernel();
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create(InvoiceMiscPositionType::class, $position, [
                'csrf_protection' => false,
                'show_brokered' => true,
            ]);

        $data = [
            'amount' => '1',
            'description' => 'Frühstück',
            'price' => '12,50',
            'vat' => '7',
        ];
        if ($brokered) {
            $data['brokered'] = '1';
        }
        $form->submit($data);

        self::assertTrue($form->isSynchronized());

        return $position;
    }
}
