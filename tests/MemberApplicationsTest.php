<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/member_applications.php';

final class MemberApplicationsTest extends TestCase
{
    public function test_parse_money(): void
    {
        $this->assertSame(0.0, application_parse_money('$0.00'));
        $this->assertSame(50.0, application_parse_money('$50.00'));
        $this->assertSame(4.19, application_parse_money('$4.19'));
        $this->assertSame(134.19, application_parse_money('134.19'));
        $this->assertNull(application_parse_money('not-a-number'));
    }

    public function test_payment_breakdown_for_coupon_entry(): void
    {
        $application = [
            'payment_total'            => 0.0,
            'payment_initiation'       => 50.0,
            'payment_processing_fee'   => 4.19,
            'membership_type_slot'     => 1,
            'suggested_renewal_type'   => 'new',
            'notes'                    => 'Coupon code: PAULTEST',
        ];
        $payment = application_payment_breakdown($application);
        $this->assertSame(50.0, $payment['initiation']);
        $this->assertSame(4.19, $payment['processing']);
        $this->assertSame(0.0, $payment['total_paid']);
        $this->assertSame('PAULTEST', $payment['special_code']);
        $this->assertTrue($payment['coupon_applied']);
    }

    public function test_payment_breakdown_for_comp_invite_note(): void
    {
        $application = [
            'payment_total'  => 0.0,
            'payment_status' => 'waived',
            'notes'          => 'Complimentary invite #12 (free membership)',
        ];
        $payment = application_payment_breakdown($application);
        $this->assertSame('Comp invite #12 (free membership)', $payment['complimentary_label']);
        $this->assertTrue($payment['coupon_applied']);
    }

    public function test_payment_breakdown_for_member_flag_note(): void
    {
        $application = [
            'payment_total'  => 0.0,
            'payment_status' => 'waived',
            'notes'          => 'Complimentary: life member (member record)',
        ];
        $payment = application_payment_breakdown($application);
        $this->assertSame('Life member (member record)', $payment['complimentary_label']);
        $this->assertTrue($payment['coupon_applied']);
    }

    public function test_payment_breakdown_without_pdo_uses_stored_amounts(): void
    {
        $application = [
            'membership_type_slot'   => 1,
            'payment_initiation'     => 50.0,
            'payment_processing_fee' => 4.19,
            'payment_total'          => 134.19,
        ];
        $payment = application_payment_breakdown($application);
        $this->assertNull($payment['membership_dues']);
        $this->assertSame(50.0, $payment['initiation']);
        $this->assertSame(134.19, $payment['total_paid']);
    }

    public function test_resolve_membership_type_slot_from_row(): void
    {
        $this->assertSame(2, application_resolve_membership_type_slot(['membership_type_slot' => 2]));
        $this->assertNull(application_resolve_membership_type_slot(['membership_type_slot' => 0]));
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
        $this->assertStringContainsString("status IN ('pending', 'pending_payment')", $clause['where']);
        $this->assertSame([2026], $clause['params']);
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

    public function test_match_is_weak_for_renewal(): void
    {
        $this->assertTrue(application_match_is_weak_for_renewal(null));
        $this->assertTrue(application_match_is_weak_for_renewal('name_only'));
        $this->assertFalse(application_match_is_weak_for_renewal('exact'));
    }

    public function test_renewal_verification_skips_non_renewal_applications(): void
    {
        $pdo = $this->createMock(PDO::class);
        $result = application_renewal_verification($pdo, ['application_kind' => 'new']);
        $this->assertSame('verified', $result['status']);
        $this->assertNull($result['adjusted_renewal_type']);
    }

    public function test_renewal_verification_flags_renewal_without_member_match(): void
    {
        $pdo = $this->createMock(PDO::class);
        $result = application_renewal_verification($pdo, [
            'application_kind'         => 'renewal',
            'matched_member_id'        => null,
            'suggested_renewal_year'   => 2027,
        ]);
        $this->assertSame('no_match', $result['status']);
        $this->assertSame('late', $result['adjusted_renewal_type']);
    }

    public function test_can_approve_only_when_payment_complete(): void
    {
        $this->assertTrue(application_can_approve('pending'));
        $this->assertFalse(application_can_approve('pending_payment'));
        $this->assertFalse(application_can_approve('approved'));
    }

    public function test_online_payment_context_detects_stripe_and_coupon(): void
    {
        $paid = application_online_payment_context([
            'payment_status'           => 'succeeded',
            'payment_total'            => 130.0,
            'payment_gateway'          => 'Stripe',
            'payment_transaction_id'   => 'pi_test',
        ]);
        $this->assertTrue($paid['paid_online']);
        $this->assertTrue($paid['suggest_complementary']);

        $waived = application_online_payment_context([
            'payment_status' => 'waived',
            'payment_total'  => 0.0,
            'notes'          => 'Coupon code: PAULTEST',
        ]);
        $this->assertTrue($waived['waived']);
        $this->assertTrue($waived['suggest_complementary']);
    }
}
