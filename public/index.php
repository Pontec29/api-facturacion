<?php

/**
 * Bridge de Facturación Electrónica POS-2026
 * Refacturado a Arquitectura de Servicios
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\SunatService;
use App\Services\StorageService;
use App\Mappers\InvoiceMapper;

header('Content-Type: application/json');
ob_start(); // Capturar cualquier salida accidental (warnings, etc)

// --- SEGURIDAD: Validación de API Key ---
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$secret = 'mi_llave_secreta_pos_2026'; // Esto debería ir en un .env en producción

if ($apiKey !== $secret) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => ['message' => 'Unauthorized: Invalid API Key']]);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception('No JSON data received');
    }

    // 1. Obtener Certificado (Local fallback por ahora, escalable a URL)
    $ruc = $data['empresa']['ruc'] ?? '20000000001';
    $certPath = dirname(__DIR__) . "/certs/$ruc.pem";
    if (!file_exists($certPath)) {
        $certPath = dirname(__DIR__) . '/certs/certificate.pem';
    }

    if (!file_exists($certPath)) {
        throw new Exception("Certificado no encontrado para RUC $ruc");
    }

    // 2. Inicializar Servicios
    $sunatService = new SunatService($data['empresa'], file_get_contents($certPath));
    $storageService = new StorageService(dirname(__DIR__) . '/storage/comprobantes');
    $mapper = new InvoiceMapper();

    // 3. Mapear y Enviar
    $invoice = $mapper->map($data);
    $res = $sunatService->send($invoice);
    
    // 4. Procesar Respuesta
    $signedXml = $sunatService->getSee()->getFactory()->getLastXml();
    $hash = '';
    if ($signedXml && preg_match('/<ds:DigestValue>(.*?)<\/ds:DigestValue>/', $signedXml, $matches)) {
        $hash = $matches[1];
    }

    $response = [
        'success' => $res->isSuccess(),
        'hash' => $hash,
        'xml_base64' => $signedXml ? base64_encode($signedXml) : null,
    ];

    if (!$res->isSuccess()) {
        $error = $res->getError();
        $response['error'] = [
            'code' => $error ? $error->getCode() : 'UNKNOWN',
            'message' => $error ? $error->getMessage() : 'Error desconocido de SUNAT'
        ];
    } else {
        $cdrZip = $res->getCdrZip();
        $cdrResponse = $res->getCdrResponse();
        
        $response['sunat_code'] = $cdrResponse ? $cdrResponse->getCode() : null;
        $response['sunat_response'] = $cdrResponse ? $cdrResponse->getDescription() : null;
        $response['cdr_base64'] = $cdrZip ? base64_encode($cdrZip) : null;

        // 5. Almacenamiento Local (opcional, como respaldo)
        $fileName = $invoice->getSerie() . "-" . $invoice->getCorrelativo();
        $storageUrls = $storageService->saveComprobante($ruc, $fileName, $signedXml, $cdrZip);
        
        $response = array_merge($response, $storageUrls);
    }

    ob_clean();
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => ['message' => $e->getMessage()]
    ]);
}
