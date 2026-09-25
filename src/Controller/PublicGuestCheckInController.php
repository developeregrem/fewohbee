<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GuestCheckIn\GuestCheckInSubmission;
use App\Dto\GuestCheckIn\GuestCheckInVerification;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Form\GuestCheckInType;
use App\Form\GuestCheckInVerificationType;
use App\Repository\OnlineBookingConfigRepository;
use App\Service\AppSettingsService;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use App\Service\GuestCheckIn\GuestCheckInFormDataFactory;
use App\Service\GuestCheckIn\GuestCheckInLinkService;
use App\Service\GuestCheckIn\GuestCheckInLinkState;
use App\Service\GuestCheckIn\GuestCheckInPolicy;
use App\Service\GuestCheckIn\GuestCheckInRateLimiter;
use App\Service\GuestCheckIn\GuestCheckInSubmissionService;
use App\Service\GuestCheckIn\GuestCheckInTokenSigner;
use App\Service\GuestCheckIn\GuestCheckInVerifier;
use App\Service\GuestCheckIn\Section\GuestCheckInSections;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Public online check-in page, reached through the link a guest received.
 *
 * Every failure — malformed, forged, revoked or expired link, feature switched off — answers with
 * the same neutral page, so the response reveals nothing about which links exist. Before any
 * booking or guest data is shown the visitor confirms the booking details (GuestCheckInVerifier);
 * after that the form comes prefilled from the guest records (GuestCheckInFormDataFactory). No
 * session is started: the only cookie is the signed proof of that check.
 */
final class PublicGuestCheckInController extends AbstractController
{
    private const SUPPORTED_LOCALES = ['de', 'en'];

    /** The token travels in the URL: nothing may leak it, cache it or index it. */
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

    public function __construct(
        private readonly GuestCheckInLinkService $linkService,
        private readonly GuestCheckInConfigService $configService,
        private readonly GuestCheckInPolicy $policy,
        private readonly GuestCheckInVerifier $verifier,
        private readonly GuestCheckInRateLimiter $rateLimiter,
        private readonly GuestCheckInSections $sections,
        private readonly GuestCheckInSubmissionService $submissionService,
        private readonly GuestCheckInFormDataFactory $formDataFactory,
        private readonly AppSettingsService $appSettingsService,
        private readonly OnlineBookingConfigRepository $onlineBookingConfigRepository,
        private readonly LocaleSwitcher $localeSwitcher,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $installationLocale,
    ) {
    }

    #[Route(
        '/checkin/{token}',
        name: GuestCheckInLinkService::ROUTE,
        requirements: ['token' => '[A-Za-z0-9_-]{'.GuestCheckInTokenSigner::TOKEN_LENGTH.'}'],
        methods: ['GET', 'POST'],
    )]
    public function index(Request $request, string $token): Response
    {
        $locale = $this->applyLocale($request);

        if ($this->rateLimiter->isLinkGuessingBlocked($request)) {
            return $this->unavailable(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $checkIn = $this->linkService->resolve($token);
        $config = $this->configService->findConfig();
        if (null === $checkIn || null === $config) {
            // Only links that never worked count; an expired link of a real stay does not.
            $this->rateLimiter->registerInvalidLink($request);

            return $this->unavailable(Response::HTTP_NOT_FOUND);
        }

        $state = $this->policy->linkState($checkIn->getReservation(), $checkIn, $config->isEnabled());
        if (GuestCheckInLinkState::UNAVAILABLE === $state) {
            return $this->unavailable(Response::HTTP_NOT_FOUND);
        }

        $pagePath = $this->generateUrl(GuestCheckInLinkService::ROUTE, ['token' => $token]);
        if (!$this->verifier->isVerified($request, $checkIn)) {
            return $this->gate($request, $checkIn, $token, $pagePath);
        }

        $reservation = $checkIn->getReservation();
        $showForm = GuestCheckInLinkState::EDITABLE === $state
            && (GuestCheckInStatus::OPEN === $checkIn->getStatus() || $request->query->getBoolean('edit') || $request->isMethod('POST'));

        $form = null;
        if ($showForm) {
            $companionCount = $this->policy->companionCount($reservation);
            $salutations = array_values(array_filter($this->appSettingsService->getSettings()->getCustomerSalutations(), static fn (string $s): bool => '' !== trim($s)));
            $form = $this->createForm(GuestCheckInType::class, $this->formDataFactory->create($checkIn, $companionCount, $salutations), [
                'config' => $config,
                'salutations' => $salutations,
                'companion_count' => $companionCount,
                'stored_id_hint' => $this->formDataFactory->storedIdNumberHint($checkIn),
                'action' => $this->pageUrl($request, $token, ['edit' => 1]),
            ]);
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                if ($this->rateLimiter->isSubmissionBlocked($checkIn->getSelector())) {
                    return $this->unavailable(Response::HTTP_TOO_MANY_REQUESTS);
                }

                if ($form->isValid()) {
                    /** @var GuestCheckInSubmission $submission */
                    $submission = $form->getData();
                    $this->submissionService->submit($checkIn, $submission, $locale);
                    $this->rateLimiter->registerSubmission($checkIn->getSelector());

                    return $this->secure(new RedirectResponse($this->pageUrl($request, $token), Response::HTTP_SEE_OTHER));
                }
            }
        }

        return $this->secure($this->render('GuestCheckIn/public/show.html.twig', [
            'token' => $token,
            'reservation' => $reservation,
            'checkIn' => $checkIn,
            'config' => $config,
            'sections' => $this->sections->visibleFor($checkIn),
            'form' => $form?->createView(),
            'editable' => GuestCheckInLinkState::EDITABLE === $state,
            'submitted' => null !== $checkIn->getFirstSubmittedAt(),
            'primaryColor' => $this->primaryColor(),
        ], new Response(status: null !== $form && $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }

    /** Booking-details check; on success the proof cookie is set and the page reloads. */
    private function gate(Request $request, GuestCheckIn $checkIn, string $token, string $pagePath): Response
    {
        $reservation = $checkIn->getReservation();
        $form = $this->createForm(GuestCheckInVerificationType::class, new GuestCheckInVerification(), [
            'ask_last_name' => $this->verifier->asksLastName($reservation),
            'action' => $this->pageUrl($request, $token),
        ]);
        $form->handleRequest($request);

        $failed = false;
        if ($form->isSubmitted()) {
            // Checked before comparing, so a blocked visitor cannot tell a right guess from a wrong one.
            if ($this->rateLimiter->isVerificationBlocked($checkIn->getSelector())) {
                return $this->unavailable(Response::HTTP_TOO_MANY_REQUESTS);
            }

            /** @var GuestCheckInVerification $input */
            $input = $form->getData();
            if ($form->isValid() && $this->verifier->matches($reservation, $input)) {
                $response = new RedirectResponse($this->pageUrl($request, $token), Response::HTTP_SEE_OTHER);
                $response->headers->setCookie($this->verifier->createCookie($checkIn, $request, $pagePath));

                return $this->secure($response);
            }
            $this->rateLimiter->registerFailedVerification($checkIn->getSelector());
            $failed = true;
        }

        return $this->secure($this->render('GuestCheckIn/public/gate.html.twig', [
            'token' => $token,
            'reservation' => $reservation,
            'form' => $form->createView(),
            'failed' => $failed,
            'primaryColor' => $this->primaryColor(),
        ], new Response(status: $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }

    /** Explicit ?lang= wins, then the browser's languages, then the installation's language. */
    private function applyLocale(Request $request): string
    {
        $requested = $request->query->getString('lang');
        $locale = \in_array($requested, self::SUPPORTED_LOCALES, true)
            ? $requested
            : $request->getPreferredLanguage(array_values(array_unique([$this->installationLocale, ...self::SUPPORTED_LOCALES])));
        $locale = \in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : $this->installationLocale;

        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);

        return $locale;
    }

    /**
     * Keeps an explicitly chosen language across redirects and form posts.
     *
     * @param array<string, int|string> $parameters
     */
    private function pageUrl(Request $request, string $token, array $parameters = []): string
    {
        $lang = $request->query->getString('lang');
        if (\in_array($lang, self::SUPPORTED_LOCALES, true)) {
            $parameters['lang'] = $lang;
        }

        return $this->generateUrl(GuestCheckInLinkService::ROUTE, ['token' => $token, ...$parameters]);
    }

    private function unavailable(int $status): Response
    {
        return $this->secure($this->render('GuestCheckIn/public/unavailable.html.twig', [
            'tooManyRequests' => Response::HTTP_TOO_MANY_REQUESTS === $status,
            'primaryColor' => $this->primaryColor(),
        ], new Response(status: $status)));
    }

    /** Same accent colour as the online booking page, read without creating its settings row. */
    private function primaryColor(): string
    {
        $color = $this->onlineBookingConfigRepository->findSingleton()?->getThemePrimaryColor();

        return \is_string($color) && 1 === preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#1f6feb';
    }

    private function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);

        return $response;
    }
}
