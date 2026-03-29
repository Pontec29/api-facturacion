<?php
// Desactivamos cualquier salida de errores en HTML que rompa el JSON
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);
error_reporting(0); // Por ahora lo bajamos a 0 para que no meta ruido

require dirname(__DIR__) . '/vendor/autoload.php';

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\FormaPagos\Cuota;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;

header('Content-Type: application/json');

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'No JSON data received']);
        exit;
    }

    // 1. Configuración Dinámica
    $ruc = $data['empresa']['ruc'] ?? '20000000001';
    $certPath = dirname(__DIR__) . "/certs/$ruc.pem";
    if (!file_exists($certPath)) $certPath = dirname(__DIR__) . '/certs/certificate.pem';

    if (!file_exists($certPath)) {
        throw new Exception("No hay certificado .pem en /certs/");
    }

    $see = new See();
    $see->setCertificate(file_get_contents($certPath));

    $solUser = $data['empresa']['sol_user'] ?? 'MODDATOS';
    $solPass = $data['empresa']['sol_pass'] ?? 'MODDATOS';
    $isProduction = (bool)($data['empresa']['produccion'] ?? false);

    $see->setService($isProduction ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA);
    $see->setClaveSOL($ruc, $solUser, $solPass); 

    // 2. Información Empresa
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
        ->setNombreComercial($data['empresa']['nombre_comercial'] ?? 'TEST POS')
        ->setAddress($address);

    // 3. Cliente
    $client = (new Client())
        ->setTipoDoc($data['cliente']['tipo_doc'] ?? '6')
        ->setNumDoc($data['cliente']['num_doc'] ?? '20405060708')
        ->setRznSocial($data['cliente']['nombre'] ?? 'CLIENTE DE PRUEBA');

    // 4. Comprobante
    $invoice = (new Invoice());
    $invoice->setUblVersion('2.1')
        ->setTipoOperacion('0101')
        ->setTipoDoc($data['venta']['tipo_comprobante'] ?? '01')
        ->setSerie($data['venta']['serie'] ?? 'F001')
        ->setCorrelativo($data['venta']['numero'] ?? '1')
        ->setFechaEmision(new DateTime($data['venta']['fecha_emision'] ?? 'now'))
        ->setTipoMoneda($data['venta']['moneda'] ?? 'PEN')
        ->setClient($client)
        ->setCompany($company)
        ->setMtoOperGravadas((float)($data['venta']['total_gravado'] ?? 0))
        ->setMtoOperExoneradas((float)($data['venta']['total_exonerado'] ?? 0))
        ->setMtoOperInafectas((float)($data['venta']['total_inafecto'] ?? 0))
        ->setMtoIGV((float)($data['venta']['total_igv'] ?? 0))
        ->setMtoImpVenta((float)($data['venta']['total'] ?? 0));

    // Pago
    if (($data['venta']['forma_pago'] ?? 'CONTADO') === 'CONTADO') {
        $invoice->setFormaPago(new FormaPagoContado());
    } else {
        $invoice->setFormaPago(new FormaPagoCredito((float)($data['venta']['total_neto'] ?? $data['venta']['total'])));
        if (isset($data['venta']['cuotas'])) {
            $cuotas = [];
            foreach ($data['venta']['cuotas'] as $c) {
                $cuotas[] = (new Cuota())
                    ->setMonto((float)$c['monto'])
                    ->setFechaPago(new DateTime($c['fecha_pago']));
            }
            $invoice->setCuotas($cuotas);
        }
    }

    // 5. Detalles
    $details = [];
    foreach ($data['detalles'] as $item) {
        $detail = (new SaleDetail())
            ->setCodProducto($item['codigo'] ?? '-')
            ->setUnidad($item['unidad'] ?? 'NIU')
            ->setCantidad((float)$item['cantidad'])
            ->setDescripcion($item['descripcion'])
            ->setMtoBaseIgv((float)$item['base_igv'])
            ->setPorcentajeIgv((float)($item['igv_porcentaje'] ?? 18.00))
            ->setIgv((float)$item['igv'])
            ->setTipAfeIgv($item['tipo_afectacion'] ?? '10')
            ->setTotalImpuestos((float)$item['igv'])
            ->setMtoValorUnitario((float)$item['valor_unitario'])
            ->setMtoPrecioUnitario((float)$item['precio_unitario']);
        $details[] = $detail;
    }
    $invoice->setDetails($details);

    $invoice->setLegends([
        (new Legend())->setCode('1000')->setValue($data['venta']['total_letras'] ?? 'SOLES')
    ]);

    // 6. Enviar a SUNAT (getLastXml() da el XML firmado después del envío)
    $res = $see->send($invoice);
    $signedXml = $see->getFactory()->getLastXml();

    // Extraer Hash para el QR (Es el DigestValue de la firma)
    $hash = '';
    if (preg_match('/<ds:DigestValue>(.*?)<\/ds:DigestValue>/', $signedXml, $matches)) {
        $hash = $matches[1];
    }

    $response = [
        'success' => $res->isSuccess(),
        'hash' => $hash,
        'xml' => $signedXml,
    ];

    if (!$res->isSuccess()) {
        $error = $res->getError();
        $response['error'] = [
            'code' => $error ? $error->getCode() : 'UNKNOWN',
            'message' => $error ? $error->getMessage() : 'Error desconocido'
        ];
    } else {
        $cdrZip = $res->getCdrZip();
        $cdrResponse = $res->getCdrResponse();
        $response['cdr_zip'] = $cdrZip ? base64_encode($cdrZip) : null;
        $response['sunat_code'] = $cdrResponse ? $cdrResponse->getCode() : null;
        $response['sunat_response'] = $cdrResponse ? $cdrResponse->getDescription() : null;

        // Storage
        try {
            $storagePath = dirname(__DIR__) . "/storage/comprobantes/$ruc/" . date('Y/m');
            if (!is_dir($storagePath)) mkdir($storagePath, 0777, true);
            $fileName = $invoice->getSerie() . "-" . $invoice->getCorrelativo();
            if ($signedXml) file_put_contents("$storagePath/$fileName.xml", $signedXml);
            if ($cdrZip) file_put_contents("$storagePath/R-$fileName.zip", $cdrZip);
            $response['xml_url'] = "$ruc/" . date('Y/m') . "/$fileName.xml";
            $response['cdr_url'] = "$ruc/" . date('Y/m') . "/R-$fileName.zip";
        } catch (Exception $storageEx) {
            $response['storage_error'] = $storageEx->getMessage();
        }
    }

    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['message' => $e->getMessage()]
    ]);
}
