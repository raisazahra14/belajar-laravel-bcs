<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PurchaseOrder $resource
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $stateMachine = app(PurchaseOrderStateMachine::class);

        $availableTransitions = $user
            ? array_map(
                static fn ($case) => $case->value,
                $stateMachine->availableTransitions(
                    $this->resource->status,
                    $user->role,
                    $this->resource->created_by === $user->id
                )
            )
            : [];

        return [
            'id' => $this->resource->id,
            'nomor_po' => $this->resource->nomor_po,
            'status' => [
                'value' => $this->resource->status->value,
                'label' => $this->resource->status->label(),
                'badge_variant' => $this->resource->status->badgeVariant(),
                'description' => $this->resource->status->description(),
                'is_terminal' => $this->resource->status->isTerminal(),
                'is_editable' => $this->resource->status->isEditable(),
                'can_receive_goods' => $this->resource->status->canReceiveGoods(),
            ],
            'tanggal_pesan' => $this->resource->tanggal_pesan?->format('Y-m-d'),
            'estimasi_tiba' => $this->resource->estimasi_tiba?->format('Y-m-d'),
            'total_biaya' => (float) $this->resource->total_biaya,
            'catatan' => $this->resource->catatan,
            'supplier' => $this->whenLoaded('supplier', function () {
                return [
                    'id' => $this->resource->supplier?->id,
                    'kode_supplier' => $this->resource->supplier?->kode_supplier,
                    'nama_supplier' => $this->resource->supplier?->nama_supplier,
                    'contact_person' => $this->resource->supplier?->contact_person,
                    'telepon' => $this->resource->supplier?->telepon,
                    'email' => $this->resource->supplier?->email,
                    'alamat' => $this->resource->supplier?->alamat,
                ];
            }),
            'warehouse' => $this->whenLoaded('warehouse', function () {
                return [
                    'id' => $this->resource->warehouse?->id,
                    'kode_gudang' => $this->resource->warehouse?->kode_gudang,
                    'nama_gudang' => $this->resource->warehouse?->nama_gudang,
                    'lokasi' => $this->resource->warehouse?->lokasi,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->resource->creator?->id,
                    'name' => $this->resource->creator?->name,
                    'email' => $this->resource->creator?->email,
                    'role' => $this->resource->creator?->role,
                ];
            }),
            'approver' => $this->whenLoaded('approver', function () {
                return [
                    'id' => $this->resource->approver?->id,
                    'name' => $this->resource->approver?->name,
                    'email' => $this->resource->approver?->email,
                    'role' => $this->resource->approver?->role,
                ];
            }),
            'items_count' => $this->whenCounted('items', $this->resource->items_count),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'available_transitions' => $availableTransitions,
            'pdf_url' => route('purchase-orders.pdf', $this->resource),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
