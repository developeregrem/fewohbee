<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Calendar\Sync\CalendarImportPreviewService;
use App\Service\Calendar\Sync\CalendarImportSummaryMatcher;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Verify that portal feeds are presented as grouped, neutral choices before import. */
final class CalendarImportPreviewServiceTest extends TestCase
{
    public function testBookingDotComStyleEntriesRemainOneNeutralChoice(): void
    {
        $service = $this->createService(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Booking.com//Calendar//EN
BEGIN:VEVENT
UID:booking-1
DTSTAMP:20990101T000000Z
DTSTART;VALUE=DATE:20990110
DTEND;VALUE=DATE:20990112
SUMMARY:CLOSED - Not available
END:VEVENT
BEGIN:VEVENT
UID:booking-2
DTSTAMP:20990101T000000Z
DTSTART;VALUE=DATE:20990210
DTEND;VALUE=DATE:20990212
SUMMARY:CLOSED - Not available
END:VEVENT
BEGIN:VEVENT
UID:booking-3
DTSTAMP:20990101T000000Z
DTSTART;VALUE=DATE:20990310
DTEND;VALUE=DATE:20990312
SUMMARY:CLOSED - Not available
END:VEVENT
BEGIN:VEVENT
UID:past-entry
DTSTAMP:20200101T000000Z
DTSTART;VALUE=DATE:20200110
DTEND;VALUE=DATE:20200112
SUMMARY:CLOSED - Not available
END:VEVENT
END:VCALENDAR
ICS);

        $preview = $service->preview('https://example.test/booking.ics', new \DateTimeZone('UTC'));

        self::assertSame(3, $preview->eventCount);
        self::assertSame(0, $preview->skippedCount);
        self::assertCount(1, $preview->groups);
        self::assertSame('CLOSED - Not available', $preview->groups[0]->summary);
        self::assertSame('closed - not available', $preview->groups[0]->filterValue);
        self::assertSame(3, $preview->groups[0]->count);
        self::assertSame('2099-01-10', $preview->groups[0]->exampleStart->format('Y-m-d'));
    }

    public function testPreviewGroupsAirbnbLabelsSeparatelyInsteadOfGuessingTheirMeaning(): void
    {
        $service = $this->createService(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:reserved
DTSTART;VALUE=DATE:20990110
DTEND;VALUE=DATE:20990112
SUMMARY:Reserved
END:VEVENT
BEGIN:VEVENT
UID:not-available
DTSTART;VALUE=DATE:20990210
DTEND;VALUE=DATE:20990212
SUMMARY:Airbnb (Not available)
END:VEVENT
END:VCALENDAR
ICS);

        $preview = $service->preview('https://example.test/airbnb.ics', new \DateTimeZone('UTC'));

        self::assertSame(2, $preview->eventCount);
        self::assertCount(2, $preview->groups);
        self::assertSame(
            ['Airbnb (Not available)', 'Reserved'],
            array_map(static fn ($group): string => $group->summary, $preview->groups),
        );
    }

    public function testValidCalendarWithoutEntriesReturnsEmptyPreview(): void
    {
        $service = $this->createService(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//New property//Calendar//EN
END:VCALENDAR
ICS);

        $preview = $service->preview('https://example.test/empty.ics', new \DateTimeZone('UTC'));

        self::assertSame(0, $preview->eventCount);
        self::assertSame(0, $preview->skippedCount);
        self::assertSame([], $preview->groups);
    }

    /** Build the preview service with a single in-memory HTTP response. */
    private function createService(string $content): CalendarImportPreviewService
    {
        return new CalendarImportPreviewService(
            new IcsFeedClient(new MockHttpClient(new MockResponse($content))),
            new IcsOccurrenceReader(),
            new CalendarImportSummaryMatcher(),
        );
    }
}
