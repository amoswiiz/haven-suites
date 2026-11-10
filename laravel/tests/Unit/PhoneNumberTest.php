<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function numbers(): array
    {
        return [
            'local mobile' => ['0712345678', '+254712345678'],
            'local with spaces' => ['0712 345 678', '+254712345678'],
            'safaricom 01 range' => ['0110 123 456', '+254110123456'],
            'international' => ['+254712345678', '+254712345678'],
            'international with punctuation' => ['+254 (712) 345-678', '+254712345678'],
            'without plus' => ['254712345678', '+254712345678'],
            'double zero prefix' => ['00254712345678', '+254712345678'],
            'prefix followed by local zero' => ['+254 0712 345 678', '+254712345678'],
            'kenyan number one digit short' => ['+25470289894', null],
            'kenyan number one digit long' => ['+2547123456789', null],
            'kenyan landline style' => ['+254201234567', null],
            'local number too short' => ['071234567', null],
            'foreign number' => ['+44 20 7946 0958', '+442079460958'],
            'foreign with double zero' => ['0044 20 7946 0958', '+442079460958'],
            'letters' => ['call me', null],
            'untouched prefix' => ['+254', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_numbers_are_normalised_to_international_form(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_landlines_are_allowed_only_when_mobile_numbers_are_not_required(): void
    {
        $this->assertNull(PhoneNumber::normalize('+254 20 123 4567'));
        $this->assertNull(PhoneNumber::normalize('020 123 4567'));
        $this->assertSame('+254201234567', PhoneNumber::normalize('+254 20 123 4567', mobileOnly: false));
        $this->assertSame('+254201234567', PhoneNumber::normalize('020 123 4567', mobileOnly: false));
        $this->assertSame('+254712345678', PhoneNumber::normalize('0712 345 678', mobileOnly: false));
        $this->assertNull(PhoneNumber::normalize('+25470289894', mobileOnly: false));
        $this->assertNull(PhoneNumber::normalize('+254012345678', mobileOnly: false));
    }

    public function test_an_untouched_country_prefix_counts_as_blank(): void
    {
        $this->assertTrue(PhoneNumber::isBlank('+254 '));
        $this->assertTrue(PhoneNumber::isBlank(null));
        $this->assertFalse(PhoneNumber::isBlank('+254 7'));
        $this->assertFalse(PhoneNumber::isBlank('0712345678'));
    }
}
