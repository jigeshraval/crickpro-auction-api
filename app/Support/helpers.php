<?php

if (! function_exists('paginated')) {
    function paginated($items, $itemName = 'items', $simple = false)
    {
        if ($simple) {
            $currentPage = $items->currentPage();

            return [
                'currentPage' => $currentPage,
                'nextPage' => $items->hasMorePages() ? $currentPage + 1 : null,
                $itemName => $items->items(),
            ];
        }

        return [
            'total' => $items->total(),
            'currentPage' => $items->currentPage(),
            'nextPage' => $items->currentPage() < $items->lastPage() ? ($items->currentPage() + 1) : null,
            'lastPage' => $items->lastPage(),
            $itemName => $items->items(),
        ];
    }
}

if (! function_exists('cfCountry')) {
    /**
     * Visitor's country (ISO2) from Cloudflare's CF-IPCountry edge header — the
     * same CF-header source crickpro-api-v2 uses. Null off-Cloudflare or for
     * CF's non-country codes (XX unknown, T1 Tor).
     */
    function cfCountry(): ?string
    {
        $code = strtoupper(trim((string) (request()?->header('CF-IPCountry') ?? '')));

        return preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, ['XX', 'T1'], true) ? $code : null;
    }
}

if (! function_exists('ipAddress')) {
    function ipAddress(): string
    {
        return request()?->ip() ?? '127.0.0.1';
    }
}
