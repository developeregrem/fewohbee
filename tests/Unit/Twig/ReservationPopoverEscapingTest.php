<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

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

    public function testInvoiceNumberLinksToTheInvoiceWithInvoiceRights(): void
    {
        $popoverHtml = $this->renderPopover(['invoices' => [['id' => 7, 'number' => 'R-1001', 'status' => 2]]], true);

        self::assertStringContainsString(
            "<a href='/invoices/get/7/' class='js-invoice-link' title='reservation.invoice R-1001'>R-1001</a> (invoice.status.paid)",
            $popoverHtml,
        );
    }

    public function testInvoiceNumberStaysPlainTextWithoutInvoiceRights(): void
    {
        $popoverHtml = $this->renderPopover(['invoices' => [['id' => 7, 'number' => 'R-1001', 'status' => 2]]]);

        self::assertStringContainsString('R-1001 (invoice.status.paid)', $popoverHtml);
        self::assertStringNotContainsString('js-invoice-link', $popoverHtml);
    }

    public function testInvoiceLinkKeepsTheInvoiceNumberEscaped(): void
    {
        $popoverHtml = $this->renderPopover(['invoices' => [['id' => 7, 'number' => self::PAYLOAD, 'status' => 1]]], true);

        self::assertStringContainsString(
            "title='reservation.invoice &lt;img src=x onerror=alert(1)&gt;'>&lt;img src=x onerror=alert(1)&gt;</a>",
            $popoverHtml,
        );
        self::assertStringNotContainsString(self::PAYLOAD, $popoverHtml);
    }

    public function testContactValuesAreSeparatedOnlyBetweenValues(): void
    {
        $popoverHtml = $this->renderPopover(['booker' => $this->booker([
            ['phone' => '0351 1', 'mobilePhone' => '', 'email' => 'max@example.org'],
            ['phone' => '', 'mobilePhone' => '', 'email' => ''],
        ])]);

        self::assertStringContainsString("customer.phone'></i><a href='tel:03511'>0351 1</a></div>", $popoverHtml);
        self::assertStringContainsString("customer.email'></i><a href='mailto:max@example.org'>max@example.org</a></div>", $popoverHtml);
    }

    public function testContactValuesSharedByTwoAddressesAppearOnce(): void
    {
        $popoverHtml = $this->renderPopover(['booker' => $this->booker([
            ['phone' => '0351 1', 'mobilePhone' => '0170 2', 'email' => 'max@example.org'],
            ['phone' => '0351 1 ', 'mobilePhone' => '0170 3', 'email' => 'Max@Example.org'],
        ])]);

        self::assertSame(1, substr_count($popoverHtml, "href='tel:03511'"));
        self::assertStringContainsString("customer.mobilephone'></i><a href='tel:01702'>0170 2</a>, <a href='tel:01703'>0170 3</a></div>", $popoverHtml);
        self::assertSame(1, substr_count($popoverHtml, 'mailto:'));
    }

    public function testDepartureTimeWithoutArrivalTimeGetsItsOwnRow(): void
    {
        $popoverHtml = $this->renderPopover([
            'booker' => $this->booker([['phone' => '', 'mobilePhone' => '', 'email' => 'max@example.org']]),
            'departureTime' => new \DateTimeImmutable('10:00'),
        ]);

        self::assertStringContainsString("max@example.org</a></div><div><i class='fas fa-fw fa-right-from-bracket text-body-secondary me-2' role='img' aria-label='reservation.departureTime'></i>10:00</div>", $popoverHtml);
        self::assertStringNotContainsString('js-reservation-meta-link', $popoverHtml);
    }

    public function testArrivalAndDepartureTimeShareOneRow(): void
    {
        $popoverHtml = $this->renderPopover([
            'arrivalTime' => new \DateTimeImmutable('17:00'),
            'departureTime' => new \DateTimeImmutable('10:00'),
        ]);

        self::assertStringContainsString(
            "aria-label='reservation.arrivalTime'></i>17:00 <span class='ms-2'><i class='fas fa-fw fa-right-from-bracket text-body-secondary' role='img' aria-label='reservation.departureTime'></i></span> 10:00</div>",
            $popoverHtml,
        );
        self::assertStringNotContainsString('js-reservation-meta-link', $popoverHtml);
    }

    public function testArrivalAndDepartureTimesLinkToTheStayDetailsWithReservationEditRights(): void
    {
        $popoverHtml = $this->renderPopover([
            'arrivalTime' => new \DateTimeImmutable('17:00'),
            'departureTime' => new \DateTimeImmutable('10:00'),
            'remark' => '',
        ], reservationRights: true);

        foreach (['17:00', '10:00'] as $time) {
            self::assertStringContainsString(
                "<a href='/reservation/42/edit/remark' class='js-reservation-meta-link' title='nav.reservation.edit'>".$time.'</a>',
                $popoverHtml,
            );
        }
        self::assertSame(2, substr_count($popoverHtml, 'js-reservation-meta-link'));
    }

    public function testArrivalTimeAtMidnightLinksWithoutDepartureTime(): void
    {
        $popoverHtml = $this->renderPopover([
            'arrivalTime' => new \DateTimeImmutable('00:00'),
            'remark' => '',
        ], reservationRights: true);

        self::assertStringContainsString(
            "<a href='/reservation/42/edit/remark' class='js-reservation-meta-link' title='nav.reservation.edit'>00:00</a>",
            $popoverHtml,
        );
        self::assertSame(1, substr_count($popoverHtml, 'js-reservation-meta-link'));
    }

    public function testDepartureTimeLinksWithoutArrivalTime(): void
    {
        $popoverHtml = $this->renderPopover([
            'departureTime' => new \DateTimeImmutable('10:00'),
            'remark' => '',
        ], reservationRights: true);

        self::assertStringContainsString(
            "<a href='/reservation/42/edit/remark' class='js-reservation-meta-link' title='nav.reservation.edit'>10:00</a>",
            $popoverHtml,
        );
        self::assertSame(1, substr_count($popoverHtml, 'js-reservation-meta-link'));
    }

    public function testRemarkOpensTheStayDetailsWithReservationEditRights(): void
    {
        $popoverHtml = $this->renderPopover(['remark' => "Late arrival\nCall first"], reservationRights: true);

        self::assertStringContainsString(
            "<a href='/reservation/42/edit/remark' class='js-reservation-meta-link' title='nav.reservation.edit'>Late arrival<br />\nCall first</a>",
            $popoverHtml,
        );
    }

    public function testRemarkStaysPlainTextWithoutReservationEditRights(): void
    {
        $popoverHtml = $this->renderPopover(['remark' => "Late arrival\nCall first"]);

        self::assertStringContainsString("Late arrival<br />\nCall first", $popoverHtml);
        self::assertStringNotContainsString('js-reservation-meta-link', $popoverHtml);
    }

    public function testRemarkLinkKeepsUserContentEscaped(): void
    {
        $popoverHtml = $this->renderPopover([], reservationRights: true);

        self::assertStringContainsString(
            "title='nav.reservation.edit'>&lt;img src=x onerror=alert(1)&gt;</a>",
            $popoverHtml,
        );
        self::assertStringNotContainsString(self::PAYLOAD, $popoverHtml);
    }

    /**
     * A private booker with the given phone numbers and e-mail addresses.
     *
     * @param list<array{phone: string, mobilePhone: string, email: string}> $contacts
     *
     * @return array<string, mixed>
     */
    private function booker(array $contacts): array
    {
        return [
            'salutation' => 'Herr',
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'customerAddresses' => array_map(
                static fn (array $contact): array => $contact + ['type' => 'CUSTOMER_ADDRESS_TYPE_PRIVATE', 'company' => ''],
                $contacts,
            ),
        ];
    }

    /**
     * Renders the popover body the way Bootstrap reads it from data-bs-content: the
     * attribute is decoded exactly once, so the trusted formatting is HTML while every
     * user value must still be an entity that becomes text via innerHTML.
     *
     * @param array<string, mixed> $overrides
     */
    private function renderPopover(array $overrides, bool $invoiceRights = false, bool $reservationRights = false): string
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
        $twig->addFunction(new TwigFunction('is_granted', static fn (string $role): bool => match ($role) {
            'ROLE_INVOICES' => $invoiceRights,
            'ROLE_RESERVATIONS' => $reservationRights,
            default => false,
        }));
        $twig->addFunction(new TwigFunction(
            'path',
            static fn (string $route, array $parameters): string => match ($route) {
                'invoices.get.invoice' => '/invoices/get/'.$parameters['id'].'/',
                'reservations.edit.remark' => '/reservation/'.$parameters['id'].'/edit/remark',
                default => '/'.$route,
            },
        ));

        $rendered = $twig->render('Reservations/_resevation_popover.html.twig', [
            'reservation' => $overrides + [
                'id' => 42,
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
