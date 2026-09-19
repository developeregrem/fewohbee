<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\BookingRestriction\RuleData;
use App\Entity\BookingRestrictionRule;
use App\Form\BookingRestrictionRuleType;
use App\Repository\BookingRestrictionRuleRepository;
use App\Repository\RoomCategoryRepository;
use App\Service\OnlineBooking\BookingRestrictionCalendar;
use App\Service\OnlineBooking\BookingRestrictionPresentation;
use App\Service\OnlineBooking\BookingRestrictionRuleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Booking rule maintenance for the online booking settings page. The editor lives in an
 * offcanvas that loads and submits this controller's form partial, so validation always
 * happens on the server.
 */
#[Route('/settings/online-booking')]
#[IsGranted('ROLE_ADMIN')]
final class BookingRestrictionController extends AbstractController
{
    public function __construct(
        private readonly BookingRestrictionRuleRepository $repository,
        private readonly BookingRestrictionRuleService $writer,
        private readonly BookingRestrictionPresentation $presentation,
    ) {
    }

    /**
     * Creates or edits a rule. Whether it is a special period comes from the route, not
     * from the submitted data — the two lists on the settings page open different routes.
     */
    #[Route('/rules/new', name: 'settings.online_booking.rule_new', defaults: ['isPeriod' => false], methods: ['GET', 'POST'])]
    #[Route('/periods/new', name: 'settings.online_booking.period_new', defaults: ['isPeriod' => true], methods: ['GET', 'POST'])]
    #[Route('/rules/{id}/edit', name: 'settings.online_booking.rule_edit', requirements: ['id' => '\d+'], defaults: ['isPeriod' => null], methods: ['GET', 'POST'])]
    public function edit(Request $request, ?int $id = null, ?bool $isPeriod = false): Response
    {
        $rule = null === $id ? null : $this->requireRule($id);
        $data = null === $rule ? new RuleData() : RuleData::fromRule($rule);
        $data->isPeriod = $isPeriod ?? $rule?->isPeriod() ?? false;

        $form = $this->createForm(BookingRestrictionRuleType::class, $data, [
            'is_period' => $data->isPeriod,
            'action' => $this->ruleFormAction($id, $data->isPeriod),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->writer->save($data, $rule);
            $this->addFlash('success', 'booking_rules.saved');

            // The offcanvas submits by fetch and reloads on success; the id lets the page scroll
            // back to the rule that was just saved instead of landing at the top.
            if ($request->isXmlHttpRequest()) {
                return new Response(status: Response::HTTP_NO_CONTENT, headers: ['X-Booking-Rule-Id' => (string) $saved->getId()]);
            }

            return $this->redirectToRoute('settings.online_booking.index', [
                'tab' => BookingRestrictionCalendar::SETTINGS_TAB,
                '_fragment' => 'booking-rule-'.$saved->getId(),
            ]);
        }

        if ($form->isSubmitted() && !$request->isXmlHttpRequest()) {
            $this->addFlash('danger', 'booking_rules.invalid');

            return $this->redirectToRoute('settings.online_booking.index', ['tab' => BookingRestrictionCalendar::SETTINGS_TAB]);
        }

        return $this->renderRuleForm($form, $rule, $data, $form->isSubmitted() ? 422 : 200);
    }

    #[Route('/rules/{id}/toggle', name: 'settings.online_booking.rule_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, int $id): Response
    {
        $rule = $this->requireRule($id);
        if (!$this->isCsrfTokenValid('booking-rule-toggle-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->writer->toggle($rule);

        // The list updates the row in place, so the page keeps its scroll position.
        return $request->isXmlHttpRequest()
            ? new Response(status: Response::HTTP_NO_CONTENT)
            : $this->redirectToRoute('settings.online_booking.index', ['tab' => BookingRestrictionCalendar::SETTINGS_TAB]);
    }

    #[Route('/rules/{id}', name: 'settings.online_booking.rule_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(Request $request, int $id): Response
    {
        $rule = $this->requireRule($id);
        if (!$this->isCsrfTokenValid('delete'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->writer->delete($rule);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Renders the booking-rule calendar for one room category and week; the settings page
     * reloads it whenever one of them changes.
     */
    #[Route('/rules/calendar', name: 'settings.online_booking.rule_calendar', methods: ['GET'])]
    public function calendar(Request $request, RoomCategoryRepository $categories, BookingRestrictionCalendar $calendar): Response
    {
        $category = $categories->find($request->query->getInt('category')) ?? throw $this->createNotFoundException();

        $context = $calendar->build($category, $request->query->getString('week') ?: null);

        return $this->render('Settings/OnlineBooking/_rule_calendar.html.twig', $context + ['categories' => $categories->findAll()]);
    }

    private function renderRuleForm(FormInterface $form, ?BookingRestrictionRule $rule, RuleData $data, int $status): Response
    {
        // Overlaps are computed from the submitted draft, so the hint reflects what is on screen.
        $draft = $form->isSubmitted() && $form->isValid() ? $this->writer->apply($data) : $rule;
        $overlaps = null === $draft ? [] : $this->presentation->overlappingRules($draft, $rule?->getId());

        return $this->render('Settings/OnlineBooking/_rule_form.html.twig', [
            'form' => $form->createView(),
            'rule' => $rule,
            'isPeriod' => $data->isPeriod,
            'overlaps' => $overlaps,
            'descriptions' => $this->describeAll($overlaps),
        ], new Response(status: $status));
    }

    private function ruleFormAction(?int $id, bool $isPeriod): string
    {
        if (null !== $id) {
            return $this->generateUrl('settings.online_booking.rule_edit', ['id' => $id]);
        }

        return $this->generateUrl($isPeriod ? 'settings.online_booking.period_new' : 'settings.online_booking.rule_new');
    }

    /** @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException for an unknown rule */
    private function requireRule(int $id): BookingRestrictionRule
    {
        return $this->repository->find($id) ?? throw $this->createNotFoundException();
    }

    /**
     * @param list<BookingRestrictionRule> $rules
     *
     * @return array<int, string>
     */
    private function describeAll(array $rules): array
    {
        $descriptions = [];
        foreach ($rules as $rule) {
            $descriptions[(int) $rule->getId()] = $this->presentation->describe($rule);
        }

        return $descriptions;
    }
}
