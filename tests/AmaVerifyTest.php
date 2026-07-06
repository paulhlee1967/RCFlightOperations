<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AmaVerifyTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/fixtures/' . $name;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testResponseToHtmlExtractsDrupalAjaxPayload(): void
    {
        $raw  = $this->fixture('ama_valid_drupal_ajax.json');
        $html = ama_verify_response_to_html($raw);

        $this->assertStringContainsString('is valid until', $html);
        $this->assertStringContainsString('JOHN SMITH', $html);
    }

    public function testParseValidMembershipFromDrupalAjax(): void
    {
        $html   = ama_verify_response_to_html($this->fixture('ama_valid_drupal_ajax.json'));
        $result = ama_verify_parse_html($html, 'Smith');

        $this->assertTrue($result['ok']);
        $this->assertSame('valid', $result['status']);
        $this->assertSame('2026-12-31', $result['expiration_ymd']);
        $this->assertSame('12/31/2026', $result['expiration_mdy']);
        $this->assertFalse($result['life_member']);
    }

    public function testParseNoMatch(): void
    {
        $html   = ama_verify_response_to_html($this->fixture('ama_no_match_drupal_ajax.json'));
        $result = ama_verify_parse_html($html, 'Doe');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_match', $result['status']);
    }

    public function testParseParkPilotAsInvalidType(): void
    {
        $html   = ama_verify_response_to_html($this->fixture('ama_park_pilot_drupal_ajax.json'));
        $result = ama_verify_parse_html($html, 'Doe');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_type', $result['status']);
    }

    public function testParseLifeMember(): void
    {
        $html   = $this->fixture('ama_life_member.html');
        $result = ama_verify_parse_html($html, 'Jones');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['life_member']);
        $this->assertNotNull($result['expiration_ymd']);
        $this->assertSame('Jones', $result['last_name']);
    }

    public function testNormalizeAmaNumberStripsNumericSeparators(): void
    {
        $this->assertSame('123456', ama_verify_normalize_number(' 12-3456 '));
    }

    public function testNormalizeAmaNumberPreservesAlphanumeric(): void
    {
        $this->assertSame('IFLYRC', ama_verify_normalize_number('i fly rc'));
        $this->assertSame('L330', ama_verify_normalize_number('l330'));
        $this->assertSame('FLY#1', ama_verify_normalize_number('fly#1'));
    }

    public function testValidateRejectsShortAmaNumber(): void
    {
        $this->assertNotNull(ama_verify_validate_inputs('12', 'Smith'));
    }

    public function testMeetsRequiredDate(): void
    {
        $this->assertTrue(ama_verify_meets_required_date('12/31/2026', '12/31/2026'));
        $this->assertFalse(ama_verify_meets_required_date('12/30/2025', '12/31/2026'));
    }

    public function testApiJsonShapeBackwardCompatible(): void
    {
        $api = ama_verify_to_api_json(ama_verify_result(true, 'valid', 'OK', '2026-01-01', '01/01/2026', false));
        $this->assertTrue($api['valid']);
        $this->assertSame('2026-01-01', $api['expiration']);
        $this->assertArrayHasKey('life_member', $api);
        $this->assertArrayHasKey('status', $api);
    }
}
