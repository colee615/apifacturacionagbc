<?php

namespace App\Http\Controllers\Concerns;

use App\Support\FinancialReport;
use App\Support\FinancialSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait FinancialReports
{
    public function financialReportRows(array $filters): \Generator
    {
        $ownSnapshot = DB::transactionLevel() === 0 && DB::connection()->getDriverName() === 'pgsql';
        if ($ownSnapshot) {
            DB::beginTransaction();
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        try {
            foreach ($this->mergedServiceReportVentaStream($filters, true) as $row) {
                $row['financiero'] = FinancialSale::classify($row);
                yield $row;
            }
        } finally {
            if ($ownSnapshot) DB::rollBack();
        }
    }

    public function financialReportSnapshot(array $filters, int $issueLimit = 500): array
    {
        return FinancialReport::build($this->financialReportRows($filters), $issueLimit);
    }

    public function auditoriaFinanciera(Request $request)
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $report = $this->financialReportSnapshot($filters, (int) ($filters['limite'] ?? 500));
        return response()->json(['filters'=>$filters,'generadoEn'=>now()->toIso8601String(),
            'alcance'=>'Estado actual de las operaciones registradas en el período. Los cierres históricos y los extractos bancarios se contrastan por separado.'] + $report);
    }
}
