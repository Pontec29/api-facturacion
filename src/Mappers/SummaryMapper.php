<?php

namespace App\Mappers;

use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use DateTime;

class SummaryMapper
{
    public function map(array $data): Summary
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

        // 2. Summary
        $summary = new Summary();
        $summary->setCorrelativo($data['resumen']['correlativo'] ?? '1')
            ->setFecGeneracion(new DateTime($data['resumen']['fecha_generacion'] ?? 'now'))
            ->setFecResumen(new DateTime($data['resumen']['fecha_resumen'] ?? 'now'))
            ->setCompany($company);

        // 3. Detalles
        $details = [];
        foreach ($data['detalles'] as $item) {
            $detail = new SummaryDetail();
            $detail->setTipoDoc($item['tipo_doc'] ?? '03') // 03 = Boleta
                ->setSerieNro($item['serie_numero'] ?? 'B001-1')
                ->setClienteTipo($item['cliente_tipo'] ?? '1')
                ->setClienteNro($item['cliente_numero'] ?? '00000000')
                ->setEstado($item['estado'] ?? '1') // 1 = Adicionar, 2 = Modificar, 3 = Anular
                ->setTotal((float)($item['total'] ?? 0))
                ->setMtoOperGravadas((float)($item['gravado'] ?? 0))
                ->setMtoOperExoneradas((float)($item['exonerado'] ?? 0))
                ->setMtoOperInafectas((float)($item['inafecto'] ?? 0))
                ->setMtoIGV((float)($item['igv'] ?? 0));

            $details[] = $detail;
        }

        $summary->setDetails($details);

        return $summary;
    }
}
