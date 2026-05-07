<?php

namespace App\Mail;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReprocessUploadCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Period $period,
        public ?User $user,
        public ReportUpload $upload,
        public array $stats = [],
    ) {}

    public function envelope(): Envelope
    {
        $sourceName = $this->upload->dataSource?->name ?? 'Archivo';
        return new Envelope(
            subject: "Reprocesamiento completado — {$sourceName} — {$this->period->label}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reprocess-upload-completed',
        );
    }
}
