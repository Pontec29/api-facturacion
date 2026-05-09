<?php

/**
 * Bridge de Facturación Electrónica POS-2026
 * Refacturado a Arquitectura de Servicios
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Cargar variables de entorno desde .env si existe
if (file_exists(dirname(__DIR__) . '/.env')) {
    $lines = file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
        putenv(trim($name) . '=' . trim($value));
    }
}

// Configuración de errores según el entorno
$isProd = getenv('APP_ENV') === 'production';
ini_set('display_errors', $isProd ? 0 : 1);
ini_set('display_startup_errors', $isProd ? 0 : 1);
error_reporting($isProd ? E_ALL & ~E_DEPRECATED & ~E_STRICT : E_ALL);


use App\Services\SunatService;
use App\Services\StorageService;
use App\Services\ReportService;
use App\Mappers\InvoiceMapper;

header('Content-Type: application/json');
ob_start(); // Capturar cualquier salida accidental (warnings, etc)

// --- SEGURIDAD: Validación de API Key ---
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$secret = getenv('API_SECRET') ?: 'mi_llave_secreta_pos_2026';

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

// Endpoint /void => Comunicación de Baja (Anulaciones)
if ($path === '/void') {
    handleVoid();
    exit;
}

// Endpoint /summary => Resumen Diario de Boletas
if ($path === '/summary') {
    handleSummary();
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

        // 1. Obtener Certificado
        $ruc = $data['empresa']['ruc'] ?? '20000000001';
        $certificadoPath = $data['empresa']['certificado_path'] ?? '';
        $certContent = null;

        if (strpos($certificadoPath, 'http') === 0) {
            // Es una URL (ej. firmada de Firebase), descargarlo
            $certContent = @file_get_contents($certificadoPath);
            if (!$certContent) {
                throw new Exception("No se pudo descargar el certificado desde la URL de Storage.");
            }
        } else {
            throw new Exception("Operación abortada: El certificado debe ser una URL válida de Storage por seguridad. No se permiten archivos locales.");
        }

        // 2. Inicializar Servicios
        $sunatService = new SunatService($data['empresa'], $certContent);
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
            $empresaData = array_merge($data['empresa'] ?? [], [
                'hash' => $hash ?? '',
                'estado' => $data['venta']['estado'] ?? 'EMITIDO'
            ]);
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

/**
 * Endpoint /void - Comunicación de Baja (Anulaciones).
 */
function handleVoid(): void
{
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            throw new Exception('No JSON data received');
        }

        // 1. Obtener Certificado
        $ruc = $data['empresa']['ruc'] ?? '20000000001';
        $certificadoPath = $data['empresa']['certificado_path'] ?? '';
        $certContent = null;

        if (strpos($certificadoPath, 'http') === 0) {
            $certContent = @file_get_contents($certificadoPath);
            if (!$certContent) {
                throw new Exception("No se pudo descargar el certificado desde la URL de Storage.");
            }
        } else {
            throw new Exception("Operación abortada: El certificado debe ser una URL válida de Storage por seguridad. No se permiten archivos locales.");
        }

        // 2. Inicializar Servicios
        $sunatService = new \App\Services\SunatService($data['empresa'], $certContent);
        $mapper = new \App\Mappers\VoidedMapper();

        // 3. Mapear y Enviar
        $voided = $mapper->map($data);
        $res = $sunatService->send($voided);
        
        // 4. Procesar Respuesta
        $signedXml = $sunatService->getSee()->getFactory()->getLastXml();
        
        $response = [
            'success' => $res->isSuccess(),
            'xml_base64' => $signedXml ? base64_encode($signedXml) : null,
        ];

        if (!$res->isSuccess()) {
            $error = $res->getError();
            $response['error'] = [
                'code' => $error ? $error->getCode() : 'UNKNOWN',
                'message' => $error ? $error->getMessage() : 'Error desconocido de SUNAT'
            ];
        } else {
            // Para Bajas, SUNAT devuelve un Ticket
            $response['ticket'] = $res->getTicket();
        }

        ob_clean();
        echo json_encode($response);

    } catch (\Throwable $e) {
        http_response_code(500);
        ob_clean();
        echo json_encode([
            'success' => false,
            'error'   => ['message' => $e->getMessage()],
        ]);
    }
}

/**
 * Endpoint /summary - Resumen Diario de Boletas.
 */
function handleSummary(): void
{
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            throw new Exception('No JSON data received');
        }

        // 1. Obtener Certificado
        $ruc = $data['empresa']['ruc'] ?? '20000000001';
        $certificadoPath = $data['empresa']['certificado_path'] ?? '';
        $certContent = null;

        if (strpos($certificadoPath, 'http') === 0) {
            $certContent = @file_get_contents($certificadoPath);
            if (!$certContent) {
                throw new Exception("No se pudo descargar el certificado desde la URL de Storage.");
            }
        } else {
            throw new Exception("Operación abortada: El certificado debe ser una URL válida de Storage por seguridad. No se permiten archivos locales.");
        }

        // 2. Inicializar Servicios
        $sunatService = new \App\Services\SunatService($data['empresa'], $certContent);
        $mapper = new \App\Mappers\SummaryMapper();

        // 3. Mapear y Enviar
        $summary = $mapper->map($data);
        $res = $sunatService->send($summary);
        
        // 4. Procesar Respuesta
        $signedXml = $sunatService->getSee()->getFactory()->getLastXml();
        
        $response = [
            'success' => $res->isSuccess(),
            'xml_base64' => $signedXml ? base64_encode($signedXml) : null,
        ];

        if (!$res->isSuccess()) {
            $error = $res->getError();
            $response['error'] = [
                'code' => $error ? $error->getCode() : 'UNKNOWN',
                'message' => $error ? $error->getMessage() : 'Error desconocido de SUNAT'
            ];
        } else {
            // Para Resúmenes, SUNAT devuelve un Ticket
            $response['ticket'] = $res->getTicket();
        }

        ob_clean();
        echo json_encode($response);

    } catch (\Throwable $e) {
        http_response_code(500);
        ob_clean();
        echo json_encode([
            'success' => false,
            'error'   => ['message' => $e->getMessage()],
        ]);
    }
}
