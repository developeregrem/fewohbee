<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class ReservationPopoverEscapingTest extends TestCase
{
    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    public function testUserValuesRemainEscapedWhenBootstrapReadsPopoverHtml(): void
    {
        $popoverHtml = $this->renderPopover(['invoices' => [['number' => self::PAYLOAD, 'status' => 1]]]);

        // The e-mail address appears twice, as mailto: target and as link text
        self::assertSame(11, substr_count($popoverHtml, '&lt;img src=x onerror=alert(1)&gt;'));
        // The tel: target drops spaces and brackets, but stays escaped as well
        self::assertStringContainsString("href='tel:&lt;imgsrc=xonerror=alert1&gt;'", $popoverHtml);
        self::assertStringNotContainsString(self::PAYLOAD, $popoverHtml);
    }

    public function testReservationStatusRemainsEscapedWithoutInvoice(): void
    {
        $popoverHtml = $this->renderPopover(['invoices' => []]);

        self::assertStringContainsString('<i>(&lt;img src=x onerror=alert(1)&gt;)</i>', $popoverHtml);
        self::assertStringNotContainsString(self::PAYLOAD, $popoverHtml);
    }

    public function testInvoiceStatusReplacesTheReservationStatus(): void
    {
        $popoverHtml = $this->renderPopover([
            'invoices' => [['number' => 'R-1001', 'status' => 2]],
            'reservationStatus' => ['name' => 'Bezahlt'],
        ]);

        self::assertStringContainsString('R-1001 (invoice.status.paid)', $popoverHtml);
        self::assertStringNotContainsString('<i>(Bezahlt)</i>', $popoverHtml);
    }

    /**
     * Renders the popover body the way Bootstrap reads it from data-bs-content: the
     * attribute is decoded exactly once, so the trusted formatting is HTML while every
     * user value must still be an entity that becomes text via innerHTML.
     *
     * @param array<string, mixed> $overrides
     */
    private function renderPopover(array $overrides): string
    {
        $payload = self::PAYLOAD;
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 3).'/templates'),
            ['autoescape' => 'name'],
        );
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        // This test drives the template with plain arrays and only asserts
        // escaping, so display_name is stubbed down to the raw name.
        $twig->addFilter(new TwigFilter('display_name', static fn (array $status): string => $status['name']));

        $rendered = $twig->render('Reservations/_resevation_popover.html.twig', [
            'reservation' => $overrides + [
                'booker' => [
                    'salutation' => $payload,
                    'firstname' => $payload,
                    'lastname' => $payload,
                    'customerAddresses' => [[
                        'type' => 'CUSTOMER_ADDRESS_TYPE_BUSINESS',
                        'company' => $payload,
                        'phone' => $payload,
                        'mobilePhone' => $payload,
                        'email' => $payload,
                    ]],
                ],
                'persons' => 2,
                'appartment' => ['bedsMax' => 4],
                'startdate' => new \DateTimeImmutable('2026-07-01'),
                'enddate' => new \DateTimeImmutable('2026-07-02'),
                'reservationStatus' => ['name' => $payload],
                'arrivalTime' => null,
                'departureTime' => null,
                'remark' => $payload,
                'calendarSyncImport' => ['name' => $payload],
            ],
        ]);

        return htmlspecialchars_decode($rendered, \ENT_QUOTES | \ENT_HTML5);
    }
}
