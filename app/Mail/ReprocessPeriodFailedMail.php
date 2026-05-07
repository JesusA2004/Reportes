<?php

namespace App\Mail;

use App\Models\Period;
use App\Models\PeriodReprocessRun;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReprocessPeriodFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Period $period,
        public ?User $user,
        public PeriodReprocessRun $run,
        public array $results = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Error en reprocesamiento — {$this->period->label}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reprocess-period-failed',
        );
    }
}
