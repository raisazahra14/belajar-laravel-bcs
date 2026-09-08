@props([
    'field', 'label', 'name', 'value' => '', 'state' => [], 'type' => 'text',
    'textarea' => false, 'step' => null, 'min' => null, 'placeholder' => null,
])
@php
    $status = $state['status'] ?? 'absent';
    $statusUi = [
        'automatic' => ['badge-success', 'ti-check', 'Terbaca otomatis'],
        'review' => ['badge-warning', 'ti-alert', 'Perlu diperiksa'],
        'corrected' => ['badge-info', 'ti-pencil-alt', 'Dikoreksi pengguna'],
        'absent' => ['badge-secondary', 'ti-help-alt', 'Tidak tercantum / Input manual'],
    ][$status] ?? ['badge-secondary', 'ti-help-alt', 'Input manual'];
    $inputId = 'ocr_'.preg_replace('/[^a-z0-9_-]+/i', '_', $field);
    $helpId = $inputId.'_help';
@endphp
<div {{ $attributes->class(['ocr-field']) }}>
    <div class="ocr-field-heading">
        <label class="form-label" for="{{ $inputId }}">{{ $label }}</label>
        <span class="badge {{ $statusUi[0] }}"><i class="{{ $statusUi[1] }}" aria-hidden="true"></i>{{ $state['label'] ?? $statusUi[2] }}</span>
    </div>
    @if($textarea)
        <textarea class="form-control" id="{{ $inputId }}" name="{{ $name }}" rows="3" aria-describedby="{{ $helpId }}">{{ $value }}</textarea>
    @else
        <input class="form-control" id="{{ $inputId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" aria-describedby="{{ $helpId }}" @if($step !== null) step="{{ $step }}" @endif @if($min !== null) min="{{ $min }}" @endif @if($placeholder) placeholder="{{ $placeholder }}" @endif>
    @endif
    <p class="ocr-field-message" id="{{ $helpId }}">{{ $state['message'] ?? $state['help'] ?? 'Nilai belum ditemukan. Silakan isi secara manual.' }}</p>
    @if(array_key_exists('confidence', $state) || ! empty($state['source_text']))
        <details class="ocr-source-details">
            <summary>Lihat sumber OCR</summary>
            <p>Confidence {{ $state['confidence'] ?? 'tidak tersedia' }}</p>
            <dl>
                <dt>Teks sumber</dt><dd>{{ $state['source_text'] ?: 'Tidak tersedia' }}</dd>
            </dl>
        </details>
    @endif
</div>
