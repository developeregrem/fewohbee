<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\CalendarEntryTimeRules;
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
