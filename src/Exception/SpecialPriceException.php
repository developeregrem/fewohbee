<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A special-period price was refused (unknown price row, invalid period, ...). The message is
 * English and safe to show to MCP clients.
 */
class SpecialPriceException extends \RuntimeException
{
}
