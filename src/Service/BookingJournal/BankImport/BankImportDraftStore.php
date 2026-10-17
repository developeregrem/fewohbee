<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\BankImportDraft;
use App\Entity\User;
use App\Repository\BankImportDraftRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * In-progress bank statement imports of the current user, stored as BankImportDraft.
 *
 * A draft is only visible to the user who uploaded it and is deleted RETENTION_DAYS after its last
 * change. Changes go through update(), which locks the row for the read-modify-write, so the web
 * UI and an AI assistant can edit the same draft without overwriting each other's changes.
 */
final class BankImportDraftStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BankImportDraftRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function create(ImportState $state): string
    {
        if ('' === $state->sessionImportId) {
            $state->sessionImportId = Uuid::v4()->toRfc4122();
        }

        $this->em->persist(new BankImportDraft($state->sessionImportId, $this->user(), $state->toArray()));
        $this->em->flush();

        return $state->sessionImportId;
    }

    public function load(string $draftId): ?ImportState
    {
        $draft = $this->find($draftId);

        return null !== $draft ? ImportState::fromArray($draft->getState()) : null;
    }

    /**
     * The current user's draft, or null when it does not exist, belongs to someone else or expired.
     */
    public function find(string $draftId): ?BankImportDraft
    {
        $draft = $this->repository->find($draftId);
        if (!$draft instanceof BankImportDraft
            || $draft->getUser()->getId() !== $this->user()->getId()
            || $draft->getExpiresAt() < new \DateTimeImmutable()
        ) {
            return null;
        }

        return $draft;
    }

    /**
     * Reloads the draft under a row lock, lets $change modify it and saves it in one transaction.
     *
     * @param callable(ImportState): void $change
     *
     * @return ImportState|null the saved state, or null when the draft does not exist (anymore)
     */
    public function update(string $draftId, callable $change): ?ImportState
    {
        $draft = $this->find($draftId);
        if (null === $draft) {
            return null;
        }

        return $this->em->wrapInTransaction(function () use ($draft, $change): ImportState {
            // The draft may have been loaded before (e.g. by the argument resolver): refresh it
            // under the lock so the change applies to the latest state.
            $this->em->refresh($draft, LockMode::PESSIMISTIC_WRITE);
            $state = ImportState::fromArray($draft->getState());
            $change($state);
            $draft->setState($state->toArray());
            $this->em->flush();

            return $state;
        });
    }

    public function discard(string $draftId): void
    {
        $draft = $this->find($draftId);
        if (null !== $draft) {
            $this->em->remove($draft);
            $this->em->flush();
        }
    }

    /**
     * The current user's drafts, oldest first. Expired drafts of all users are deleted on the way.
     *
     * @return list<BankImportDraft>
     */
    public function list(): array
    {
        $this->purgeExpired();

        return $this->repository->findBy(['user' => $this->user()], ['createdAt' => 'ASC']);
    }

    /**
     * Deletes drafts that were not changed within the retention period; also run by app:purge-logs.
     */
    public function purgeExpired(): int
    {
        return $this->repository->purgeNotUpdatedSince(new \DateTimeImmutable(\sprintf('-%d days', BankImportDraft::RETENTION_DAYS)));
    }

    private function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Bank statement import drafts need a logged-in user.');
        }

        return $user;
    }
}
