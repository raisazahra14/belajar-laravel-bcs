<?php

namespace App\Services;

use App\Models\StockPrediction;
use App\Models\StockPredictionProcess;
use Carbon\CarbonInterface;

class StockPredictionPresenter
{
    public function safeProcessError(?string $message): string
    {
        if (! $message || preg_match('/traceback|(?:[A-Z]:\\\\|\/)(?:[^\s]+\/)*[^\s]+\.py|exception|stack trace/i', $message)) {
            return 'Analisis belum berhasil. Silakan jadwalkan ulang.';
        }

        return str($message)->limit(180)->toString();
    }

    /** @return array<string,mixed> */
    public function present(StockPrediction $prediction, ?StockPredictionProcess $process = null): array
    {
        $summary = $prediction->input_summary ?? [];
        $available = (bool) ($summary['prediction_available'] ?? $prediction->predicted_30_day_need !== null);
        $fallback = (bool) ($summary['fallback_used'] ?? false);
        $confidence = $this->confidence($summary);
        $depletion = $prediction->predicted_depletion_date;
        $restockDate = $prediction->predicted_minimum_date;

        if (! $restockDate && $depletion && $prediction->barang->lead_time_days !== null) {
            $restockDate = $depletion->copy()->subDays(max(0, (int) $prediction->barang->lead_time_days));
        }

        return [
            'available' => $available,
            'summary' => $summary,
            'method_label' => $fallback ? 'Perhitungan Cadangan' : match ($prediction->method) {
                'cold_start' => 'Estimasi Awal',
                'simple_average', 'fallback_average', 'average_fallback' => 'Rata-rata Historis',
                'machine_learning' => 'Machine Learning',
                default => 'Metode Analisis',
            },
            'technical_method' => $prediction->method ?: 'tidak tersedia',
            'confidence' => $confidence,
            'confidence_label' => $confidence === null ? 'Belum tersedia' : $confidence.'%',
            'risk_variant' => match ($prediction->status) {
                StockPrediction::STATUS_SAFE => 'success',
                StockPrediction::STATUS_WARNING, StockPrediction::STATUS_RESTOCK => 'warning',
                StockPrediction::STATUS_URGENT => 'danger',
                default => 'secondary',
            },
            'process_label' => match ($process?->status) {
                StockPredictionProcess::STATUS_WAITING => 'Menunggu',
                StockPredictionProcess::STATUS_PROCESSING => 'Diproses',
                StockPredictionProcess::STATUS_COMPLETED => 'Selesai',
                StockPredictionProcess::STATUS_FAILED => 'Gagal',
                default => null,
            },
            'process_variant' => match ($process?->status) {
                StockPredictionProcess::STATUS_COMPLETED => 'success',
                StockPredictionProcess::STATUS_FAILED => 'danger',
                default => 'info',
            },
            'restock_date' => $restockDate,
            'depletion_date' => $depletion,
            'depletion_is_past' => $depletion?->isBefore(today(config('app.display_timezone'))) ?? false,
            'missing_inputs' => collect($summary['missing_inputs'] ?? [])->map(fn ($input) => match ($input) {
                'daily_usage_estimate', 'estimasi pemakaian harian' => 'Estimasi pemakaian harian',
                'lead_time_days', 'lead time' => 'Lead time pemasok',
                default => ucfirst(str_replace('_', ' ', (string) $input)),
            })->values()->all(),
            'analyzed_at' => $this->displayDateTime($prediction->analyzed_at),
        ];
    }

    /** @param array<string,mixed> $summary */
    private function confidence(array $summary): ?int
    {
        if (! array_key_exists('confidence', $summary) || ! is_numeric($summary['confidence'])) {
            return null;
        }

        return (int) round(min(100, max(0, (float) $summary['confidence'] * 100)));
    }

    private function displayDateTime(?CarbonInterface $date): string
    {
        return $date?->copy()->timezone(config('app.display_timezone'))->format('d/m/Y H:i') ?? 'Belum tersedia';
    }
}
