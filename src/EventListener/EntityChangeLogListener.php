<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Enum\LogAction;
use App\Entity\Log;
use App\Entity\MonthlyStatsSnapshot;
use App\Entity\User;
use App\Entity\WorkflowLog;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class EntityChangeLogListener
{
    /** Recursion guard (Log itself), separate audit tables, and request-only caches. */
    private const IGNORED_ENTITIES = [
        Log::class,
        WorkflowLog::class,
        MonthlyStatsSnapshot::class,
    ];

    private const SENSITIVE_FIELD_NEEDLES = [
        'password', 'salt', 'token', 'secret', 'apikey', 'privatekey',
        'iban', 'vatid', 'card', 'cvv',
    ];

    /** LastActionSubscriber writes this on every request; filter defensively. */
    private const NOISY_FIELDS = ['lastAction'];

    private const MAX_STRING_LENGTH = 1000;

    /** @var list<array{action: LogAction, entity: object, class: string, id: ?string, changes: array<string, array{0: mixed, 1: mixed}>}> */
    private array $buffer = [];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (!$this->isPrimaryEm($em)) {
            return;
        }

        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->isIgnored($entity)) {
                continue;
            }
            $this->buffer[] = [
                'action' => LogAction::CREATE,
                'entity' => $entity,
                'class' => $em->getClassMetadata($entity::class)->getName(),
                'id' => $this->getEntityId($entity, $em),
                'changes' => $this->filterNoise($uow->getEntityChangeSet($entity)),
            ];
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isIgnored($entity)) {
                continue;
            }
            $changes = $this->filterNoise($uow->getEntityChangeSet($entity));
            if ([] === $changes) {
                continue;
            }
            $this->buffer[] = [
                'action' => LogAction::UPDATE,
                'entity' => $entity,
                'class' => $em->getClassMetadata($entity::class)->getName(),
                'id' => $this->getEntityId($entity, $em),
                'changes' => $changes,
            ];
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->isIgnored($entity)) {
                continue;
            }
            $this->buffer[] = [
                'action' => LogAction::DELETE,
                'entity' => $entity,
                'class' => $em->getClassMetadata($entity::class)->getName(),
                'id' => $this->getEntityId($entity, $em),
                'changes' => $this->snapshot($entity, $em),
            ];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->buffer) {
            return;
        }

        $em = $args->getObjectManager();
        if (!$this->isPrimaryEm($em)) {
            return;
        }

        $buffer = $this->buffer;
        $this->buffer = [];

        $user = $this->security->getUser();
        $username = $user instanceof User ? $user->getUserIdentifier() : null;
        $userId = $user instanceof User ? $user->getId() : null;
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp();
        $now = new \DateTimeImmutable();

        // background EM isolates audit writes from the primary flush so they cannot recurse or pick up unrelated dirty entities.
        try {
            $logEm = $this->registry->getManager('background');
            if (!$logEm instanceof EntityManagerInterface || !$logEm->isOpen()) {
                $logEm = $this->registry->resetManager('background');
            }
            if (!$logEm instanceof EntityManagerInterface) {
                return;
            }

            $userRef = null !== $userId ? $logEm->getReference(User::class, $userId) : null;

            foreach ($buffer as $item) {
                $log = new Log();
                $log->setDate($now);
                $log->setEntityClass($item['class']);
                $log->setEntityId($item['id'] ?? $this->getEntityId($item['entity'], $em));
                $log->setAction($item['action']);
                $log->setChanges($this->sanitize($item['changes']));
                $log->setUser($userRef);
                $log->setUsername($username);
                $log->setIpAddress($ip);
                $logEm->persist($log);
            }
            $logEm->flush();
        } catch (\Throwable $e) {
            // Best-effort: log but never block the request on audit-write failures.
            $this->logger->error('Failed to persist audit log rows: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function isPrimaryEm(object $em): bool
    {
        return $em === $this->registry->getManager('default');
    }

    private function isIgnored(object $entity): bool
    {
        foreach (self::IGNORED_ENTITIES as $cls) {
            if ($entity instanceof $cls) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function filterNoise(array $changes): array
    {
        foreach (self::NOISY_FIELDS as $field) {
            unset($changes[$field]);
        }

        foreach ($changes as $field => [$old, $new]) {
            if ($this->isEffectivelyUnchanged($old, $new)) {
                unset($changes[$field]);
            }
        }

        return $changes;
    }

    // Forms send "" where the column is NULL; 0/false/"0" do not normalize to null so genuine changes still log.
    private function isEffectivelyUnchanged(mixed $old, mixed $new): bool
    {
        return $this->normalizeEmpty($old) === $this->normalizeEmpty($new);
    }

    private function normalizeEmpty(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }
        if (is_string($value) && '' === trim($value)) {
            return null;
        }
        if (is_array($value) && [] === $value) {
            return null;
        }
        if ($value instanceof \Countable && 0 === count($value)) {
            return null;
        }

        return $value;
    }

    private function getEntityId(object $entity, EntityManagerInterface $em): ?string
    {
        $meta = $em->getClassMetadata($entity::class);
        $ids = $meta->getIdentifierValues($entity);
        if ([] === $ids) {
            return null;
        }

        $parts = [];
        foreach ($ids as $id) {
            $parts[] = is_scalar($id) ? (string) $id : (string) $this->normalize($id);
        }

        return implode(',', $parts);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function snapshot(object $entity, EntityManagerInterface $em): array
    {
        $meta = $em->getClassMetadata($entity::class);
        $snapshot = [];
        foreach ($meta->getFieldNames() as $field) {
            $snapshot[$field] = [$meta->getFieldValue($entity, $field), null];
        }

        return $this->filterNoise($snapshot);
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     *
     * @return array<string, array{0: mixed, 1: mixed}|array{0: string, 1: string}>
     */
    private function sanitize(array $changes): array
    {
        $out = [];
        foreach ($changes as $field => $values) {
            if ($this->isSensitive($field)) {
                $out[$field] = ['***redacted***', '***redacted***'];
                continue;
            }
            $out[$field] = [$this->normalize($values[0] ?? null), $this->normalize($values[1] ?? null)];
        }

        return $out;
    }

    private function isSensitive(string $field): bool
    {
        $lower = strtolower($field);
        foreach (self::SENSITIVE_FIELD_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_object($value)) {
            $cls = $this->shortClass($value::class);
            if (method_exists($value, 'getId') && null !== $value->getId()) {
                return $cls.'#'.(string) $value->getId();
            }
            if ($value instanceof \BackedEnum) {
                return (string) $value->value;
            }

            return $cls;
        }
        if (is_array($value) || $value instanceof \Countable) {
            return sprintf('array(%d)', count($value));
        }
        if (is_string($value)) {
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '?', $value);
            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_STRING_LENGTH).'…(truncated)';
            }

            return $value;
        }

        return $value;
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return false === $pos ? $fqcn : substr($fqcn, $pos + 1);
    }
}
