<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemGuideController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SystemGuide/Index');
    }

    public function pdf(): StreamedResponse
    {
        $pdf = Pdf::loadView('reports.system-guide-pdf')
            ->setPaper('letter', 'portrait')
            ->setOption('isPhpEnabled', true);

        $directory = storage_path('app/guias');
        File::ensureDirectoryExists($directory);
        $path = $directory . '/guia-del-sistema.pdf';
        $pdf->save($path);

        return response()->streamDownload(function () use ($path) {
            echo File::get($path);
        }, 'Guia-del-sistema.pdf', ['Content-Type' => 'application/pdf']);
    }
}
