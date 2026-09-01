@php
    $fields = [
        'document_number' => ['Nomor Surat Jalan', 'text'],
        'document_date' => ['Tanggal Surat Jalan', 'date'],
        'purchase_order_number' => ['Nomor PO/DO', 'text'],
        'vehicle_number' => ['Nomor Kendaraan', 'text'],
        'sender' => ['Pengirim', 'text'],
        'recipient' => ['Penerima', 'text'],
        'total_items' => ['Jumlah/berat', 'number'],
    ];
    $badgeClasses = [
        'automatic' => 'badge-success',
        'review' => 'badge-warning',
        'absent' => 'badge-secondary',
        'corrected' => 'badge-info',
    ];
@endphp
<div class="row">
    @foreach($fields as $field => [$label, $type])
        @php($state = $metadataFieldStates[$field] ?? ['status' => 'absent', 'label' => 'Tidak tercantum', 'help' => 'Nilai tidak ditemukan.'])
        <div class="col-md-6 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label mb-0" for="{{ $field }}">{{ $label }}</label>
                <span class="badge {{ $badgeClasses[$state['status']] ?? 'badge-secondary' }}">{{ $state['label'] }}</span>
            </div>
            <input class="form-control" id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" @if($type === 'number') min="0" @endif value="{{ old($field, $mappedMetadata[$field] ?? '') }}">
            <small class="form-text text-muted">{{ $state['help'] }}</small>
        </div>
    @endforeach
</div>
