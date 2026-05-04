<?php

declare(strict_types=1);

namespace App\Controller\Resolver;

use App\Controller\Attribute\ImportDraft;
use App\Exception\BankImportEditException;
use App\Dto\BookingJournal\BankImport\ImportState;
use App\Service\BookingJournal\BankImport\BankImportDraftSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Resolves an {@see ImportState} controller argument tagged with
 * {@see ImportDraft} by:
 *
 *  1. validating the "_token" CSRF token under id "bank_import_line_<id>",
 *  2. loading the draft via {@see BankImportDraftSession}.
 *
 * Failure paths throw a {@see BankImportEditException} that the matching
 * subscriber turns into a JsonResponse, so the JSON edit endpoints no longer
 * each carry the same five lines of guard code.
 */
final class ImportDraftResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly BankImportDraftSession $drafts,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (ImportState::class !== $argument->getType()) {
            return [];
        }
        if ([] === $argument->getAttributesOfType(ImportDraft::class)) {
            return [];
        }

        $sessionImportId = (string) $request->attributes->get('sessionImportId');
        if ('' === $sessionImportId) {
            throw BankImportEditException::draftNotFound();
        }

        $token = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('bank_import_line_'.$sessionImportId, $token))) {
            throw BankImportEditException::invalidToken();
        }

        $state = $this->drafts->load($sessionImportId);
        if (null === $state) {
            throw BankImportEditException::draftNotFound();
        }

        return [$state];
    }
}
