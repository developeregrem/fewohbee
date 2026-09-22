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
            'scopes' => [ApiScope::STATISTICS_READ->value],
            'mcpScopes' => [ApiScope::GUESTS_READ->value],
        ]);

        self::assertSame([ApiScope::STATISTICS_READ->value, ApiScope::MCP_ACCESS->value, ApiScope::GUESTS_READ->value], $scopes);
    }

    public function testRestTokenIgnoresAiOptions(): void
    {
        $scopes = ApiTokenType::collectScopes([
            'kind' => ApiTokenType::KIND_API,
            'scopes' => [ApiScope::CALENDAR_READ->value],
            'mcpScopes' => [ApiScope::RESERVATIONS_WRITE->value],
        ]);

        self::assertSame([ApiScope::CALENDAR_READ->value], $scopes);
    }

    public function testTokenWithoutKindIsARestToken(): void
    {
        self::assertSame([ApiScope::PRICES_READ->value], ApiTokenType::collectScopes(['scopes' => [ApiScope::PRICES_READ->value]]));
    }
}
