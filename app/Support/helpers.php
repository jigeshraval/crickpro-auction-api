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

if (! function_exists('ipAddress')) {
    function ipAddress(): string
    {
        return request()?->ip() ?? '127.0.0.1';
    }
}
