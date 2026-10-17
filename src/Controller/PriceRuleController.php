<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Pricing\PriceRuleData;
use App\Entity\PriceRule;
use App\Form\PriceChangeLimitsType;
use App\Form\PriceRuleType;
use App\Repository\PriceRuleRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\AppSettingsService;
use App\Service\Pricing\PriceRulePreview;
use App\Service\Pricing\PriceRuleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The price rules tab of the price settings. Rules are edited in an offcanvas that loads and
 * submits the form partial over fetch, so validation always happens on the server.
 */
#[Route('/settings/prices/rules')]
#[IsGranted('ROLE_ADMIN')]
final class PriceRuleController extends AbstractController
{
    public function __construct(
        private readonly PriceRuleRepository $repository,
        private readonly PriceRuleService $writer,
    ) {
    }

    #[Route('/', name: 'prices.rules', methods: ['GET'])]
    public function index(AppSettingsService $settings, SubsidiaryRepository $subsidiaries): Response
    {
        return $this->render('PriceRules/index.html.twig', [
            'rules' => $this->repository->findForSettings(),
            'settings' => $settings->getSettings(),
            'multipleSubsidiaries' => count($subsidiaries->findAllOrdered()) > 1,
        ]);
    }

    /** Without a template the offcanvas first offers the templates to start from. */
    #[Route('/new', name: 'prices.rules.new', methods: ['GET', 'POST'])]
    public function new(Request $request, TranslatorInterface $translator, SubsidiaryRepository $subsidiaries): Response
    {
        $template = $request->query->getString('template');
        if (!in_array($template, PriceRuleData::TEMPLATES, true)) {
            return $this->render('PriceRules/_templates.html.twig');
        }

        $data = PriceRuleData::fromTemplate($template, $translator->trans('price_rules.template.'.$template));

        return $this->handleForm($request, $data, null, $this->generateUrl('prices.rules.new', ['template' => $template]), $subsidiaries);
    }

    #[Route('/{id}/edit', name: 'prices.rules.edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id, SubsidiaryRepository $subsidiaries): Response
    {
        $rule = $this->requireRule($id);

        return $this->handleForm($request, PriceRuleData::fromRule($rule), $rule, $this->generateUrl('prices.rules.edit', ['id' => $id]), $subsidiaries);
    }

    /**
     * Renders the preview for the form as it is on screen; nothing is saved. An incomplete
     * form shows a hint instead.
     */
    #[Route('/preview', name: 'prices.rules.preview', methods: ['POST'])]
    public function preview(Request $request, PriceRulePreview $preview): Response
    {
        $original = $request->query->has('id') ? $this->requireRule($request->query->getInt('id')) : null;
        $data = new PriceRuleData();
        $form = $this->createForm(PriceRuleType::class, $data);
        $form->handleRequest($request);

        return $this->render('PriceRules/_preview.html.twig', [
            'preview' => $form->isSubmitted() && $form->isValid() ? $preview->preview($this->writer->apply($data), $original) : null,
        ]);
    }

    #[Route('/{id}/toggle', name: 'prices.rules.toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, int $id): Response
    {
        $rule = $this->requireRule($id);
        if (!$this->isCsrfTokenValid('price-rule-toggle-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->writer->toggle($rule);

        return $request->isXmlHttpRequest()
            ? new Response(status: Response::HTTP_NO_CONTENT)
            : $this->redirectToRoute('prices.rules');
    }

    #[Route('/{id}', name: 'prices.rules.delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(Request $request, int $id): Response
    {
        $rule = $this->requireRule($id);
        if (!$this->isCsrfTokenValid('delete'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->writer->delete($rule);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/limits', name: 'prices.rules.limits', methods: ['GET', 'POST'])]
    public function limits(Request $request, AppSettingsService $settings): Response
    {
        $current = $settings->getSettings();
        $form = $this->createForm(PriceChangeLimitsType::class, [
            'maxDecrease' => -$current->getPriceChangeMinPercent(),
            'maxIncrease' => $current->getPriceChangeMaxPercent(),
            'rounding' => $current->getPriceChangeRounding(),
        ], ['action' => $this->generateUrl('prices.rules.limits')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{maxDecrease: int, maxIncrease: int, rounding: \App\Entity\Enum\PriceRounding} $values */
            $values = $form->getData();
            $this->writer->saveLimits($values['maxDecrease'], $values['maxIncrease'], $values['rounding']);
            $this->addFlash('success', 'price_rules.limits.saved');

            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $this->render('PriceRules/_limits_form.html.twig', [
            'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    private function handleForm(Request $request, PriceRuleData $data, ?PriceRule $rule, string $action, SubsidiaryRepository $subsidiaries): Response
    {
        $form = $this->createForm(PriceRuleType::class, $data, ['action' => $action]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->writer->save($data, $rule);
            $this->addFlash('success', 'price_rules.saved');

            // The offcanvas submits by fetch and reloads on success; the id lets the page scroll
            // back to the rule that was just saved.
            return new Response(status: Response::HTTP_NO_CONTENT, headers: ['X-Price-Rule-Id' => (string) $saved->getId()]);
        }

        return $this->render('PriceRules/_rule_form.html.twig', [
            'form' => $form->createView(),
            'rule' => $rule,
            'multipleSubsidiaries' => count($subsidiaries->findAllOrdered()) > 1,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    /** @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException for an unknown rule */
    private function requireRule(int $id): PriceRule
    {
        return $this->repository->find($id) ?? throw $this->createNotFoundException();
    }
}
