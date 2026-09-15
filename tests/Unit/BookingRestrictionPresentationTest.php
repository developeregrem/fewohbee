<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType as Type;
use App\Repository\BookingRestrictionRuleRepository;
use App\Service\OnlineBooking\BookingRestrictionPresentation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The rule sentence is the main explanation in the settings, so it is checked against the
 * real translation files rather than a stubbed translator.
 */
final class BookingRestrictionPresentationTest extends TestCase
{
    public function testNightRuleSentenceSaysTheWholeBookingHasToReachTheMinimum(): void
    {
        $rule = $this->rule(Type::MIN_STAY_THROUGH, 4, [1, 2]);

        $sentence = $this->presentation()->describe($rule);

        // Naming the nights as pairs is what distinguishes them from arrival days.
        self::assertStringContainsString('Mo → Di, Di → Mi', $sentence);
        self::assertStringContainsString('insgesamt mindestens 4 Nächte', $sentence);
    }

    public function testArrivalRuleNamesPlainDays(): void
    {
        $sentence = $this->presentation()->describe($this->rule(Type::MIN_STAY_ARRIVAL, 2, [5, 6]));

        self::assertStringContainsString('Bei Anreise am Fr, Sa', $sentence);
        self::assertStringNotContainsString('→', $sentence);
    }

    /**
     * One night is a real setting — it explicitly releases the restriction — so the
     * sentence has to read "eine Nacht" rather than "1 Nächte".
     */
    public function testSingleNightUsesTheSingular(): void
    {
        $presentation = $this->presentation();

        self::assertStringContainsString('mindestens eine Nacht', $presentation->describe($this->rule(Type::MIN_STAY_ARRIVAL, 1)));
        self::assertStringNotContainsString('1 Nächte', $presentation->describe($this->rule(Type::MIN_STAY_ARRIVAL, 1)));
        self::assertSame('eine Nacht', $presentation->describeNights(1));
        self::assertSame('2 Nächte', $presentation->describeNights(2));
    }

    public function testClosureSentenceCarriesNoNightCountAndAPeriodIsAppended(): void
    {
        $rule = $this->rule(Type::CLOSED_TO_ARRIVAL, null, [7]);
        $rule->setPeriod(new \DateTimeImmutable('2026-12-24'), new \DateTimeImmutable('2026-12-27'));

        $sentence = $this->presentation()->describe($rule);

        self::assertStringContainsString('Am So ist keine Anreise möglich.', $sentence);
        // Storage is half-open, the operator entered the 26th as the last covered day.
        self::assertStringContainsString('vom 24.12.2026 bis 26.12.2026', $sentence);
        self::assertStringNotContainsString('Nächte', $sentence);
    }

    private function presentation(): BookingRestrictionPresentation
    {
        $translator = new Translator('de');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 2).'/translations/BookingRules/messages.de.yaml', 'de');

        return new BookingRestrictionPresentation($translator, $this->createStub(BookingRestrictionRuleRepository::class));
    }

    /** @param list<int> $weekdays */
    private function rule(Type $type, ?int $nights, array $weekdays = [1, 2, 3, 4, 5, 6, 7]): BookingRestrictionRule
    {
        $rule = new BookingRestrictionRule();
        $rule->setType($type);
        $rule->setMinNights($nights);
        $rule->setWeekdays($weekdays);

        return $rule;
    }
}
