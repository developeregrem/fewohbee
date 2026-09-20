<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\BookingRestriction\RuleData;
use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType as Type;
use App\Entity\RoomCategory;
use App\Repository\BookingRestrictionRuleRepository;
use App\Service\OnlineBooking\BookingRestrictionRuleService;
use App\Service\OnlineBooking\BookingRestrictionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/** Protects drafts from persistence side effects and validates the editor's conditional fields. */
final class BookingRestrictionRuleServiceTest extends TestCase
{
    public function testDraftReplacesOriginalWithoutMutatingManagedEntity(): void
    {
        $original = new BookingRestrictionRule();
        (new \ReflectionProperty(BookingRestrictionRule::class, 'id'))->setValue($original, 1);
        $original->setMinNights(4);
        $unrelated = new BookingRestrictionRule();
        (new \ReflectionProperty(BookingRestrictionRule::class, 'id'))->setValue($unrelated, 2);

        $repo = $this->createStub(BookingRestrictionRuleRepository::class);
        $repo->method('findForSettings')->willReturn([$original, $unrelated]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('persist');

        $service = new BookingRestrictionRuleService($em, $repo, $this->createStub(BookingRestrictionService::class));
        $data = RuleData::fromRule($original);
        $data->minNights = 2;
        $data->type = Type::MIN_STAY_THROUGH;
        $data->weekdays = [1, 2, 3, 4];

        $drafts = $service->previewRules($data, $original);

        self::assertCount(2, $drafts);
        self::assertSame($unrelated, $drafts[0]);
        self::assertNotSame($original, $drafts[1]);
        self::assertSame(2, $drafts[1]->getMinNights());
        self::assertSame(Type::MIN_STAY_THROUGH, $drafts[1]->getType());
        self::assertSame(4, $original->getMinNights());
        self::assertSame(Type::MIN_STAY_ARRIVAL, $original->getType());
    }

    public function testInclusiveSingleDayPeriodBecomesOneNightInterval(): void
    {
        $data = new RuleData();
        $data->type = Type::MIN_STAY_THROUGH;
        $data->isPeriod = true;
        $data->startDate = new \DateTimeImmutable('2026-09-13');
        $data->lastDate = new \DateTimeImmutable('2026-09-13');

        $rule = $this->service()->apply($data);

        self::assertSame('2026-09-14', $rule->getEndDate()?->format('Y-m-d'));
        self::assertTrue($rule->isPeriod());
        // Round trip: what the operator entered is what the editor shows again.
        self::assertSame('2026-09-13', RuleData::fromRule($rule)->lastDate?->format('Y-m-d'));
    }

    public function testUnlimitedRuleDropsDatesLeftOverFromAPeriodEdit(): void
    {
        $data = new RuleData();
        $data->isPeriod = false;
        $data->startDate = new \DateTimeImmutable('2026-09-13');
        $data->lastDate = new \DateTimeImmutable('2026-09-15');

        $rule = $this->service()->apply($data);

        self::assertNull($rule->getStartDate());
        self::assertNull($rule->getEndDate());
        self::assertFalse($rule->isPeriod());
    }

    public function testClosureTypeDropsTheNightCountAndAllCategoriesClearsTheSelection(): void
    {
        $data = new RuleData();
        $data->type = Type::CLOSED_TO_ARRIVAL;
        $data->minNights = 5;
        $data->allCategories = true;
        $data->categories = [new RoomCategory()];

        $rule = $this->service()->apply($data);

        self::assertNull($rule->getMinNights());
        self::assertTrue($rule->getCategories()->isEmpty());
    }

    public function testConditionalValidationRequiresDaysCategoriesNightsAndValidDates(): void
    {
        $data = new RuleData();
        $data->isPeriod = true;
        $data->weekdays = [];
        $data->allCategories = false;
        $data->minNights = 0;

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $paths = [];
        foreach ($validator->validate($data) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        sort($paths);

        self::assertSame(['categories', 'lastDate', 'minNights', 'weekdays'], $paths);
    }

    public function testClosureNeedsNoNightCountToValidate(): void
    {
        $data = new RuleData();
        $data->type = Type::CLOSED_TO_DEPARTURE;
        $data->minNights = null;

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validator->validate($data));
    }

    public function testSaveInvalidatesResolvedRuleWindows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');
        $restrictions = $this->createMock(BookingRestrictionService::class);
        $restrictions->expects(self::once())->method('reset');

        $writer = new BookingRestrictionRuleService($em, $this->createStub(BookingRestrictionRuleRepository::class), $restrictions);
        $writer->save(new RuleData());
    }

    private function service(): BookingRestrictionRuleService
    {
        return new BookingRestrictionRuleService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(BookingRestrictionRuleRepository::class),
            $this->createStub(BookingRestrictionService::class),
        );
    }
}
