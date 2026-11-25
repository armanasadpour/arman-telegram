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

    /** Add a row; returns its ".id" (e.g. "*1F"). @param array<string,string> $attrs */
    public function add(string $path, array $attrs): string;

    /** Change fields of one row. @param array<string,string> $attrs */
    public function set(string $path, string $id, array $attrs): void;

    /** Remove one row. */
    public function remove(string $path, string $id): void;
}
