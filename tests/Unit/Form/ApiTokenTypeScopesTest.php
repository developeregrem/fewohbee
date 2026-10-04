<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Enum\ApiScope;
use App\Form\ApiTokenType;
use PHPUnit\Framework\TestCase;

final class ApiTokenTypeScopesTest extends TestCase
{
    public function testAiTokenGetsMcpAccessAndItsOptions(): void
    {
        $scopes = ApiTokenType::collectScopes([
            'kind' => ApiTokenType::KIND_MCP,
            'scopes' => [ApiScope::STATISTICS_READ->value, ApiScope::GUESTS_READ->value],
        ]);

        self::assertSame([ApiScope::STATISTICS_READ->value, ApiScope::GUESTS_READ->value, ApiScope::MCP_ACCESS->value], $scopes);
    }

    public function testAiTokenDropsScopesOnlyTheRestApiEvaluates(): void
    {
        $scopes = ApiTokenType::collectScopes([
            'kind' => ApiTokenType::KIND_MCP,
            'scopes' => [ApiScope::CALENDAR_READ->value, ApiScope::PRICES_READ->value],
        ]);

        self::assertSame([ApiScope::PRICES_READ->value, ApiScope::MCP_ACCESS->value], $scopes);
    }

    public function testRestTokenIgnoresAiOptions(): void
    {
        $scopes = ApiTokenType::collectScopes([
            'kind' => ApiTokenType::KIND_API,
            'scopes' => [ApiScope::CALENDAR_READ->value, ApiScope::RESERVATIONS_WRITE->value, 'unknown:scope'],
        ]);

        self::assertSame([ApiScope::CALENDAR_READ->value], $scopes);
    }

    public function testTokenWithoutKindIsARestToken(): void
    {
        self::assertSame([ApiScope::PRICES_READ->value], ApiTokenType::collectScopes(['scopes' => [ApiScope::PRICES_READ->value]]));
    }

    public function testEveryChoosableScopeHasAGroupAndAKindOfAccess(): void
    {
        foreach (ApiScope::cases() as $scope) {
            if (ApiScope::MCP_ACCESS === $scope) {
                self::assertNull($scope->group());
                continue;
            }
            self::assertNotNull($scope->group(), $scope->value);
            self::assertTrue($scope->isForRest() || $scope->isForMcp(), $scope->value);
        }
    }
}
