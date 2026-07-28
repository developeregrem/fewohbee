<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class ReservationPopoverEscapingTest extends TestCase
{
    public function testUserValuesRemainEscapedWhenBootstrapReadsPopoverHtml(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 3).'/templates'),
            ['autoescape' => 'name'],
        );
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));

        $rendered = $twig->render('Reservations/_resevation_popover.html.twig', [
            'reservation' => [
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
                'invoices' => [['number' => $payload]],
                'reservationStatus' => ['name' => $payload],
                'arrivalTime' => null,
                'departureTime' => null,
                'remark' => $payload,
                'calendarSyncImport' => ['name' => $payload],
            ],
        ]);

        // Reading data-bs-content decodes the attribute exactly once. The
        // trusted formatting is then HTML, while every payload is still an
        // entity and becomes text when Bootstrap assigns it via innerHTML.
        $popoverHtml = htmlspecialchars_decode($rendered, \ENT_QUOTES | \ENT_HTML5);

        self::assertSame(11, substr_count($popoverHtml, '&lt;img src=x onerror=alert(1)&gt;'));
        self::assertStringContainsString('<i>', $popoverHtml);
        self::assertStringNotContainsString($payload, $popoverHtml);
    }
}
