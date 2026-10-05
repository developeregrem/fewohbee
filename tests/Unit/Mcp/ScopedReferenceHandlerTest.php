<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Entity\Enum\ApiScope;
use App\Entity\Enum\McpToolCallOutcome;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolAuditor;
use App\Mcp\Security\McpToolException;
use App\Mcp\Security\ScopedReferenceHandler;
use App\Security\Voter\ApiScopeVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ScopedReferenceHandlerTest extends TestCase
{
    public function testRefusesToolWithoutScopeAttribute(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->expects(self::never())->method('handle');
        $auditor = $this->auditorExpecting(McpToolCallOutcome::DENIED);

        $handler = new ScopedReferenceHandler($inner, $this->checker([]), $auditor);

        $this->expectException(ToolCallException::class);
        $handler->handle($this->reference('unscoped'), []);
    }

    public function testRefusesWhenScopeIsNotGranted(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->expects(self::never())->method('handle');
        $auditor = $this->auditorExpecting(McpToolCallOutcome::DENIED);

        $handler = new ScopedReferenceHandler($inner, $this->checker([ApiScopeVoter::RESERVATIONS_READ]), $auditor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('reservations:write');
        $handler->handle($this->reference('write'), []);
    }

    public function testRunsToolWhenAllScopesAreGranted(): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn(['ok' => true]);
        $auditor = $this->auditorExpecting(McpToolCallOutcome::OK);

        $handler = new ScopedReferenceHandler($inner, $this->checker([ApiScopeVoter::RESERVATIONS_READ]), $auditor);

        self::assertSame(['ok' => true], $handler->handle($this->reference('read'), []));
    }

    public function testConvertsToolExceptionAndAuditsItsOutcome(): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(McpToolException::rateLimited('Slow down.'));
        $auditor = $this->auditorExpecting(McpToolCallOutcome::RATE_LIMITED);

        $handler = new ScopedReferenceHandler($inner, $this->checker([ApiScopeVoter::RESERVATIONS_READ]), $auditor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Slow down.');
        $handler->handle($this->reference('read'), []);
    }

    public function testAuditsUnexpectedErrorsWithoutChangingThem(): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new \RuntimeException('SQLSTATE secret detail'));
        $auditor = $this->auditorExpecting(McpToolCallOutcome::ERROR);

        $handler = new ScopedReferenceHandler($inner, $this->checker([ApiScopeVoter::RESERVATIONS_READ]), $auditor);

        // The SDK turns this into a generic "Error while executing tool" for the client.
        $this->expectException(\RuntimeException::class);
        $handler->handle($this->reference('read'), []);
    }

    public function testPassesNonToolElementsThrough(): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn('prompt');
        $auditor = $this->createMock(McpToolAuditor::class);
        $auditor->expects(self::never())->method('record');

        $handler = new ScopedReferenceHandler($inner, $this->checker([]), $auditor);

        self::assertSame('prompt', $handler->handle(new ElementReference([ScopedToolFixture::class, 'read']), []));
    }

    /**
     * Architecture guard: every MCP tool in the application declares the scopes it needs.
     */
    public function testEveryApplicationToolDeclaresAScope(): void
    {
        $finder = (new Finder())->files()->in(\dirname(__DIR__, 3).'/src/Mcp/Tool')->name('*.php');
        $tools = 0;

        foreach ($finder as $file) {
            $class = 'App\\Mcp\\Tool\\'.$file->getBasename('.php');
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if ([] === $method->getAttributes(McpTool::class)) {
                    continue;
                }
                ++$tools;
                self::assertNotEmpty(
                    ScopedReferenceHandler::requiredScopes([$class, $method->getName()]),
                    sprintf('%s::%s() lacks #[McpRequiresScope].', $class, $method->getName())
                );
            }
        }

        self::assertGreaterThan(0, $tools);
    }

    private function reference(string $method): ToolReference
    {
        return new ToolReference(
            new Tool($method, null, ['type' => 'object', 'properties' => new \stdClass(), 'required' => null], null, null),
            [ScopedToolFixture::class, $method],
        );
    }

    /**
     * @param list<string> $grantedAttributes
     */
    private function checker(array $grantedAttributes): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => \in_array($attribute, $grantedAttributes, true)
        );

        return $checker;
    }

    private function auditorExpecting(McpToolCallOutcome $outcome): McpToolAuditor
    {
        $auditor = $this->createMock(McpToolAuditor::class);
        $auditor->expects(self::once())->method('record')->with(self::anything(), $outcome, self::anything());

        return $auditor;
    }
}

/**
 * @internal
 */
final class ScopedToolFixture
{
    #[McpRequiresScope(ApiScope::RESERVATIONS_READ)]
    public function read(): void
    {
    }

    #[McpRequiresScope(ApiScope::RESERVATIONS_READ, ApiScope::RESERVATIONS_WRITE)]
    public function write(): void
    {
    }

    public function unscoped(): void
    {
    }
}
