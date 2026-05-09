<?php

namespace App\Mappers;

use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;
use DateTime;

class VoidedMapper
{
    public function map(array $data): Voided
    {
        // 1. Empresa
        $company = new Company();
        $company->setRuc($data['empresa']['ruc'] ?? '20000000000')
            ->setRazonSocial($data['empresa']['razon_social'] ?? 'EMPRESA TEST')
            ->setAddress((new Address())
                ->setDireccion($data['empresa']['direccion'] ?? '-')
                ->setDistrito($data['empresa']['distrito'] ?? 'LIMA')
                ->setProvincia($data['empresa']['provincia'] ?? 'LIMA')
                ->setDepartamento($data['empresa']['departamento'] ?? 'LIMA')
                ->setUbigueo($data['empresa']['ubigeo'] ?? '150101'));

        // 2. Voided (Baja)
        $voided = new Voided();
        $voided->setCorrelativo($data['baja']['correlativo'] ?? '1')
            ->setFecGeneracion(new DateTime($data['baja']['fecha_generacion'] ?? 'now'))
            ->setFecComunicacion(new DateTime($data['baja']['fecha_comunicacion'] ?? 'now'))
            ->setCompany($company);

        // 3. Detalles
        $details = [];
        foreach ($data['detalles'] as $item) {
            $detail = new VoidedDetail();
            $detail->setTipoDoc($item['tipo_doc'] ?? '01') // 01 = Factura
                ->setSerie($item['serie'] ?? 'F001')
                ->setNumero($item['numero'] ?? '1')
                ->setDesMotivoBaja($item['motivo'] ?? 'Error en el documento');

            $details[] = $detail;
        }

        $voided->setDetails($details);

        return $voided;
    }
}
