<?php

namespace Tests\Unit;

use App\Domain\Domain;
use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    public function test_normalizes_persian_digits_and_country_code(): void
    {
        $this->assertSame('09121234567', Domain::normalizeMobile('۰۹۱۲۱۲۳۴۵۶۷'));
        $this->assertSame('09121234567', Domain::normalizeMobile('+98 912 123 4567'));
        $this->assertSame('09121234567', Domain::normalizeMobile('9121234567'));
    }

    public function test_validates_iranian_mobile(): void
    {
        $this->assertTrue(Domain::isValidMobile('09121234567'));
        $this->assertFalse(Domain::isValidMobile('0912123456'));
        $this->assertFalse(Domain::isValidMobile('08121234567'));
    }
}
