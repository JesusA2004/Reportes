<?php

namespace App\Mail;

use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DatabaseUpdateCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Period $period,
        public ?User $user,
        public PeriodDatabaseUpdateRun $run,
        public array $stats = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Base de datos actualizada — ' . $this->period->label,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.database-update-completed',
        );
    }
}
