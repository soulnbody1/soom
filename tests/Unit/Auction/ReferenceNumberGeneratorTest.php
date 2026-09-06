<?php

declare(strict_types=1);

namespace Tests\Unit\Auction;

use App\Services\Auction\Payments\Bills\ReferenceNumberGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReferenceNumberGeneratorTest extends TestCase
{
    private const NUMERIC = ['length' => 10, 'charset' => 'numeric', 'check_digit' => true];

    public function test_it_generates_references_of_the_configured_shape(): void
    {
        $generator = new ReferenceNumberGenerator;

        for ($i = 0; $i < 50; $i++) {
            $reference = $generator->generate(self::NUMERIC);

            $this->assertSame(10, strlen($reference));
            $this->assertMatchesRegularExpression('/^\d{10}$/', $reference);
            $this->assertTrue(ReferenceNumberGenerator::isValid($reference, self::NUMERIC));
        }
    }

    public function test_a_mistyped_reference_fails_its_check_digit(): void
    {
        $reference = (new ReferenceNumberGenerator)->generate(self::NUMERIC);
        $digit = (int) $reference[0];
        $mistyped = (string) (($digit + 1) % 10).substr($reference, 1);

        $this->assertFalse(ReferenceNumberGenerator::isValid($mistyped, self::NUMERIC));
        $this->assertFalse(ReferenceNumberGenerator::isValid(substr($reference, 0, 9), self::NUMERIC));
    }

    public function test_references_do_not_repeat(): void
    {
        $generator = new ReferenceNumberGenerator;
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $seen[$generator->generate(self::NUMERIC)] = true;
        }

        $this->assertGreaterThan(190, count($seen));
    }

    public function test_alphanumeric_references_are_supported_without_a_check_digit(): void
    {
        $shape = ['length' => 12, 'charset' => 'alphanumeric', 'check_digit' => false];
        $reference = (new ReferenceNumberGenerator)->generate($shape);

        $this->assertSame(12, strlen($reference));
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{12}$/', $reference);
    }

    public function test_a_check_digit_is_refused_on_a_non_numeric_charset(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReferenceNumberGenerator)->generate(['length' => 12, 'charset' => 'alphanumeric', 'check_digit' => true]);
    }

    public function test_an_unknown_charset_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReferenceNumberGenerator)->generate(['length' => 10, 'charset' => 'hex', 'check_digit' => false]);
    }

    public function test_a_reference_can_be_required_never_to_start_with_zero(): void
    {
        $shape = ['length' => 12, 'charset' => 'numeric', 'check_digit' => true, 'no_leading_zero' => true];
        $generator = new ReferenceNumberGenerator;

        for ($i = 0; $i < 500; $i++) {
            $reference = $generator->generate($shape);

            $this->assertMatchesRegularExpression('/^[1-9]\d{11}$/', $reference);
            $this->assertTrue(ReferenceNumberGenerator::isValid($reference, $shape));
        }
    }

    public function test_a_leading_zero_is_rejected_only_where_the_shape_forbids_it(): void
    {
        $forbidding = ['length' => 10, 'charset' => 'numeric', 'check_digit' => false, 'no_leading_zero' => true];
        $permitting = ['length' => 10, 'charset' => 'numeric', 'check_digit' => false];

        $this->assertFalse(ReferenceNumberGenerator::isValid('0123456789', $forbidding));
        $this->assertTrue(ReferenceNumberGenerator::isValid('1234567890', $forbidding));
        $this->assertTrue(ReferenceNumberGenerator::isValid('0123456789', $permitting));
    }

    public function test_a_reference_is_never_longer_than_the_protocol_ceiling(): void
    {
        $shape = ['length' => 80, 'charset' => 'numeric', 'check_digit' => true, 'no_leading_zero' => true];

        $this->assertSame(
            ReferenceNumberGenerator::MAX_LENGTH,
            strlen((new ReferenceNumberGenerator)->generate($shape))
        );
    }

    public function test_an_alphanumeric_reference_also_honours_the_leading_zero_rule(): void
    {
        $shape = ['length' => 8, 'charset' => 'alphanumeric', 'check_digit' => false, 'no_leading_zero' => true];
        $generator = new ReferenceNumberGenerator;

        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('/^[1-9A-Z][0-9A-Z]{7}$/', $generator->generate($shape));
        }
    }
}
