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
}
