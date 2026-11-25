<?php

namespace App\RouterOs;

interface Client
{
    /**
     * Read a menu ("system/resource", "ppp/secret", …).
     *
     * @param  array<string,string>  $query  exact-match filters (field => value)
     * @return list<array<string,string>> rows (singleton menus return one row)
     */
    public function get(string $path, array $query = []): array;
}
