<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use App\Entity\ApiToken;
use App\Entity\Enum\McpToolCallOutcome;
use App\Entity\McpToolCallLog;
use App\Entity\User;
use App\Security\ApiTokenContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Writes one McpToolCallLog row per tool call. Tools may add details during the call through
 * note(); callers must only pass ids, dates, counts and similar values, never guest data.
 *
 * Rows go through the "background" entity manager so a failed tool (possibly with a closed or
 * dirty default entity manager) can still be audited without flushing its half-done work.
 */
class McpToolAuditor implements ResetInterface
{
    private const CLIENT_MAX_LENGTH = 100;

    /** @var array<string, mixed> */
    private array $details = [];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ApiTokenContext $apiTokenContext,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'monolog.logger.mcp')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Adds non-personal details (ids, dates, counts) to the entry of the running call.
     *
     * @param int|string|bool|list<int|string>|null $value
     */
    public function note(string $key, int|string|bool|null|array $value): void
    {
        $this->details[$key] = $value;
    }

    public function record(string $toolName, McpToolCallOutcome $outcome, int $durationMs): void
    {
        $details = $this->details;
        $this->details = [];

        $user = $this->security->getUser();
        $apiToken = $this->apiTokenContext->getToken();
        $request = $this->requestStack->getCurrentRequest();

        $this->logger->info('MCP tool call {tool}: {outcome}', [
            'tool' => $toolName,
            'outcome' => $outcome->value,
            'duration_ms' => $durationMs,
            'token_prefix' => $apiToken?->getTokenPrefix(),
        ]);

        try {
            $em = $this->registry->getManager('background');
            if (!$em instanceof EntityManagerInterface || !$em->isOpen()) {
                $em = $this->registry->resetManager('background');
            }
            if (!$em instanceof EntityManagerInterface) {
                return;
            }

            $log = new McpToolCallLog(mb_substr($toolName, 0, 100), $outcome, new \DateTimeImmutable());
            $log->setDurationMs($durationMs)
                ->setDetails([] === $details ? null : $details)
                ->setUsername($user?->getUserIdentifier())
                ->setTokenPrefix($apiToken?->getTokenPrefix())
                ->setIpAddress($request?->getClientIp());

            if ($user instanceof User && null !== $user->getId()) {
                $log->setUser($em->getReference(User::class, $user->getId()));
            }
            if ($apiToken instanceof ApiToken && null !== $apiToken->getId()) {
                $log->setApiToken($em->getReference(ApiToken::class, $apiToken->getId()));
            }
            $client = (string) $request?->headers->get('User-Agent', '');
            if ('' !== $client) {
                $log->setClient(mb_substr($client, 0, self::CLIENT_MAX_LENGTH));
            }

            $em->persist($log);
            $em->flush();
        } catch (\Throwable $e) {
            // Best effort like EntityChangeLogListener: never fail the tool call on an audit write.
            $this->logger->error('Failed to persist MCP audit entry: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    public function reset(): void
    {
        $this->details = [];
    }
}
