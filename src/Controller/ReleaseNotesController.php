<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ReleaseNotesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Shows the release notes that ship with this installation.
 *
 * Self-hosted operators can read them on GitHub, but hosted customers never
 * would — so the notes are rendered in the application itself, and announced
 * once after every version bump.
 */
#[Route('/release-notes')]
#[IsGranted('IS_AUTHENTICATED')]
final class ReleaseNotesController extends AbstractController
{
    public function __construct(
        private readonly ReleaseNotesService $releaseNotes,
    ) {
    }

    #[Route('/', name: 'release_notes.index', methods: [Request::METHOD_GET])]
    public function index(Request $request): Response
    {
        $locale = $request->getLocale();
        $notes = $this->releaseNotes->getAll($locale);

        $rendered = [];
        foreach ($notes as $note) {
            $rendered[] = [
                'note' => $note,
                'html' => $this->releaseNotes->getHtml($note),
            ];
        }

        return $this->render('ReleaseNotes/index.html.twig', [
            'releaseNotes' => $rendered,
            'currentVersion' => $this->releaseNotes->getCurrentVersion(),
        ]);
    }

    /** Modal body for the "what's new" announcement and for the notification centre. */
    #[Route('/{version}/modal', name: 'release_notes.modal', methods: [Request::METHOD_GET])]
    public function modal(string $version, Request $request): Response
    {
        $note = $this->releaseNotes->get($version, $request->getLocale());
        if (null === $note) {
            throw $this->createNotFoundException();
        }

        return $this->render('ReleaseNotes/modal.html.twig', [
            'note' => $note,
            'html' => $this->releaseNotes->getHtml($note),
        ]);
    }

    /**
     * Marks the current version as announced for the logged-in user.
     *
     * Deliberately a separate write instead of a side effect of rendering the
     * modal: a user who closes the tab before reading should see it again.
     */
    #[Route('/seen', name: 'release_notes.seen', methods: [Request::METHOD_POST])]
    public function seen(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->isCsrfTokenValid('release-notes-seen', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException();
        }

        $user->setLastSeenVersion($this->releaseNotes->getCurrentVersion());
        $em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
