<?php

namespace App\Services;

class TelegramBotCommandParser
{
    /**
     * Parsea el caption a parametros para PhotoUploadService.
     * Formato: p, p 2, b A1, b 2 A1, t, t 12345
     */
    public function parsePhotoCaption(string $caption): ?array
    {
        $parts = preg_split('/\s+/', strtolower(trim($caption)));
        $cmd = $parts[0] ?? '';

        $typeMap = [
            'p' => 'pallet', 'pallet' => 'pallet',
            'b' => 'base', 'base' => 'base',
            't' => 'ticket', 'ticket' => 'ticket',
        ];

        $type = $typeMap[$cmd] ?? null;
        if (! $type) {
            return null;
        }

        $params = ['type' => $type];

        if ($type === 'pallet') {
            $arg = $parts[1] ?? null;
            if (! $arg || preg_match('/^\d{1,2}$/', $arg)) {
                $params['pallet_index'] = (int) ($arg ?? 1);
            } else {
                $params['pallet_code'] = $arg;
            }
        } elseif ($type === 'base') {
            $arg1 = $parts[1] ?? null;
            $rest = implode(' ', array_slice($parts, 2));
            if (! $arg1) {
                $params['pallet_index'] = 1;
            } elseif (preg_match('/^\d{1,2}$/', $arg1) && $rest) {
                $params['pallet_index'] = (int) $arg1;
                $params['base_name'] = $rest;
            } else {
                $params['pallet_index'] = 1;
                $params['base_name'] = implode(' ', array_slice($parts, 1));
            }
        } elseif ($type === 'ticket') {
            $arg = $parts[1] ?? null;
            if (! $arg || preg_match('/^\d{1,2}$/', $arg)) {
                $params['order_index'] = (int) ($arg ?? 1);
            } else {
                $params['order_code'] = $arg;
            }
        }

        return $params;
    }
}
