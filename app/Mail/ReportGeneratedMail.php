<?php

namespace App\Mail;

use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportGeneratedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Period $period,
        public ?User $user,
        public PeriodRadiographyRun $run,
        public string $downloadUrl = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reporte listo para descargar — ' . $this->period->label,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-generated',
        );
    }
}
