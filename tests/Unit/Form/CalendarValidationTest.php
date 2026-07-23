<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The maxlength attributes on CalendarType/CalendarEntryType are markup only
 * - anything posting past the browser (or the ICS import) needs the
 * constraint to exist server-side, or the value travels straight into a
 * column too narrow for it and fails at flush time.
 */
final class CalendarValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    public function testCalendarNameOverHundredCharactersIsRejected(): void
    {
        $calendar = new Calendar();
        $calendar->setName(str_repeat('a', 101));

        self::assertGreaterThan(0, $this->validator->validate($calendar)->count());
    }

    public function testCalendarNameAtTheLimitIsAccepted(): void
    {
        $calendar = new Calendar();
        $calendar->setName(str_repeat('a', 100));

        self::assertCount(0, $this->validator->validate($calendar));
    }

    public function testBlankCalendarNameIsRejected(): void
    {
        $calendar = new Calendar();
        $calendar->setName('');

        self::assertGreaterThan(0, $this->validator->validate($calendar)->count());
    }

    public function testEntryTitleOverHundredCharactersIsRejected(): void
    {
        $entry = new CalendarEntry();
        $entry->setTitle(str_repeat('a', 101));
        $entry->setDate(new \DateTimeImmutable('2026-01-01'));

        $violations = $this->validator->validate($entry, null, ['Default']);
        self::assertGreaterThan(0, $violations->count());
    }

    public function testEntryTitleAtTheLimitIsAccepted(): void
    {
        $entry = new CalendarEntry();
        $entry->setTitle(str_repeat('a', 100));
        $entry->setDate(new \DateTimeImmutable('2026-01-01'));

        $titleViolations = $this->validator->validateProperty($entry, 'title');
        self::assertCount(0, $titleViolations);
    }

    public function testIcsUrlOverTwoHundredFiftyFiveCharactersIsRejected(): void
    {
        $calendar = new Calendar();
        $calendar->setName('Gültig');
        $calendar->setIcsUrl('https://example.com/'.str_repeat('a', 250).'.ics');

        self::assertGreaterThan(0, $this->validator->validate($calendar)->count());
    }
}
