<?php

namespace App\Services;

use Exception;

class StorageService
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
    }

    public function saveComprobante(string $ruc, string $fileName, ?string $xml, ?string $cdrZip): array
    {
        $relativeDir = "$ruc/" . date('Y/m');
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . $relativeDir;

        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0777, true);
        }

        $urls = [
            'xml_url' => null,
            'cdr_url' => null
        ];

        if ($xml) {
            file_put_contents("$fullPath/$fileName.xml", $xml);
            $urls['xml_url'] = "$relativeDir/$fileName.xml";
        }

        if ($cdrZip) {
            file_put_contents("$fullPath/R-$fileName.zip", $cdrZip);
            $urls['cdr_url'] = "$relativeDir/R-$fileName.zip";
        }

        return $urls;
    }
}
