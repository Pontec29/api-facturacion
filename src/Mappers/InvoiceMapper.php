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

        $company = (new Company())
            ->setRuc($ruc)
            ->setRazonSocial($data['empresa']['razon_social'] ?? 'EMPRESA TEST SAC')
            ->setNombreComercial($data['empresa']['nombre_comercial'] ?? $data['empresa']['razon_social'] ?? 'TEST POS')
            ->setAddress($address);

        // 2. Cliente
        $client = (new Client())
            ->setTipoDoc($data['cliente']['tipo_doc'] ?? '6')
            ->setNumDoc($data['cliente']['num_doc'] ?? '20405060708')
            ->setRznSocial($data['cliente']['nombre'] ?? 'CLIENTE DE PRUEBA');

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

        // 4. Comprobante
        $totalGravado = round((float)($data['venta']['total_gravado'] ?? $sumBaseIgv), 2);
        $totalIgv = round((float)($data['venta']['total_igv'] ?? $sumIgv), 2);
        $totalVenta = round((float)($data['venta']['total'] ?? ($totalGravado + $totalIgv)), 2);

        $invoice = new Invoice();
        $invoice->setUblVersion('2.1');
        $invoice->setTipoOperacion('0101');
        $invoice->setTipoDoc($data['venta']['tipo_comprobante'] ?? '01');
        $invoice->setSerie($data['venta']['serie'] ?? 'F001');
        $invoice->setCorrelativo($data['venta']['numero'] ?? '1');
        $invoice->setFechaEmision(new DateTime($data['venta']['fecha_emision'] ?? 'now'));
        $invoice->setTipoMoneda($moneda);
        $invoice->setClient($client);
        $invoice->setCompany($company);

        // Montos de cabecera
        $invoice->setMtoOperGravadas($totalGravado);
        $invoice->setMtoOperExoneradas(round((float)($data['venta']['total_exonerado'] ?? 0), 2));
        $invoice->setMtoOperInafectas(round((float)($data['venta']['total_inafecto'] ?? 0), 2));
        $invoice->setMtoIGV($totalIgv);
        $invoice->setTotalImpuestos($totalIgv);
        $invoice->setValorVenta($totalGravado);
        $invoice->setSubTotal($totalVenta);
        $invoice->setMtoImpVenta($totalVenta);

        // Detalles
        $invoice->setDetails($details);

        // Pago
        if (($data['venta']['forma_pago'] ?? 'CONTADO') === 'CONTADO') {
            $invoice->setFormaPago(new FormaPagoContado());
        } else {
            $invoice->setFormaPago(new FormaPagoCredito($totalVenta));
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

        // Leyendas
        $invoice->setLegends([
            (new Legend())->setCode('1000')->setValue($data['venta']['total_letras'] ?? 'SOLES')
        ]);

        return $invoice;
    }
}
