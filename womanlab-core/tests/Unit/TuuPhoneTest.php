<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Services\TuuClient;

final class TuuPhoneTest extends TestCase
{
    public function testNormalizesChileanNumbers(): void
    {
        self::assertSame('+56996665555', TuuClient::normalizePhone('96665555'));
        self::assertSame('+56956336699', TuuClient::normalizePhone('956336699'));
        self::assertSame('+56956336699', TuuClient::normalizePhone('(9) 5633 6699'));
        self::assertSame('+56956336699', TuuClient::normalizePhone('56956336699'));
        self::assertSame('+56912345678', TuuClient::normalizePhone('+56 9 1234 5678'));
        self::assertSame('+56900000000', TuuClient::normalizePhone(''));
    }
}
