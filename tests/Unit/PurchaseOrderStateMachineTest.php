<?php

namespace Tests\Unit;

use App\Enums\PurchaseOrderStatus as S;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Services\PurchaseOrderStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PurchaseOrderStateMachineTest extends TestCase
{
    private PurchaseOrderStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new PurchaseOrderStateMachine;
    }

    public function test_every_transition_in_map_has_a_rule(): void
    {
        foreach (S::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                $this->assertNotNull(
                    $this->machine->actionName($from, $to),
                    "Aturan untuk {$from->value}->{$to->value} belum didefinisikan."
                );
            }
        }
    }

    public function test_terminal_statuses_have_no_transitions(): void
    {
        $this->assertTrue(S::COMPLETED->isTerminal());
        $this->assertTrue(S::CANCELLED->isTerminal());
        $this->assertFalse(S::REJECTED->isTerminal());
    }

    public function test_only_draft_is_editable_and_only_approved_or_partial_can_receive(): void
    {
        foreach (S::cases() as $status) {
            $this->assertSame($status === S::DRAFT, $status->isEditable());
            $this->assertSame(
                in_array($status, [S::APPROVED, S::PARTIALLY_RECEIVED], true),
                $status->canReceiveGoods()
            );
        }
    }

    /**
     * @return array<string, array{S, S}>
     */
    public static function illegalTransitions(): array
    {
        return [
            'draft langsung approved' => [S::DRAFT, S::APPROVED],
            'draft langsung diterima' => [S::DRAFT, S::COMPLETED],
            'rejected langsung approved' => [S::REJECTED, S::APPROVED],
            'partial dibatalkan' => [S::PARTIALLY_RECEIVED, S::CANCELLED],
            'completed dibuka lagi' => [S::COMPLETED, S::DRAFT],
            'cancelled dibuka lagi' => [S::CANCELLED, S::DRAFT],
        ];
    }

    #[DataProvider('illegalTransitions')]
    public function test_illegal_transitions_are_rejected(S $from, S $to): void
    {
        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->machine->transition($from, $to, 'admin', true, 'alasan', 1);
    }

    public function test_staff_creator_can_submit_draft_with_items(): void
    {
        $this->assertSame(S::PENDING_APPROVAL, $this->machine->transition(S::DRAFT, S::PENDING_APPROVAL, 'staff', true, null, 2));
    }

    public function test_submit_without_items_is_rejected(): void
    {
        $this->expectExceptionMessage('minimal harus memiliki satu item');
        $this->machine->transition(S::DRAFT, S::PENDING_APPROVAL, 'staff', true, null, 0);
    }

    public function test_staff_cannot_submit_someone_elses_draft(): void
    {
        try {
            $this->machine->transition(S::DRAFT, S::PENDING_APPROVAL, 'staff', false, null, 1);
            $this->fail('Seharusnya ditolak.');
        } catch (InvalidPurchaseOrderTransitionException $e) {
            $this->assertSame(InvalidPurchaseOrderTransitionException::UNAUTHORIZED, $e->errorCode);
            $this->assertSame(403, $e->httpStatus());
        }
    }

    public function test_only_manager_can_approve(): void
    {
        $this->assertSame(S::APPROVED, $this->machine->transition(S::PENDING_APPROVAL, S::APPROVED, 'manager'));
        $this->assertFalse($this->machine->can(S::PENDING_APPROVAL, S::APPROVED, 'admin'));
        $this->assertFalse($this->machine->can(S::PENDING_APPROVAL, S::APPROVED, 'staff'));
    }

    public function test_manager_cannot_approve_own_po(): void
    {
        $this->expectExceptionMessage('tidak boleh menyetujui atau menolak PO miliknya sendiri');
        $this->machine->transition(S::PENDING_APPROVAL, S::APPROVED, 'manager', true);
    }

    public function test_reject_and_cancel_require_reason(): void
    {
        foreach ([[S::PENDING_APPROVAL, S::REJECTED], [S::APPROVED, S::CANCELLED]] as [$from, $to]) {
            try {
                $this->machine->transition($from, $to, 'manager', false, '   ');
                $this->fail('Seharusnya alasan wajib.');
            } catch (InvalidPurchaseOrderTransitionException $e) {
                $this->assertSame(InvalidPurchaseOrderTransitionException::REASON_REQUIRED, $e->errorCode);
                $this->assertSame(422, $e->httpStatus());
            }
        }

        $this->assertSame(S::REJECTED, $this->machine->transition(S::PENDING_APPROVAL, S::REJECTED, 'manager', false, 'Harga terlalu tinggi'));
    }

    public function test_rejected_po_can_be_revised_by_creator(): void
    {
        $this->assertSame(S::DRAFT, $this->machine->transition(S::REJECTED, S::DRAFT, 'staff', true));
    }

    public function test_available_transitions_per_role(): void
    {
        $this->assertSame(
            [S::APPROVED, S::REJECTED, S::CANCELLED],
            $this->machine->availableTransitions(S::PENDING_APPROVAL, 'manager')
        );
        $this->assertSame(
            [S::DRAFT],
            $this->machine->availableTransitions(S::PENDING_APPROVAL, 'staff', true)
        );
        $this->assertSame([], $this->machine->availableTransitions(S::PENDING_APPROVAL, 'staff'));
    }

    public function test_receiving_status_is_resolved_from_cumulative_quantities(): void
    {
        $this->assertSame(S::PARTIALLY_RECEIVED, $this->machine->resolveReceivingStatus(S::APPROVED, [
            ['ordered' => 10, 'received' => 4],
            ['ordered' => 5, 'received' => 0],
        ]));

        $this->assertSame(S::COMPLETED, $this->machine->resolveReceivingStatus(S::PARTIALLY_RECEIVED, [
            ['ordered' => 10, 'received' => 10],
            ['ordered' => 5, 'received' => 5],
        ]));
    }

    public function test_over_receipt_is_rejected(): void
    {
        $this->expectExceptionMessage('over-receipt');
        $this->machine->resolveReceivingStatus(S::APPROVED, [['ordered' => 10, 'received' => 11]]);
    }

    public function test_receipt_on_non_approved_po_is_rejected(): void
    {
        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->machine->resolveReceivingStatus(S::PENDING_APPROVAL, [['ordered' => 10, 'received' => 5]]);
    }
}
