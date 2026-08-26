<?php

namespace App\Mail;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
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

    /**
     * Correo por SCOPE (Problema 3): antes el asunto/cuerpo siempre decía "Reporte
     * listo para descargar" armado desde $period genérico (tipo de periodo, ej.
     * "Mes operativo") — nunca reflejaba QUÉ radiografía se generó realmente.
     * Ahora la fuente de verdad es el MISMO PeriodRadiographyRun que terminó
     * ($this->run->report_type/scope/branch_id/employee_id/comparison_period_id —
     * ver PeriodRadiographyRun::identity()), nunca solo el periodo.
     */
    public function envelope(): Envelope
    {
        $ctx = $this->buildContext();

        return new Envelope(subject: $ctx['subject']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-generated',
            with: $this->buildContext(),
        );
    }

    private function buildContext(): array
    {
        $run        = $this->run;
        $period     = $this->period;
        $reportType = $run->report_type ?: 'simple';
        $scope      = $run->scope ?: 'general';
        $isComparative = $reportType !== 'simple';

        $reportTypeLabel = match ($reportType) {
            'simple'                 => 'Radiografía simple',
            'month_vs_month'         => 'Comparativo mes vs mes',
            'bimester_vs_bimester'   => 'Comparativo bimestre vs bimestre',
            'quarter_vs_quarter'     => 'Comparativo trimestre vs trimestre',
            default                  => 'Radiografía simple',
        };

        $scopeLabel = match ($scope) {
            'branch'   => 'Sucursal',
            'employee' => 'Empleado / Gestor',
            default    => 'General — todas las sucursales',
        };

        $branch   = $run->branch_id ? Branch::query()->find($run->branch_id) : null;
        $employee = $run->employee_id ? Employee::query()->find($run->employee_id) : null;

        // Sucursal histórica del empleado en ESE periodo (el run de scope=employee no
        // guarda branch_id — ver GenerateRadiographyJob::handle()/identity()) —
        // resuelta vía la misma tabla que usa EmployeeBranchAutoMatchService.
        $employeeBranchName = null;
        if ($employee) {
            $historicBranchId = EmployeeBranchAssignment::query()
                ->where('period_id', $run->period_id)
                ->where('employee_id', $employee->id)
                ->value('branch_id');
            $employeeBranchName = $historicBranchId ? Branch::query()->find($historicBranchId)?->name : null;
        }

        $comparisonPeriod = $run->comparison_period_id ? Period::query()->find($run->comparison_period_id) : null;

        // ── Asunto por scope/tipo (Problema 3) ──────────────────────────────
        if ($isComparative) {
            $subject = 'Comparativo listo — ' . $period->label . ' vs ' . ($comparisonPeriod->label ?? '—');
        } elseif ($scope === 'branch') {
            $subject = 'Radiografía de sucursal lista — ' . $period->label . ' — ' . ($branch->name ?? 'sucursal');
        } elseif ($scope === 'employee') {
            $subject = 'Radiografía de gestor lista — ' . $period->label . ' — ' . ($employee->full_name ?? 'gestor');
        } else {
            $subject = 'Radiografía general lista — ' . $period->label;
        }

        return [
            'period'              => $period,
            'user'                => $this->user,
            'run'                 => $run,
            'downloadUrl'         => $this->downloadUrl,
            'subject'             => $subject,
            'reportType'          => $reportType,
            'reportTypeLabel'     => $reportTypeLabel,
            'scope'               => $scope,
            'scopeLabel'          => $scopeLabel,
            'isComparative'       => $isComparative,
            'branch'              => $branch,
            'employee'            => $employee,
            'employeeBranchName'  => $employeeBranchName,
            'comparisonPeriod'    => $comparisonPeriod,
        ];
    }
}
