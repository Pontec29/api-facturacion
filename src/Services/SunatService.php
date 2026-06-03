<?php

namespace App\Services;

use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Exception;

class SunatService
{
    private See $see;
    private string $ruc;

    public function __construct(array $empresaConfig, string $certContent)
    {
        $this->see = new See();
        $this->ruc = $empresaConfig['ruc'];

        $certContent = $this->normalizarCertificado($certContent, $empresaConfig['certificado_clave'] ?? '');
        $this->see->setCertificate($certContent);
        $this->see->setClaveSOL(
            $this->ruc,
            $empresaConfig['sol_user'] ?? 'MODDATOS',
            $empresaConfig['sol_pass'] ?? 'MODDATOS'
        );

        $isProduction = (bool)($empresaConfig['produccion'] ?? false);
        $this->see->setService($isProduction ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA);
    }

    public function getSee(): See
    {
        return $this->see;
    }

    public function send($document)
    {
        return $this->see->send($document);
    }

    private function normalizarCertificado(string $certContent, string $clave): string
    {
        // Intenta leer el .p12 con la clave, si falla (certificado legacy) lo reconvierte
        $certs = [];
        if (openssl_pkcs12_read($certContent, $certs, $clave)) {
            return $certContent; // Ya es compatible, lo usa directo
        }

        // Certificado legacy: reconvertir usando archivos temporales
        $tmpP12  = tempnam(sys_get_temp_dir(), 'cert_') . '.p12';
        $tmpPem  = tempnam(sys_get_temp_dir(), 'cert_') . '.pem';
        $tmpNew  = tempnam(sys_get_temp_dir(), 'cert_') . '.p12';

        try {
            file_put_contents($tmpP12, $certContent);

            // Extraer con flag legacy
            exec("openssl pkcs12 -legacy -in {$tmpP12} -out {$tmpPem} -nodes -passin pass:{$clave} 2>/dev/null");
            // Empaquetar de nuevo en formato moderno
            exec("openssl pkcs12 -export -in {$tmpPem} -out {$tmpNew} -passout pass:{$clave} 2>/dev/null");

            $newContent = file_get_contents($tmpNew);
            return $newContent ?: $certContent;
        } finally {
            @unlink($tmpP12);
            @unlink($tmpPem);
            @unlink($tmpNew);
        }
    }
}
