<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Services;

final class PayloadSanitizer
{
    public function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '[truncated]';
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 512);
        }
        if (! is_array($value) && ! is_object($value)) {
            return mb_substr((string) $value, 0, 512);
        }

        $result = [];
        foreach (array_slice((array) $value, 0, 50, true) as $key => $item) {
            $key = mb_substr((string) $key, 0, 64);
            $result[$key] = preg_match('/pass|secret|token|authorization|cookie|credit.?card|card.?number|cvv|cvc|iban|account.?number|email|phone|address/i', $key)
                ? '[redacted]'
                : $this->sanitize($item, $depth + 1);
        }

        return $result;
    }
}
