<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyDocumentRequest;
use App\Models\DocumentVerification;
use App\Services\DocumentVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class DocumentVerificationController extends Controller
{
    public function index(): View
    {
        $query = DocumentVerification::query()->with('user')->latest();
        if (auth()->user()->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return view('verifications.index', ['verifications' => $query->paginate(10)]);
    }

    public function store(VerifyDocumentRequest $request, DocumentVerificationService $service): RedirectResponse
    {
        $file = $request->file('document');
        $path = $file->store('document-verifications/'.auth()->id(), 'local');

        try {
            $result = $service->verify(
                Storage::disk('local')->path($path),
                $request->string('document_type')->toString(),
            );

            $verification = DB::transaction(fn () => DocumentVerification::create([
                'user_id' => auth()->id(),
                'document_type' => $request->string('document_type')->toString(),
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'status' => $result['status'],
                'score' => $result['score'],
                'message' => $result['message'],
                'analysis_details' => $result['details'],
            ]));
        } catch (RuntimeException $exception) {
            $verification = DocumentVerification::create([
                'user_id' => auth()->id(),
                'document_type' => $request->string('document_type')->toString(),
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'status' => 'failed',
                'score' => 0,
                'message' => 'Verifikasi dokumen gagal.',
                'analysis_details' => [],
                'error_message' => $exception->getMessage(),
            ]);

            return redirect()->route('verifications.show', $verification)
                ->withErrors(['document' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return redirect()->route('verifications.show', $verification)
            ->with('success', 'Dokumen berhasil diverifikasi.');
    }

    public function show(DocumentVerification $documentVerification): View
    {
        abort_unless(
            auth()->user()->role === 'admin' || $documentVerification->user_id === auth()->id(),
            403,
        );

        return view('verifications.show', ['verification' => $documentVerification]);
    }
}
