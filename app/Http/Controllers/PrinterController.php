<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PrinterController extends Controller
{
    public function test(Request $request)
    {
        try {
            // Nombre de la impresora, asegúrate de que coincida exactamente con el nombre en tu sistema
            $printerName = "EPSON L3250 Series";
    
            // Conector de impresión para Windows
            $connector = new WindowsPrintConnector($printerName);
    
            // Crear una instancia del objeto Printer
            $printer = new Printer($connector);
    
            // Configuración inicial
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setFont(Printer::FONT_A);
            $printer->setDoubleStrike(true);
    
            // Encabezado de la venta ficticia
            $printer->text("Cliente: Juan Pérez \n");
            $printer->text("Documento: CI 1234567 LP \n");
            $printer->text("Dirección: Av. América #123 \n");
            $printer->text("Teléfono: 71234567 \n");
            $printer->feed();
    
            // Información de la venta
            $printer->text("TICKET DE VENTA\n");
            $printer->text("Número de Venta: 001234 \n");
            $printer->feed();
    
            // Detalles de los productos ficticios vendidos
            $printer->setEmphasis(true);
            $printer->text($this->lines(47));
            $printer->setEmphasis(false);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
    
            $printer->setDoubleStrike(true);
            $printer->text("Detalle de Productos:\n");
            $printer->setDoubleStrike(false);
            $printer->text($this->lines(47));
    
            // Detalle de producto ficticio 1
            $printer->text(" 2x Producto A (Grande)           \$25.00   \$50.00 \n");
    
            // Detalle de producto ficticio 2
            $printer->text(" 1x Producto B (Mediano)         \$15.00   \$15.00 \n");
    
            // Detalle de producto ficticio 3
            $printer->text(" 3x Producto C (Pequeño)         \$5.00    \$15.00 \n");
    
            $printer->text($this->lines(47));
            $printer->feed();
    
            // Totales de la venta ficticia
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->setFont(Printer::FONT_A);
            $printer->setDoubleStrike(true);
    
            $printer->text("Total:  \$80.00\n");
            $printer->text("Efectivo:  \$100.00\n");
            $printer->text("Cambio:  \$20.00\n");
    
            $printer->feed();
    
            // Agradecimiento ficticio y despedida
            $printer->setDoubleStrike(false);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setFont(Printer::FONT_C);
            $printer->text("Gracias por su preferencia \n");
            $printer->feed();
    
            // Cerrar conexión
            $printer->close();
    
            return "Impresión completada";
        } catch (\Exception $e) {
            return "Error al imprimir: " . $e->getMessage();
        }
    }
    
    public function lines(int $lgn)
    {
        $asteriscos = Str::padRight("-", $lgn, "-");
        return "{$asteriscos}\n";
    }
}
