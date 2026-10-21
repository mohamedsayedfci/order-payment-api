<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_exact_conversion_without_float_arithmetic(string $input, int $minor, string $formatted): void
    {
        self::assertSame($minor, Money::toMinor($input));
        self::assertSame($formatted, Money::format($minor));
    }

    public static function amounts(): array
    {
        return [['0.10', 10, '0.10'], ['19.99', 1999, '19.99'], ['1.2', 120, '1.20'], ['999999.99', 99999999, '999999.99']];
    }

    public function test_invalid_precision_is_rejected_instead_of_silently_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMinor('1.234');
    }
}
