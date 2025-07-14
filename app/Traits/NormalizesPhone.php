<?php

namespace App\Traits;

trait NormalizesPhone
{
    /**
     * Normaliza um número de telefone para o formato padrão brasileiro com nono dígito.
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $cleaned = preg_replace('/\D/', '', $phone);

        if (substr($cleaned, 0, 2) === '55') {
            $cleaned = substr($cleaned, 2);
        }

        if (strlen($cleaned) === 10 && (int)$cleaned[2] >= 6) {
            $cleaned = substr($cleaned, 0, 2) . '9' . substr($cleaned, 2);
        }

        return '55' . $cleaned;
    }
}