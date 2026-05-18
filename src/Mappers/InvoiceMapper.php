<?php

namespace App\Mappers;

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use DateTime;

class InvoiceMapper
{
    public function map(array $data): Invoice
    {
        $ruc = $data['empresa']['ruc'] ?? '20000000001';
        $moneda = $data['venta']['moneda'] ?? 'PEN';

        // 1. Empresa
        $address = (new Address())
            ->setUbigueo($data['empresa']['ubigueo'] ?? '150101')
            ->setDepartamento($data['empresa']['departamento'] ?? 'LIMA')
            ->setProvincia($data['empresa']['provincia'] ?? 'LIMA')
            ->setDistrito($data['empresa']['distrito'] ?? 'LIMA')
            ->setUrbanizacion($data['empresa']['urbanizacion'] ?? '-')
            ->setDireccion($data['empresa']['direccion'] ?? 'AV. SIEMPRE VIVA 123');

        $company = new Company();
        $company->setRuc($data['empresa']['ruc'] ?? '20000000000')
            ->setRazonSocial($data['empresa']['razon_social'] ?? 'EMPRESA TEST')
            ->setNombreComercial($data['empresa']['nombre_comercial'] ?? '')
            ->setAddress((new Address())
                ->setDireccion($data['empresa']['direccion'] ?? '-')
                ->setDistrito($data['empresa']['distrito'] ?? 'LIMA')
                ->setProvincia($data['empresa']['provincia'] ?? 'LIMA')
                ->setDepartamento($data['empresa']['departamento'] ?? 'LIMA')
                ->setUbigueo($data['empresa']['ubigeo'] ?? '150101')
                ->setCodLocal($data['empresa']['cod_local'] ?? '0000'));

        // 2. Cliente
        $client = new Client();
        $client->setTipoDoc($data['cliente']['tipo_doc'] ?? '1')
            ->setNumDoc($data['cliente']['num_doc'] ?? '00000000')
            ->setRznSocial($data['cliente']['nombre'] ?? 'CLIENTE VARIOS')
            ->setAddress((new Address())
                ->setDireccion($data['cliente']['direccion'] ?? '-'));

        // 3. Detalles (se procesan primero para acumular totales)
        $details = [];
        $sumBaseIgv = 0.0;
        $sumIgv = 0.0;

        foreach ($data['detalles'] as $item) {
            $igv = round((float)($item['igv'] ?? 0), 2);
            $baseIgv = round((float)($item['base_igv'] ?? 0), 2);
            $cantidad = (float)($item['cantidad'] ?? 1);
            $valorUnitario = round((float)($item['valor_unitario'] ?? 0), 4);
            $precioUnitario = round((float)($item['precio_unitario'] ?? 0), 4);
            $porcentajeIgv = round((float)($item['igv_porcentaje'] ?? 18.00), 2);

            $detail = new SaleDetail();
            $detail->setCodProducto($item['codigo'] ?? '-');
            $detail->setUnidad($item['unidad'] ?? 'NIU');
            $detail->setCantidad($cantidad);
            $detail->setDescripcion($item['descripcion'] ?? 'PRODUCTO');
            $detail->setMtoBaseIgv($baseIgv);
            $detail->setPorcentajeIgv($porcentajeIgv);
            $detail->setIgv($igv);
            $detail->setTipAfeIgv($item['tipo_afectacion'] ?? '10');
            $detail->setTotalImpuestos($igv);
            $detail->setMtoValorVenta($baseIgv);
            $detail->setMtoValorUnitario($valorUnitario);
            $detail->setMtoPrecioUnitario($precioUnitario);

            $details[] = $detail;

            $sumBaseIgv += $baseIgv;
            $sumIgv += $igv;
        }

        // 4. Totales
        $totalGravado   = round((float)($data['venta']['total_gravado']   ?? $sumBaseIgv), 2);
        $totalIgv       = round((float)($data['venta']['total_igv']       ?? $sumIgv), 2);
        $totalExonerado = round((float)($data['venta']['total_exonerado'] ?? 0), 2);
        $totalInafecto  = round((float)($data['venta']['total_inafecto']  ?? 0), 2);
        $total          = round((float)($data['venta']['total']           ?? ($totalGravado + $totalIgv)), 2);
        $tipoDoc = $data['venta']['tipo_comprobante'] ?? '01';

        // Tipo de operación según tipo de comprobante (catálogo 51 SUNAT)
        // 0101 = Venta interna (Facturas)
        // 0200 = Venta interna (Boletas)
        $tipoOperacion = $tipoDoc === '03' ? '0200' : '0101';

        // Fecha de emisión: Java la envía en venta.fecha_emision (YYYY-MM-DD)
        $fechaEmision = isset($data['venta']['fecha_emision'])
            ? new \DateTime($data['venta']['fecha_emision'])
            : new \DateTime();

        // 5. Crear comprobante
        $invoice = new Invoice();
        $invoice->setUblVersion('2.1')
            ->setTipoOperacion($tipoOperacion)
            ->setTipoDoc($tipoDoc)
            ->setSerie($data['venta']['serie'] ?? 'F001')
            ->setCorrelativo($data['venta']['numero'] ?? '1')
            ->setFechaEmision($fechaEmision)
            ->setTipoMoneda($data['venta']['moneda'] ?? 'PEN')
            ->setClient($client)
            ->setCompany($company)
            ->setMtoOperGravadas(number_format($totalGravado, 2, '.', ''))
            ->setMtoOperExoneradas(number_format($totalExonerado, 2, '.', ''))
            ->setMtoOperInafectas(number_format($totalInafecto, 2, '.', ''))
            ->setMtoIGV(number_format($totalIgv, 2, '.', ''))
            ->setTotalImpuestos(number_format($totalIgv, 2, '.', ''))
            ->setValorVenta(number_format($totalGravado + $totalExonerado + $totalInafecto, 2, '.', ''))
            ->setSubTotal(number_format($total, 2, '.', ''))
            ->setMtoImpVenta(number_format($total, 2, '.', ''))
            ->setDetails($details);

        // 6. Forma de pago
        if (strtoupper($data['venta']['forma_pago'] ?? 'CONTADO') === 'CONTADO') {
            $invoice->setFormaPago(new FormaPagoContado());
        } else {
            $invoice->setFormaPago(new FormaPagoCredito($total));
            if (isset($data['venta']['cuotas'])) {
                $cuotas = [];
                foreach ($data['venta']['cuotas'] as $c) {
                    $cuotas[] = (new \Greenter\Model\Sale\FormaPagos\Cuota())
                        ->setMonto(round((float)$c['monto'], 2))
                        ->setFechaPago(new DateTime($c['fecha_pago']));
                }
                $invoice->setCuotas($cuotas);
            }
        }

        // 7. Leyenda: monto en letras (se genera aquí, una sola vez)
        $formatter   = new \Luecano\NumeroALetras\NumeroALetras();
        $simbolo     = strtoupper($data['venta']['moneda'] ?? 'PEN') === 'USD' ? 'DÓLARES' : 'SOLES';
        $totalLetras = $formatter->toInvoice($total, 2, $simbolo);

        $invoice->setLegends([
            (new Legend())->setCode('1000')->setValue($totalLetras)
        ]);

        return $invoice;
    }
}
