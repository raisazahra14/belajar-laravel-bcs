<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionProcess;
use App\Services\StockPredictionPresenter;
use App\Services\StockPredictionScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockPredictionController extends Controller
{
    public function index(Request $request, StockPredictionPresenter $presenter)
    {
        $ids = StockPrediction::query()->selectRaw('MAX(id)')->groupBy('barang_id');
        $predictions = StockPrediction::with('barang')->whereIn('id', $ids)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->latest('analyzed_at')->paginate(15)->withQueryString();
        $activeProcesses = $request->user()->can('run-stock-prediction')
            ? $this->activeProcesses()->get()
            : collect();
        $activeProcesses = $this->sortActiveProcesses($activeProcesses);
        $activeProcesses->each(fn (StockPredictionProcess $process) => $process->setAttribute(
            'safe_error_message',
            $presenter->safeProcessError($process->error_message),
        ));
        $activeProcessCounts = $activeProcesses->countBy('status');
        $visibleActiveProcesses = $activeProcesses->take(5);
        $processes = $request->user()->can('run-stock-prediction')
            ? StockPredictionProcess::query()->whereIn('barang_id', $predictions->getCollection()->pluck('barang_id'))->get()->keyBy('barang_id')
            : collect();
        $presentations = $predictions->getCollection()->mapWithKeys(fn (StockPrediction $prediction): array => [
            $prediction->id => $presenter->present($prediction, $processes->get($prediction->barang_id)),
        ]);

        return view('stock-predictions.index', compact(
            'predictions',
            'processes',
            'activeProcesses',
            'activeProcessCounts',
            'visibleActiveProcesses',
            'presentations',
        ));
    }

    public function processes(Request $request, StockPredictionPresenter $presenter): JsonResponse
    {
        $this->authorize('run-stock-prediction');

        $processes = $this->sortActiveProcesses($this->activeProcesses()->get());

        return response()->json([
            'counts' => [
                StockPredictionProcess::STATUS_WAITING => $processes->where('status', StockPredictionProcess::STATUS_WAITING)->count(),
                StockPredictionProcess::STATUS_PROCESSING => $processes->where('status', StockPredictionProcess::STATUS_PROCESSING)->count(),
                StockPredictionProcess::STATUS_FAILED => $processes->where('status', StockPredictionProcess::STATUS_FAILED)->count(),
            ],
            'processes' => $processes->map(fn (StockPredictionProcess $process): array => [
                'id' => $process->id,
                'barang_id' => $process->barang_id,
                'barang_name' => $process->barang->nama_barang,
                'barang_code' => $process->barang->kode_barang,
                'status' => $process->status,
                'status_label' => match ($process->status) {
                    StockPredictionProcess::STATUS_WAITING => 'Menunggu',
                    StockPredictionProcess::STATUS_PROCESSING => 'Diproses',
                    default => 'Gagal',
                },
                'message' => $process->status === StockPredictionProcess::STATUS_FAILED
                    ? $presenter->safeProcessError($process->error_message)
                    : null,
                'updated_label' => $process->updated_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i').' WIB',
                'updated_at' => $process->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function analyze(Barang $barang, StockPredictionScheduler $scheduler)
    {
        $this->authorize('run-stock-prediction');
        $status = $scheduler->schedule($barang, request()->user(), true);

        return back()->with($status === 'skipped' ? 'info' : 'success', "Prediksi {$barang->nama_barang} dijadwalkan.");
    }

    public function analyzeAll(StockPredictionScheduler $scheduler)
    {
        $this->authorize('run-stock-prediction');
        $result = $scheduler->scheduleAll(request()->user());
        $scheduled = $result['scheduled'];
        $skipped = $result['skipped'];
        $failed = $result['failed'];

        return back()->with($failed ? 'warning' : 'success', "Antrean prediksi: {$scheduled} dijadwalkan, {$skipped} dilewati, {$failed} gagal dijadwalkan.");
    }

    public function approve(StockPrediction $stockPrediction)
    {
        $this->authorize('approve-restock');
        abort_unless($stockPrediction->recommended_restock > 0, 422, 'Prediksi ini tidak memiliki rekomendasi restock.');

        return redirect()->route('barang.stok', ['id' => $stockPrediction->barang_id, 'jenis' => 'masuk', 'jumlah' => $stockPrediction->recommended_restock, 'prediction_id' => $stockPrediction->id]);
    }

    private function activeProcesses()
    {
        return StockPredictionProcess::query()
            ->with('barang:id,kode_barang,nama_barang')
            ->whereIn('status', [
                StockPredictionProcess::STATUS_WAITING,
                StockPredictionProcess::STATUS_PROCESSING,
                StockPredictionProcess::STATUS_FAILED,
            ])
            ->latest('updated_at')
            ->orderByDesc('id');
    }

    private function sortActiveProcesses($processes)
    {
        $priority = [
            StockPredictionProcess::STATUS_FAILED => 0,
            StockPredictionProcess::STATUS_PROCESSING => 1,
            StockPredictionProcess::STATUS_WAITING => 2,
        ];

        return $processes->sort(function (StockPredictionProcess $left, StockPredictionProcess $right) use ($priority): int {
            $statusOrder = ($priority[$left->status] ?? 3) <=> ($priority[$right->status] ?? 3);

            return $statusOrder !== 0
                ? $statusOrder
                : [$right->updated_at?->timestamp, $right->id] <=> [$left->updated_at?->timestamp, $left->id];
        })->values();
    }
}
