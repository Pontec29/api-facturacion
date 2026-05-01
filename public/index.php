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
use App\Services\ReportService;
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

// --- ROUTING: Detectar el endpoint solicitado ---
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);

// Endpoint /pdf => Solo genera PDF (sin enviar a SUNAT)
if ($path === '/pdf') {
    handlePdfOnly();
    exit;
}



// Endpoint / (raíz) => Flujo completo: firma + envío SUNAT + PDF
handleFullFlow();

// ============================================================================
// FUNCIONES
// ============================================================================

/**
 * Endpoint /pdf - Genera SOLO el PDF sin firmar ni enviar nada a SUNAT.
 * Recibe el mismo payload que el flujo completo pero solo usa los datos
 * para renderizar la plantilla Twig y devolver el PDF en base64.
 */
function handlePdfOnly(): void
{
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            throw new Exception('No JSON data received');
        }

        $reportService = new ReportService();
        $mapper = new InvoiceMapper();

        // Mapear la factura (necesitamos el objeto Invoice para la plantilla)
        $invoice = $mapper->map($data);

        // Construir empresaData con formato y hash
        $empresaData = array_merge($data['empresa'] ?? [], [
            'hash'        => $data['hash'] ?? '',
            'formato_pdf' => $data['formato_pdf'] ?? ($data['empresa']['formato_pdf'] ?? 'A4'),
        ]);

        $pdfContent = $reportService->generatePdf($invoice, $empresaData);

        ob_clean();
        echo json_encode([
            'success'    => true,
            'pdf_base64' => base64_encode($pdfContent),
        ]);

    } catch (Throwable $e) {
        http_response_code(500);
        ob_clean();
        echo json_encode([
            'success' => false,
            'error'   => ['message' => $e->getMessage()],
        ]);
    }
}

/**
 * Endpoint / (raíz) - Flujo completo: firma XML, envío a SUNAT, generación de PDF.
 */
function handleFullFlow(): void
{
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
        $reportService = new ReportService();
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

        // Generar PDF (no debe bloquear el flujo principal)
        $pdfBase64 = null;
        try {
            $empresaData = array_merge($data['empresa'] ?? [], ['hash' => $hash ?? '']);
            $pdfBase64 = base64_encode($reportService->generatePdf($invoice, $empresaData));
        } catch (\Throwable $e) {
            error_log('Error generando PDF: ' . $e->getMessage());
        }

        $response = [
            'success' => $res->isSuccess(),
            'hash' => $hash,
            'xml_base64' => $signedXml ? base64_encode($signedXml) : null,
            'pdf_base64' => $pdfBase64,
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
}
