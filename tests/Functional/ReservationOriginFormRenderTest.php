<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ReservationOrigin;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * What the reservation origin form shows, rendered through the real template.
 *
 * Rendered rather than requested, so the two answers can be compared without
 * adding and removing tourist taxes around a live page.
 */
final class ReservationOriginFormRenderTest extends KernelTestCase
{
    public function testTheTouristTaxQuestionIsAskedWhereATouristTaxExists(): void
    {
        self::assertStringContainsString('tourist-tax-collection-7', $this->render(true));
    }

    public function testTheTouristTaxQuestionIsLeftOutWhereNoneIsConfigured(): void
    {
        // Nothing for a portal to collect, so the question has one answer and
        // does not need asking. The form then sends no value and the service
        // falls back to the property.
        $html = $this->render(false);

        self::assertStringNotContainsString('tourist-tax-collection-7', $html);
        // The ordinary payment question stays, it does not depend on a tax.
        self::assertStringContainsString('payment-collection-7', $html);
    }

    public function testTheCollectionOptionsNameTheTwoSides(): void
    {
        $html = $this->render(true);

        self::assertStringContainsString('>Unterkunft<', $html);
        self::assertStringContainsString('>Portal<', $html);
        self::assertStringNotContainsString('dem Haus', $html);
    }

    private function render(bool $hasTouristTax): string
    {
        self::bootKernel();
        $twig = static::getContainer()->get(Environment::class);

        // A numeric id, unlike the "new" the create form uses: the field names
        // carry it, and nothing here turns on which of the two forms renders.
        $origin = new ReservationOrigin();
        $origin->setId(7);
        $origin->setName('Booking.com');

        return $twig->render('ReservationOrigin/reservationorigin_form_input_fields.html.twig', [
            'origin' => $origin,
            'hasTouristTax' => $hasTouristTax,
            'token' => 'test-token',
        ]);
    }
}
