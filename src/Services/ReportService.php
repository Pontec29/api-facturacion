<?php

namespace App\Services;

use Greenter\Report\HtmlReport;
use Greenter\Model\Sale\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReportService
{
    private HtmlReport $renderer;

    public function __construct()
    {
        $templateDir = dirname(__DIR__) . '/Templates';
        $this->renderer = new HtmlReport($templateDir);
    }

    public function generatePdf(Invoice $invoice, array $empresaData = []): string
    {
        // 1. Determinar qué plantilla usar (jerarquía: RUC_Formato -> RUC -> Formato -> default)
        $ruc = $empresaData['ruc'] ?? 'default';
        $formato = strtolower($empresaData['formato_pdf'] ?? 'A4');
        $templateDir = dirname(__DIR__) . '/Templates';
        
        $templateFile = 'invoice_v2.html.twig'; // Default
        
        $options = [
            $ruc . '_' . $formato . '.html.twig',
            $ruc . '.html.twig',
            $formato . '.html.twig',
            'invoice_v2.html.twig'
        ];

        foreach ($options as $opt) {
            if (file_exists($templateDir . '/' . $opt)) {
                $templateFile = $opt;
                break;
            }
        }
        
        $this->renderer->setTemplate($templateFile);

        // 2. Generar HTML
        $params = $this->buildParams($empresaData);
        $html = $this->renderer->render($invoice, $params);

        // 2. Configurar Dompdf
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        
        // Ajustar tamaño de papel según formato
        if (($empresaData['formato_pdf'] ?? 'A4') === 'TICKET') {
            // 80mm es aprox 227pt. Altura puede ser larga (ej: 800pt)
            $dompdf->setPaper([0, 0, 227, 800], 'portrait');
        } else {
            $dompdf->setPaper('A4', 'portrait');
        }
        
        $dompdf->render();

        // 3. Retornar el contenido del PDF
        return $dompdf->output();
    }

    /**
     * Construir los parámetros que espera la plantilla invoice.html.twig.
     * La plantilla accede a params.system.logo, params.system.hash, etc.
     */
    private function buildParams(array $empresaData): array
    {
        // Logo: Priorizar URL de la BD, luego archivo local, luego placeholder
        $logoBase64 = null;
        if (!empty($empresaData['logo_url'])) {
            $logoBase64 = @file_get_contents($empresaData['logo_url']);
        }

        if (!$logoBase64) {
            $localLogo = dirname(__DIR__, 2) . '/storage/logos/' . ($empresaData['ruc'] ?? 'default') . '.png';
            if (file_exists($localLogo)) {
                $logoBase64 = file_get_contents($localLogo);
            }
        }

        if (!$logoBase64) {
            $logoBase64 = file_get_contents($this->getPlaceholderLogo());
        }

        return [
            'system' => [
                'logo' => $logoBase64,
                'hash' => $empresaData['hash'] ?? '',
            ],
            'user' => [
                'header'  => $empresaData['razon_social'] ?? 'EMPRESA',
                'estado' => $empresaData['estado'] ?? 'EMITIDO',
                'color' => $empresaData['color_pdf'] ?? '#000000',
                'mostrar_cuentas' => (bool)($empresaData['mostrar_cuentas'] ?? true),
                'qr_size' => $empresaData['qr_size'] ?? '120',
                'cuentas' => $empresaData['cuentas_banco'] ?? [],
                'resolucion' => $empresaData['resolucion_sunat'] ?? '',
                'extras' => [],
            ],
        ];
    }

    /**
     * Crear un logo placeholder PNG de 1x1 px transparente si no hay logo.
     */
    private function getPlaceholderLogo(): string
    {
        $tmpPath = sys_get_temp_dir() . '/placeholder_logo.png';
        if (!file_exists($tmpPath)) {
            $img = imagecreatetruecolor(200, 80);
            $white = imagecolorallocate($img, 255, 255, 255);
            imagefill($img, 0, 0, $white);
            imagepng($img, $tmpPath);
            imagedestroy($img);
        }
        return $tmpPath;
    }
}
