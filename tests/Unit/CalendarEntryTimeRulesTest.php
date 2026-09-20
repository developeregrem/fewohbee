<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Calendar\Entry\CalendarEntryTimeRules;
use PHPUnit\Framework\TestCase;

final class CalendarEntryTimeRulesTest extends TestCase
{
    private CalendarEntryTimeRules $rules;

    protected function setUp(): void
    {
        $this->rules = new CalendarEntryTimeRules();
    }

    public function testAllDayEntryIsValid(): void
    {
        self::assertTrue($this->rules->isValidRange(null, null));
    }

    public function testStartWithoutEndIsValid(): void
    {
        self::assertTrue($this->rules->isValidRange($this->time('13:00'), null));
    }

    public function testEndWithoutStartIsValidForTheClosingDayOfAMultiDayEntry(): void
    {
        self::assertTrue($this->rules->isValidRange(null, $this->time('14:00')));
    }

    public function testEndAfterStartIsValid(): void
    {
        self::assertTrue($this->rules->isValidRange($this->time('13:00'), $this->time('14:00')));
    }

    public function testEndBeforeStartIsRejected(): void
    {
        self::assertFalse($this->rules->isValidRange($this->time('13:00'), $this->time('12:00')));
    }

    public function testEndEqualToStartIsRejected(): void
    {
        self::assertFalse($this->rules->isValidRange($this->time('13:00'), $this->time('13:00')));
    }

    /**
     * The case the ICS import produces for an event running until midnight.
     * It must validate, or the import would write entries the edit form then
     * refuses to save.
     */
    public function testEndAtMidnightIsValidAlongsideAStartTime(): void
    {
        self::assertTrue($this->rules->isValidRange($this->time('18:00'), $this->time('00:00')));
    }

    public function testEndAtMidnightAloneIsRejected(): void
    {
        self::assertFalse($this->rules->isValidRange(null, $this->time('00:00')));
    }

    public function testTheStartDayKeepsAMidnightEndTime(): void
    {
        // A single-day entry running 18:00 - 00:00: the end time is the only
        // thing saying when it stops.
        self::assertSame(
            '00:00',
            $this->rules->endTimeForClosingDay($this->time('00:00'), true)?->format('H:i'),
        );
    }

    /**
     * The bug Alex found: a period ending at 00:00 put a lone "- 00:00" on
     * the closing day, which isValidRange() then refused - the entry could be
     * created but not edited.
     */
    public function testALaterClosingDayDropsAMidnightEndTime(): void
    {
        self::assertNull($this->rules->endTimeForClosingDay($this->time('00:00'), false));
    }

    public function testALaterClosingDayKeepsAnOrdinaryEndTime(): void
    {
        self::assertSame(
            '14:00',
            $this->rules->endTimeForClosingDay($this->time('14:00'), false)?->format('H:i'),
        );
    }

    public function testWhateverEndTimeSurvivesStaysValidOnItsOwn(): void
    {
        // The two rules have to agree: anything endTimeForClosingDay() leaves
        // on a day without a start time must pass isValidRange(null, ...).
        foreach (['00:00', '09:00', '14:00', '23:59'] as $candidate) {
            $kept = $this->rules->endTimeForClosingDay($this->time($candidate), false);
            if (null !== $kept) {
                self::assertTrue(
                    $this->rules->isValidRange(null, $kept),
                    sprintf('closing day kept %s, but validation rejects it', $candidate),
                );
            }
        }
    }

    public function testMidnightIsRecognisedRegardlessOfSeconds(): void
    {
        self::assertTrue($this->rules->endsAtMidnight($this->time('00:00')));
        self::assertTrue($this->rules->endsAtMidnight(new \DateTimeImmutable('1970-01-01 00:00:30')));
        self::assertFalse($this->rules->endsAtMidnight($this->time('00:01')));
        self::assertFalse($this->rules->endsAtMidnight(null));
    }

    private function time(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('1970-01-01 '.$time.':00');
    }
}
