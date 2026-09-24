<?php

namespace FolderFolio\Tests\Integration\Rest;

use WP_UnitTestCase;

/**
 * Every route the plugin registers is a row in docs/api/README.md, and every
 * row is a route — both ways, with its method.
 *
 * The 24 Sep alignment audit found fourteen routes the page never listed,
 * four of them aliases under a comment calling them unused while the rail
 * called three (record `…-24f-api-cli-alignment`).
 */
class RouteDocsTest extends WP_UnitTestCase
{
    /**
     * `/folderfolio/v1/folders/(?P<id>[\d]+)/move` → `/folders/{id}/move`.
     */
    private static function spelled(string $route): string
    {
        $route = (string) preg_replace('#^/folderfolio/v1#', '', $route);

        return (string) preg_replace('/\(\?P<(\w+)>[^)]*\)/', '{$1}', $route);
    }

    /**
     * @return list<string> "METHOD /route"
     */
    private static function documented(): array
    {
        $docs = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/api/README.md');
        preg_match_all('/^\| `(GET|POST|PATCH|PUT|DELETE)` \| `([^`?\s]+)/m', $docs, $m, PREG_SET_ORDER);

        return array_values(array_unique(array_map(
            static fn (array $row): string => $row[1] . ' ' . $row[2],
            $m
        )));
    }

    /** @test */
    public function the_page_and_the_server_list_the_same_routes(): void
    {
        $documented = self::documented();
        $this->assertGreaterThan(40, count($documented), 'the pattern still reads the table');

        $registered = [];
        $undocumented = [];

        foreach (rest_get_server()->get_routes('folderfolio/v1') as $route => $handlers) {
            $path = self::spelled($route);

            if ($path === '' || $path === '/') {
                continue; // the namespace index WordPress adds
            }

            foreach ($handlers as $handler) {
                $methods = array_keys(array_filter((array) $handler['methods']));

                foreach ($methods as $method) {
                    $registered[] = "{$method} {$path}";
                }

                // A handler is documented when one of its methods is: an
                // EDITABLE route answers POST, PUT and PATCH, and the page
                // names the one a client should send.
                $named = array_filter($methods, static fn (string $m): bool => in_array("{$m} {$path}", $documented, true));

                if ($named === []) {
                    $undocumented[] = implode('|', $methods) . ' ' . $path;
                }
            }
        }

        // Unique: rest_get_server() fires rest_api_init itself, so each
        // handler is listed once per registration.
        $this->assertSame([], array_values(array_unique($undocumented)), 'registered, not on the page');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'on the page, not registered');
    }
}
