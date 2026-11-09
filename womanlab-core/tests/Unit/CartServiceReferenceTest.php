<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Services\CartService;

final class CartServiceReferenceTest extends TestCase
{
    public function testOrderReferenceUsesDateAndRandomHexSuffix(): void
    {
        $reference = CartService::orderCode();

        self::assertMatchesRegularExpression('/^ORD-' . date('Ymd') . '-[A-F0-9]{8}$/D', $reference);
        self::assertSame(21, strlen($reference));
    }
}