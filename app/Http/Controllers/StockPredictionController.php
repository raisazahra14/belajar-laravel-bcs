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
    private const ACTIVE_PROCESS_LIMIT = 50;

    public function index(Request $request, StockPredictionPresenter $presenter)
    {
        $focusPredictionId = $request->integer('focus');
        $ids = StockPrediction::query()->selectRaw('MAX(id)')->groupBy('barang_id');
        $predictions = StockPrediction::with('barang')
            ->when($focusPredictionId > 0, fn ($query) => $query->whereKey($focusPredictionId))
            ->when($focusPredictionId === 0, fn ($query) => $query->whereIn('id', $ids))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('analyzed_at')->latest('id')->paginate(15)->withQueryString();
        $canRunPrediction = $request->user()->can('run-stock-prediction');
        $activeProcessCounts = $canRunPrediction
            ? $this->activeProcessCounts()
            : collect();
        $activeProcesses = $canRunPrediction
            ? $this->activeProcesses()->limit(self::ACTIVE_PROCESS_LIMIT)->get()
            : collect();
        $activeProcesses = $this->sortActiveProcesses($activeProcesses);
        $activeProcesses->each(fn (StockPredictionProcess $process) => $process->setAttribute(
            'safe_error_message',
            $presenter->safeProcessError($process->error_message),
        ));
        $visibleActiveProcesses = $activeProcesses->take(5);
        $processes = $canRunPrediction
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

        $counts = $this->activeProcessCounts();
        $processes = $this->sortActiveProcesses($this->activeProcesses()->limit(self::ACTIVE_PROCESS_LIMIT)->get());

        return response()->json([
            'counts' => [
                StockPredictionProcess::STATUS_WAITING => (int) ($counts[StockPredictionProcess::STATUS_WAITING] ?? 0),
                StockPredictionProcess::STATUS_PROCESSING => (int) ($counts[StockPredictionProcess::STATUS_PROCESSING] ?? 0),
                StockPredictionProcess::STATUS_FAILED => (int) ($counts[StockPredictionProcess::STATUS_FAILED] ?? 0),
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

    /**
     * Mengarahkan rekomendasi restock prediksi ke formulir pembuatan Purchase Order (Smart Restock).
     */
    public function createPo(StockPrediction $stockPrediction)
    {
        $this->authorize('approve-restock');
        abort_unless($stockPrediction->recommended_restock > 0, 422, 'Prediksi ini tidak memiliki rekomendasi restock.');

        return redirect()->route('purchase-orders.create', ['from_prediction' => $stockPrediction->id]);
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

    private function activeProcessCounts()
    {
        return StockPredictionProcess::query()
            ->whereHas('barang')
            ->whereIn('status', [
                StockPredictionProcess::STATUS_WAITING,
                StockPredictionProcess::STATUS_PROCESSING,
                StockPredictionProcess::STATUS_FAILED,
            ])
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
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
