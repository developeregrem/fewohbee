<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Entity\BankCsvProfile;
use App\Form\BankCsvProfileType;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Form\Test\TypeTestCase;

#[AllowMockObjectsWithoutExpectations]
final class BankCsvProfileTypeTest extends TypeTestCase
{
    public function testColumnMapFieldsAreInitializedFromProfile(): void
    {
        $profile = (new BankCsvProfile())
            ->setColumnMap([
                'bookDate'         => 0,
                'amount'           => 8,
                'valueDate'        => 1,
                'purpose'          => 5,
                'counterpartyName' => 4,
                'counterpartyIban' => 7,
                'endToEndId'       => 11,
            ]);

        $form = $this->factory->create(BankCsvProfileType::class, $profile);

        self::assertSame(0, $form->get('col_bookDate')->getData());
        self::assertSame(8, $form->get('col_amount')->getData());
        self::assertSame(1, $form->get('col_valueDate')->getData());
        self::assertSame(5, $form->get('col_purpose')->getData());
        self::assertSame(4, $form->get('col_counterpartyName')->getData());
        self::assertSame(7, $form->get('col_counterpartyIban')->getData());
        self::assertSame(11, $form->get('col_endToEndId')->getData());
        self::assertNull($form->get('col_mandateReference')->getData());
    }

    public function testSubmittedColumnMapFieldsAreStoredOnProfile(): void
    {
        $profile = (new BankCsvProfile())
            ->setName('Old profile')
            ->setColumnMap([
                'bookDate' => 0,
                'amount' => 8,
            ]);

        $form = $this->factory->create(BankCsvProfileType::class, $profile);
        $form->submit([
            'name' => 'Updated profile',
            'description' => '',
            'delimiter' => ';',
            'enclosure' => '"',
            'encoding' => 'UTF-8',
            'headerSkip' => '4',
            'hasHeaderRow' => '1',
            'dateFormat' => 'd.m.y',
            'amountDecimalSeparator' => ',',
            'amountThousandsSeparator' => '.',
            'directionMode' => BankCsvProfile::DIRECTION_SIGNED,
            'ibanSourceLine' => '0',
            'periodSourceLine' => '1',
            'col_bookDate' => '2',
            'col_amount' => '9',
            'col_amountDebit' => '',
            'col_amountCredit' => '',
            'col_valueDate' => '3',
            'col_purpose' => '6',
            'col_counterpartyName' => '5',
            'col_counterpartyIban' => '8',
            'col_endToEndId' => '12',
            'col_mandateReference' => '',
            'col_creditorId' => '',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame([
            'bookDate'         => 2,
            'valueDate'        => 3,
            'counterpartyName' => 5,
            'counterpartyIban' => 8,
            'purpose'          => 6,
            'amount'           => 9,
            'endToEndId'       => 12,
        ], $profile->getColumnMap());
    }
}
