<?php

namespace Tests\Unit;

use App\Support\FinancialReport;
use App\Support\FinancialSale;
use PHPUnit\Framework\TestCase;

class FinancialReportTest extends TestCase
{
    private function sale(array $fields=[]): array
    {
        return array_replace(['id'=>1,'estado_sufe'=>'PROCESADA','estado_pago'=>'pagado','metodoPago'=>5,
            'total'=>'25.00','codigoOrden'=>'VFC-1','sucursal'=>['codigoSucursal'=>2,'puntoVenta'=>0],
            'detalle'=>[['descripcion'=>'Paquetería','cantidad'=>1,'precio'=>25,'total_linea'=>'25.00']]],$fields);
    }

    public function test_annulled_attempts_do_not_annul_replacements_or_repeat_paid_qr(): void
    {
        $rows=[];
        foreach ([[1701,1702,175],[1811,1812,50],[1892,1893,50],[1920,1921,25],[1972,1973,75]] as [$old,$new,$total]) {
            foreach ([$old=>'ANULADA',$new=>'PROCESADA'] as $number=>$state) {
                $rows[]=$this->sale(['numeroFactura'=>$number,'total'=>$total,'estado_sufe'=>$state,
                    'qr_transaction_id'=>(string)$new,'estado_emision'=>'ANULADA','respuesta_emision'=>['estadoSufe'=>'ANULADA']]);
            }
        }
        $rows[]=$this->sale(['numeroFactura'=>1922,'estado_sufe'=>'ANULADA','qr_transaction_id'=>'1921']);
        $report=FinancialReport::build($rows);
        $this->assertEquals(375,$report['resumen']['totalVendido']);
        $this->assertEquals(400,$report['resumen']['totalFacturasAnuladas']);
        $this->assertEquals(375,$report['pagosQr']['importeVinculadoSinDuplicar']);
        $this->assertSame(5,$report['resumen']['facturadas']);
        $this->assertSame(6,$report['resumen']['facturasAnuladas']);
    }

    public function test_canceled_pending_and_unknown_qr_never_count_even_with_a_cuf(): void
    {
        foreach (['cancelado','fallido','reembolsado','pendiente',''] as $state) {
            $f=FinancialSale::classify($this->sale(['estado_pago'=>$state,'cuf'=>'EXISTS']));
            $this->assertFalse($f['incluidaEnTotalVendido'], $state);
            $this->assertFalse($f['anulada']);
        }
    }

    public function test_requested_annulment_is_still_active_and_final_annulment_excluded(): void
    {
        foreach (['ANULACION_SOLICITADA','ANULACION_OBSERVADA'] as $state) {
            $this->assertTrue(FinancialSale::classify($this->sale(['estado_sufe'=>$state]))['incluidaEnTotalVendido']);
        }
        $this->assertFalse(FinancialSale::classify($this->sale(['estado_sufe'=>'OBSERVADA','cuf'=>'EXISTS']))['incluidaEnTotalVendido']);
        $this->assertFalse(FinancialSale::classify($this->sale(['estado_sufe'=>'ANULADA']))['incluidaEnTotalVendido']);
    }

    public function test_cash_qr_and_excluded_categories_reconcile_to_the_cent(): void
    {
        $rows=[
            $this->sale(['total'=>'0.10']),$this->sale(['total'=>'0.20','metodoPago'=>1]),
            $this->sale(['total'=>'5.00','canal_operativo'=>'contrato']),
            $this->sale(['total'=>'10.00','detalle'=>[['descripcion'=>'Servicio ECA']]]),
            $this->sale(['total'=>'20.00','estado_sufe'=>'REGISTRADA_OFICIAL']),
        ];
        $report=FinancialReport::build($rows);
        $this->assertSame(0.3,$report['resumen']['totalVendido']);
        $this->assertEquals(0,$report['resumen']['diferenciaMediosPago']);
        $this->assertEquals(35,$report['resumen']['totalNoIncluido']);
        $this->assertSame(10,FinancialSale::cents('0.10'));
        $this->assertSame(-101,FinancialSale::cents('-1.005'));
        $this->assertEquals(0,FinancialReport::totals([$this->sale(['estado_sufe'=>'ANULADA','detalle'=>[['descripcion'=>'Servicio ECA']]])])['totalEcaFacturado']);
    }

    public function test_header_detail_difference_is_exposed_without_changing_lines(): void
    {
        $sale=$this->sale(['total'=>20]);
        $details=FinancialSale::reconciledDetails($sale);
        $this->assertEquals(25,$details[0]['total_linea']);
        $this->assertEquals(-5,$details[1]['total_linea']);
        $this->assertSame('conciliacion',$details[1]['tipoLinea']);
        $this->assertContains('DIFERENCIA_CABECERA_DETALLE',FinancialReport::build([$sale])['incidencias'][0]['motivos']);
    }
}
