<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Entity\Reservation;
use App\Service\GuestCheckIn\GuestCheckInLinkService;
use App\Twig\GuestCheckInExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class GuestCheckInExtensionTest extends TestCase
{
    public function testEmptyStringWhenThereIsNoLink(): void
    {
        $links = $this->createStub(GuestCheckInLinkService::class);
        $links->method('url')->willReturn(null);
        $extension = new GuestCheckInExtension($links, new RequestStack());

        self::assertSame('', $extension->url(new Reservation()));
        self::assertSame('', $extension->url(null));
        self::assertSame('', $extension->qr(new Reservation()));
    }

    public function testEditorPreviewShowsTheStandInInsteadOfCreatingALink(): void
    {
        $links = $this->createMock(GuestCheckInLinkService::class);
        $links->expects($this->never())->method('url');
        $links->method('previewUrl')->willReturn('https://example.com/checkin/xxx');

        $request = new Request(attributes: ['_route' => 'settings.templates.preview.render']);
        $stack = new RequestStack();
        $stack->push($request);

        self::assertSame('https://example.com/checkin/xxx', (new GuestCheckInExtension($links, $stack))->url(new Reservation()));
    }

    public function testQrCodeIsBuiltFromTheLink(): void
    {
        $links = $this->createMock(GuestCheckInLinkService::class);
        $links->method('url')->willReturn('https://example.com/checkin/abc');
        $links->expects($this->once())->method('qrDataUri')->with('https://example.com/checkin/abc', 200)->willReturn('data:image/png;base64,AAA');

        self::assertSame('data:image/png;base64,AAA', (new GuestCheckInExtension($links, new RequestStack()))->qr(new Reservation(), 200));
    }
}
