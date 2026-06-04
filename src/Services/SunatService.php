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

    /**
     * Convierte el certificado .p12/.pfx a formato PEM (clave privada + certificado público
     * concatenados), que es lo que Greenter->setCertificate() necesita para firmar.
     *
     * Pasar el .pfx binario directamente provoca que openssl_sign falle con
     * "DECODER routines::unsupported" en ciertos certificados (codificación PKCS12 con
     * longitud indefinida). Extrayéndolo a PEM, openssl_sign lo procesa correctamente.
     *
     * Maneja también certificados legacy (RC2-40/3DES) vía openssl CLI con -legacy.
     *
     * @throws \Exception si la clave es incorrecta o el archivo no es un PKCS12 válido.
     */
    private function normalizarCertificado(string $certContent, string $clave): string
    {
        // 1. Intento directo: extraer clave + cert a PEM con la API de PHP.
        $certs = [];
        if (openssl_pkcs12_read($certContent, $certs, $clave)) {
            $pem = ($certs['pkey'] ?? '') . ($certs['cert'] ?? '');
            if ($pem !== '') {
                return $pem;
            }
        }

        // 2. Fallback legacy: el cert usa algoritmos antiguos que la API de PHP no abre.
        //    Reconvertir con openssl CLI (provider legacy) a PEM.
        $tmpP12 = tempnam(sys_get_temp_dir(), 'cert_') . '.p12';
        $tmpPem = tempnam(sys_get_temp_dir(), 'cert_') . '.pem';

        try {
            file_put_contents($tmpP12, $certContent);
            $claveEsc = escapeshellarg($clave);

            // Extraer clave + cert a PEM (clave sin cifrar -nodes; archivo temporal, se borra).
            exec("openssl pkcs12 -legacy -in {$tmpP12} -out {$tmpPem} -nodes -passin pass:{$claveEsc} 2>/dev/null", $o, $r);
            if ($r === 0 && file_exists($tmpPem) && filesize($tmpPem) > 0) {
                $pem = file_get_contents($tmpPem);
                if ($pem !== false && $pem !== '') {
                    return $pem;
                }
            }

            // Si llegamos aquí, ni la API ni el CLI pudieron abrir el certificado:
            // la causa más probable es contraseña incorrecta.
            throw new \Exception('No se pudo abrir el certificado (contraseña incorrecta o formato no soportado).');
        } finally {
            @unlink($tmpP12);
            @unlink($tmpPem);
        }
    }
}
