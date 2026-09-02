<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionProcess;
use App\Services\StockPredictionScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockPredictionController extends Controller
{
    public function index(Request $request)
    {
        $ids = StockPrediction::query()->selectRaw('MAX(id)')->groupBy('barang_id');
        $predictions = StockPrediction::with('barang')->whereIn('id', $ids)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->latest('analyzed_at')->paginate(15)->withQueryString();
        $activeProcesses = $request->user()->can('run-stock-prediction')
            ? $this->activeProcesses()->get()
            : collect();
        $processes = $activeProcesses->keyBy('barang_id');

        return view('stock-predictions.index', compact('predictions', 'processes', 'activeProcesses'));
    }

    public function processes(Request $request): JsonResponse
    {
        $this->authorize('run-stock-prediction');

        return response()->json([
            'processes' => $this->activeProcesses()->get()->map(fn (StockPredictionProcess $process): array => [
                'id' => $process->id,
                'barang_id' => $process->barang_id,
                'status' => $process->status,
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
}
