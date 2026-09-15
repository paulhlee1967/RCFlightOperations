<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BadgePrintHelpersTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function sampleDesigns(): array
    {
        return [
            ['id' => 1, 'name' => 'Oldest', 'template_data' => '{"canvas":{}}', 'is_default' => 0],
            ['id' => 2, 'name' => 'Default', 'template_data' => '{"orientation":"landscape"}', 'is_default' => 1],
            ['id' => 3, 'name' => 'Other', 'template_data' => '', 'is_default' => 0],
        ];
    }

    public function testSelectDesignUsesRequestedId(): void
    {
        $result = badge_print_select_design($this->sampleDesigns(), 3);

        $this->assertSame(3, $result['designId']);
        $this->assertSame('Other', $result['design']['name']);
        $this->assertNull($result['templateData']);
    }

    public function testSelectDesignFallsBackToDefault(): void
    {
        $result = badge_print_select_design($this->sampleDesigns(), 0);

        $this->assertSame(2, $result['designId']);
        $this->assertSame('landscape', $result['templateData']['orientation']);
    }

    public function testSelectDesignFallsBackToFirstWhenNoDefault(): void
    {
        $designs = [
            ['id' => 10, 'name' => 'Only', 'template_data' => '{"canvas":[]}', 'is_default' => 0],
        ];
        $result = badge_print_select_design($designs, 0);

        $this->assertSame(10, $result['designId']);
        $this->assertIsArray($result['templateData']['canvas']);
    }

    public function testSelectDesignReturnsEmptyWhenNoDesigns(): void
    {
        $result = badge_print_select_design([], 99);

        $this->assertSame(0, $result['designId']);
        $this->assertNull($result['design']);
        $this->assertNull($result['templateData']);
    }
}
