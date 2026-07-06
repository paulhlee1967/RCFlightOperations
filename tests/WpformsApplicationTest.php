<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/member_import_helpers.php';
require_once dirname(__DIR__) . '/includes/wpforms_application.php';

final class WpformsApplicationTest extends TestCase
{
    public function test_infer_renewal_from_sample_entry_11(): void
    {
        $fields = [
            'new_or_renewal'           => 'Renewal',
            'new_member_closed'        => '',
            'membership_type'          => '',
            'membership_type_renewal'  => 'Adult - $150.00',
            'membership_type_prorated' => '',
        ];
        $result = wpforms_application_infer_kind_season($fields);
        $this->assertSame('renewal', $result['kind']);
        $this->assertSame('renewal_window', $result['season']);
    }

    public function test_infer_new_during_renewal_season_entry_182(): void
    {
        $fields = [
            'new_or_renewal'           => 'New Member',
            'new_member_closed'        => '',
            'membership_type'          => 'Adult - $160.00',
            'membership_type_renewal'  => '',
            'membership_type_prorated' => '',
        ];
        $result = wpforms_application_infer_kind_season($fields);
        $this->assertSame('new', $result['kind']);
        $this->assertSame('renewal_window', $result['season']);
    }

    public function test_infer_regular_new_entry_335(): void
    {
        $fields = [
            'new_or_renewal'           => '',
            'new_member_closed'        => 'New Member',
            'membership_type'          => 'Adult - $160.00',
            'membership_type_renewal'  => '',
            'membership_type_prorated' => '',
        ];
        $result = wpforms_application_infer_kind_season($fields);
        $this->assertSame('new', $result['kind']);
        $this->assertSame('regular_new', $result['season']);
    }

    public function test_infer_prorated_new_entry_129(): void
    {
        $fields = [
            'new_or_renewal'           => '',
            'new_member_closed'        => '',
            'membership_type'          => '',
            'membership_type_renewal'  => '',
            'membership_type_prorated' => 'Adult - $75.00',
        ];
        $result = wpforms_application_infer_kind_season($fields);
        $this->assertSame('new', $result['kind']);
        $this->assertSame('prorated_new', $result['season']);
    }

    public function test_infer_prorated_when_automator_sends_ghost_closed_field(): void
    {
        $fields = [
            'new_or_renewal'           => '',
            'new_member_closed'        => 'New Member',
            'membership_type'          => 'Adult - $75.00',
            'membership_type_renewal'  => '',
            'membership_type_prorated' => '',
        ];
        $result = wpforms_application_infer_kind_season($fields, '2026-07-05');
        $this->assertSame('new', $result['kind']);
        $this->assertSame('prorated_new', $result['season']);
        $this->assertSame('Adult - $75.00', $result['membership_label']);
    }

    public function test_infer_prorated_takes_priority_over_ghost_closed_field(): void
    {
        $fields = [
            'new_or_renewal'           => '',
            'new_member_closed'        => 'New Member',
            'membership_type'          => 'Adult - $160.00',
            'membership_type_renewal'  => '',
            'membership_type_prorated' => 'Adult - $75.00',
        ];
        $result = wpforms_application_infer_kind_season($fields, '2026-07-05');
        $this->assertSame('prorated_new', $result['season']);
        $this->assertSame('Adult - $75.00', $result['membership_label']);
    }

    public function test_infer_regular_new_respects_january_submission_date(): void
    {
        $fields = [
            'new_or_renewal'           => '',
            'new_member_closed'        => 'New Member',
            'membership_type'          => 'Adult - $160.00',
            'membership_type_renewal'  => '',
            'membership_type_prorated' => '',
        ];
        $result = wpforms_application_infer_kind_season($fields, '2026-03-15');
        $this->assertSame('regular_new', $result['season']);
    }

    public function test_new_member_season_from_date_boundaries(): void
    {
        $this->assertSame('regular_new', wpforms_application_new_member_season_from_date('2026-06-30'));
        $this->assertSame('prorated_new', wpforms_application_new_member_season_from_date('2026-07-01'));
        $this->assertSame('prorated_new', wpforms_application_new_member_season_from_date('2026-10-14'));
        $this->assertSame('renewal_window', wpforms_application_new_member_season_from_date('2026-10-15'));
    }

    public function test_suggested_renewal_type_mapping(): void
    {
        $this->assertSame('on_time', wpforms_application_suggested_renewal_type('renewal', 'renewal_window'));
        $this->assertSame('late', wpforms_application_suggested_renewal_type('new', 'regular_new'));
        $this->assertSame('late', wpforms_application_suggested_renewal_type('new', 'renewal_window'));
        $this->assertSame('new', wpforms_application_suggested_renewal_type('new', 'prorated_new'));
    }

    public function test_membership_type_slot_strips_price_suffix(): void
    {
        $labels = [1 => 'Adult', 2 => 'Youth'];
        $this->assertSame(1, normalizeMembershipTypeSlot('Adult - $160.00', $labels));
        $this->assertSame(2, normalizeMembershipTypeSlot('Youth - $40.00', $labels));
    }

    public function test_parse_gateway_info(): void
    {
        $text = "Total: 216.58\nCurrency: USD\nGateway: Stripe\nTransaction: pi_abc123";
        $parsed = wpforms_application_parse_gateway_info($text);
        $this->assertSame('Stripe', $parsed['gateway']);
        $this->assertSame('pi_abc123', $parsed['transaction_id']);
        $this->assertSame(216.58, $parsed['total']);
    }

    public function test_parse_money_rejects_phones_and_membership_labels(): void
    {
        $this->assertSame(0.0, wpforms_application_parse_money('$0.00'));
        $this->assertSame(50.0, wpforms_application_parse_money('$50.00'));
        $this->assertSame(4.19, wpforms_application_parse_money('$4.19'));
        $this->assertSame(134.19, wpforms_application_parse_money('134.19'));
        $this->assertNull(wpforms_application_parse_money('Adult - $80.00'));
        $this->assertNull(wpforms_application_parse_money('+17143238868'));
        $this->assertNull(wpforms_application_parse_money('759314'));
        $this->assertNull(wpforms_application_parse_money('360'));
    }

    public function test_parse_money_handles_html_entity_dollar_signs_from_automator(): void
    {
        $this->assertSame(50.0, wpforms_application_parse_money('&#36;50.00'));
        $this->assertSame(4.19, wpforms_application_parse_money('&#36;4.19'));
        $this->assertSame(134.19, wpforms_application_parse_money('&#36;134.19'));
        $this->assertSame(50.0, wpforms_application_parse_money('&amp;#36;50.00'));
        $this->assertSame(134.19, wpforms_application_parse_money('&amp;#36;134.19'));
    }

    public function test_parse_dues_from_label_handles_html_entity_dollar_signs(): void
    {
        $this->assertSame(75.0, wpforms_application_parse_dues_from_label('Adult - &#36;75.00'));
        $this->assertSame(150.0, wpforms_application_parse_dues_from_label('Adult - &amp;#36;150.00'));
    }

    public function test_parse_dues_from_membership_label(): void
    {
        $this->assertSame(80.0, wpforms_application_parse_dues_from_label('Adult - $80.00'));
        $this->assertSame(150.0, wpforms_application_parse_dues_from_label('Adult - $150.00'));
    }

    public function test_payment_breakdown_for_coupon_entry(): void
    {
        $application = [
            'payment_total' => 360.0,
            'payment_initiation' => 3650.0,
            'notes' => "Special code: PAULTEST",
            'raw_payload' => json_encode([
                'Membership Type (Prorated)' => 'Adult - $80.00',
                'Initiation Fee' => '$50.00',
                'Processing Fee' => '$4.19',
                'Total (Membership + Fees)' => '$0.00',
                'Special Code (If you have one)' => 'PAULTEST',
            ]),
        ];
        $payment = application_payment_breakdown($application);
        $this->assertSame(80.0, $payment['membership_dues']);
        $this->assertSame(50.0, $payment['initiation']);
        $this->assertSame(4.19, $payment['processing']);
        $this->assertSame(134.19, $payment['subtotal']);
        $this->assertSame(0.0, $payment['total_paid']);
        $this->assertTrue($payment['coupon_applied']);
    }

    public function test_payment_breakdown_without_pdo_skips_numeric_dues_lookup(): void
    {
        $application = [
            'application_kind' => 'new',
            'form_season' => 'prorated_new',
            'membership_type_slot' => 1,
            'raw_payload' => json_encode([
                'Membership Type (Prorated)' => '1',
                'Initiation Fee' => '&#36;50.00',
                'Processing Fee' => '&#36;4.19',
                'Total (Membership + Fees)' => '&#36;134.19',
            ]),
        ];
        $payment = application_payment_breakdown($application);
        $this->assertNull($payment['membership_dues']);
        $this->assertSame(50.0, $payment['initiation']);
        $this->assertSame(134.19, $payment['total_paid']);
    }

    public function test_dues_renewal_type_for_prorated_season(): void
    {
        $this->assertSame('new', wpforms_application_dues_renewal_type('new', 'prorated_new'));
        $this->assertSame('on_time', wpforms_application_dues_renewal_type('renewal', 'renewal_window'));
        $this->assertSame('late', wpforms_application_dues_renewal_type('new', 'regular_new'));
    }

    public function test_infer_prorated_before_raw_new_or_renewal_option_index(): void
    {
        $fields = [
            'new_or_renewal'           => '1',
            'new_member_closed'        => '1',
            'membership_type'          => '',
            'membership_type_renewal'  => '',
            'membership_type_prorated' => '1',
        ];
        $result = wpforms_application_infer_kind_season($fields, '2026-07-03');
        $this->assertSame('new', $result['kind']);
        $this->assertSame('prorated_new', $result['season']);
        $this->assertSame('1', $result['membership_label']);
        $this->assertSame(1, wpforms_application_membership_slot_from_choice($result['membership_label'], [1 => 'Adult']));
    }

    public function test_infer_prorated_before_ghost_new_member_closed_field(): void
    {
        $fields = [
            'new_or_renewal'           => '',
            'new_member_closed'        => 'New Member',
            'membership_type'          => '',
            'membership_type_renewal'  => '',
            'membership_type_prorated' => 'Adult - &#36;80.00',
        ];
        $result = wpforms_application_infer_kind_season($fields, '2026-07-05');
        $this->assertSame('prorated_new', $result['season']);
        $this->assertSame('Adult - &#36;80.00', $result['membership_label']);
        $labels = [1 => 'Adult'];
        $this->assertSame(1, wpforms_application_membership_slot_from_choice($result['membership_label'], $labels));
    }

    public function test_normalize_status_choice_maps_raw_option_index(): void
    {
        $this->assertSame('new member', wpforms_application_normalize_status_choice('1'));
        $this->assertSame('renewal', wpforms_application_normalize_status_choice('Renewal'));
    }

    public function test_membership_slot_from_numeric_prorated_choice(): void
    {
        $labels = [1 => 'Adult', 2 => 'Youth'];
        $this->assertSame(1, wpforms_application_membership_slot_from_choice('1', $labels));
        $this->assertSame(1, wpforms_application_membership_slot_from_choice('Adult - $80.00', $labels));
    }

    public function test_list_filters_default_to_current_renewal_year(): void
    {
        $filters = application_parse_list_filters(null, ['status' => 'pending']);
        $this->assertSame('pending', $filters['status']);
        $this->assertSame(defaultRenewalYear(), $filters['year']);
        $this->assertTrue($filters['year_is_default']);
    }

    public function test_list_filters_all_years(): void
    {
        $filters = application_parse_list_filters(null, ['status' => 'all', 'year' => 'all', 'q' => 'Paul']);
        $this->assertSame('all', $filters['status']);
        $this->assertSame(0, $filters['year']);
        $this->assertSame('Paul', $filters['search']);
    }

    public function test_list_where_clause_includes_renewal_year(): void
    {
        $clause = application_list_where_clause([
            'status' => 'pending',
            'year'   => 2026,
            'search' => '',
        ]);
        $this->assertStringContainsString('suggested_renewal_year', $clause['where']);
        $this->assertSame(['pending', 2026], $clause['params']);
    }

    public function test_list_per_page_is_fifty(): void
    {
        $this->assertSame(50, application_list_per_page());
    }

    public function test_list_page_url_omits_first_page(): void
    {
        $url = application_list_page_url('pending', 2026, '', 2026, ['page' => 1, 'id' => 5]);
        $this->assertStringContainsString('id=5', $url);
        $this->assertStringNotContainsString('page=', $url);
    }

    public function test_list_page_url_includes_later_pages(): void
    {
        $url = application_list_page_url('approved', 2026, 'lee', 2027, ['page' => 3]);
        $this->assertStringContainsString('page=3', $url);
        $this->assertStringContainsString('status=approved', $url);
        $this->assertStringContainsString('q=lee', $url);
    }

    public function test_automator_json_labels_have_aliases(): void
    {
        $automatorLabels = [
            'Name: First',
            'Name: Middle',
            'Name: Last',
            'Address: Address Line 1',
            'Address: Address Line 2',
            'Address: City',
            'Address: State',
            'Address: Zip',
            'Phone',
            'Emergency Contact',
            'Emergency Phone',
            'Email',
            'Relationship',
            'Date of Birth',
            'New Member or Renewal',
            'New Member (Renewal Period Closed)',
            'Initiation Fee',
            'Membership Type',
            'Membership Type (Renewal)',
            'Membership Type (Prorated)',
            'Processing Fee',
            'Total (Membership + Fees)',
            'FAA Registration Number',
            'FAA Registration Expiration',
            'AMA #',
            'AMA Expiration',
            'Entry ID',
            'Application Submission Date',
            'Badge Photo (.jpg, .pdf, .png, .doc), 5Mb Max',
            'AMA Verification (.jpg, .pdf, .png, .doc), 5Mb Max',
            'FAA Registration (.jpg, .pdf, .png, .doc), 5Mb Max',
        ];

        $aliases = wpforms_application_field_aliases();
        $allAliasKeys = [];
        foreach ($aliases as $keys) {
            foreach ($keys as $key) {
                $allAliasKeys[$key] = true;
            }
        }

        foreach ($automatorLabels as $label) {
            $this->assertArrayHasKey($label, $allAliasKeys, 'Missing WPForms alias for: ' . $label);
        }
    }

    public function test_pick_values_from_automator_payload(): void
    {
        $payload = [
            'Name: First' => 'Jane',
            'Name: Middle' => 'Q',
            'Name: Last' => 'Pilot',
            'Address: Address Line 1' => '123 Main St',
            'Address: Address Line 2' => 'Apt 4',
            'Address: City' => 'Anytown',
            'Address: State' => 'CA',
            'Address: Zip' => '90210',
            'Phone' => '555-123-4567',
            'Emergency Contact' => 'John Pilot',
            'Emergency Phone' => '555-987-6543',
            'Email' => 'jane@example.com',
            'Relationship' => 'Spouse',
            'AMA #' => '931788',
            'Entry ID' => 'test-entry-99',
        ];

        $flat = wpforms_application_flatten_payload($payload);
        $aliases = wpforms_application_field_aliases();

        $this->assertSame('Jane', wpforms_application_pick_value($flat, $aliases['first_name']));
        $this->assertSame('Pilot', wpforms_application_pick_value($flat, $aliases['last_name']));
        $this->assertSame('jane@example.com', wpforms_application_pick_value($flat, $aliases['email']));
        $this->assertSame('555-123-4567', wpforms_application_pick_value($flat, $aliases['phone']));
        $this->assertSame('John Pilot', wpforms_application_pick_value($flat, $aliases['emergency_contact_name']));
        $this->assertSame('555-987-6543', wpforms_application_pick_value($flat, $aliases['emergency_contact_phone']));
        $this->assertSame('123 Main St', wpforms_application_pick_value($flat, $aliases['address_street']));
        $this->assertSame('90210', wpforms_application_pick_value($flat, $aliases['address_postal_code']));
        $this->assertSame('931788', wpforms_application_pick_value($flat, $aliases['ama_number']));
        $this->assertSame('test-entry-99', wpforms_application_pick_value($flat, $aliases['wpforms_entry_id']));
    }

    public function test_webhook_email_normalized_to_lowercase(): void
    {
        $this->assertSame('jane@example.com', normalize_email('  Jane@Example.COM  '));
    }
}
