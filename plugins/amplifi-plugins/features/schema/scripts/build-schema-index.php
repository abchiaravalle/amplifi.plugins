<?php
/**
 * Build script — fetches schema.org's published vocabulary and produces
 * a lean type/property index for the amplifi.schema Registry to consume.
 *
 * Run manually (or on plugin upgrade) with:
 *   php scripts/build-schema-index.php
 *
 * Output: includes/schema/data/schema-org-types.json
 */

declare(strict_types=1);

const VOCAB_URL = 'https://schema.org/version/latest/schemaorg-current-https.jsonld';
const OUTPUT_PATH = __DIR__ . '/../includes/schema/data/schema-org-types.json';

const REQUIRED_FOR_RICH_RESULTS = [
    'Article'        => ['headline', 'author', 'datePublished', 'image'],
    'BlogPosting'    => ['headline', 'author', 'datePublished', 'image'],
    'NewsArticle'    => ['headline', 'author', 'datePublished', 'image'],
    'Product'        => ['name', 'image', 'offers'],
    'FAQPage'        => ['mainEntity'],
    'Event'          => ['name', 'startDate', 'location'],
    'Recipe'         => ['name', 'image', 'recipeIngredient', 'recipeInstructions'],
    'HowTo'          => ['name', 'step'],
    'LocalBusiness'  => ['name', 'address', 'telephone'],
    'Course'         => ['name', 'description', 'provider'],
    'BreadcrumbList' => ['itemListElement'],
    'VideoObject'    => ['name', 'description', 'thumbnailUrl', 'uploadDate'],
    'Person'         => ['name'],
];

function fetch_vocab(): array {
    $body = @file_get_contents(VOCAB_URL);
    if ($body === false) {
        fwrite(STDERR, "Failed to fetch " . VOCAB_URL . "\n");
        exit(1);
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['@graph'])) {
        fwrite(STDERR, "Vocab response missing @graph\n");
        exit(1);
    }
    return $data['@graph'];
}

/**
 * Normalise a schema.org ID to its bare local name.
 * Handles both compact form (schema:Article) and full IRI (https://schema.org/Article).
 * Returns null for IDs that belong to external vocabularies (e.g. bibo:, cmns-*).
 */
function short_id(string $id): ?string {
    // Full IRI form: https://schema.org/Foo or http://schema.org/Foo
    if (preg_match('#^https?://schema\.org/(.+)$#', $id, $m)) {
        return $m[1];
    }
    // Compact form: schema:Foo
    if (str_starts_with($id, 'schema:')) {
        return substr($id, 7);
    }
    // External vocabulary — skip
    return null;
}

function as_array($v): array {
    if ($v === null) return [];
    return array_values(array_filter(is_array($v) && array_is_list($v) ? $v : [$v]));
}

function build_index(array $graph): array {
    $types = [];        // [name => ['parents' => string[]]]
    $type_props = [];   // [type_name => [prop_name, ...]]

    foreach ($graph as $node) {
        $id = $node['@id'] ?? null;
        $atype = $node['@type'] ?? null;
        if (!$id || !$atype) continue;

        $name = short_id($id);
        if ($name === null) continue;

        $atypes = is_array($atype) ? $atype : [$atype];

        if (in_array('rdfs:Class', $atypes, true)) {
            $parents = [];
            if (isset($node['rdfs:subClassOf'])) {
                $sub = is_array($node['rdfs:subClassOf']) && isset($node['rdfs:subClassOf']['@id'])
                    ? [$node['rdfs:subClassOf']]
                    : $node['rdfs:subClassOf'];
                if (is_array($sub)) {
                    foreach ($sub as $p) {
                        $pid = is_array($p) ? ($p['@id'] ?? null) : null;
                        if ($pid) {
                            $pname = short_id($pid);
                            if ($pname !== null) { $parents[] = $pname; }
                        }
                    }
                }
            }
            $types[$name] = ['parents' => $parents];
        }

        if (in_array('rdf:Property', $atypes, true)) {
            $domain = $node['schema:domainIncludes'] ?? $node['domainIncludes'] ?? null;
            $domains = [];
            if (is_array($domain)) {
                if (isset($domain['@id'])) $domains = [$domain['@id']];
                else foreach ($domain as $d) if (is_array($d) && isset($d['@id'])) $domains[] = $d['@id'];
            }
            foreach ($domains as $d) {
                $t = short_id((string)$d);
                if ($t !== null) $type_props[$t][] = $name;
            }
        }
    }

    // Walk ALL parent chains (BFS) to flatten properties.
    $result = [];
    foreach ($types as $name => $meta) {
        $props = [];
        $queue = [$name];
        $visited = [];
        while ($queue) {
            $cursor = array_shift($queue);
            if (isset($visited[$cursor])) continue;
            $visited[$cursor] = true;
            foreach ($type_props[$cursor] ?? [] as $p) {
                $props[$p] = true;
            }
            foreach ($types[$cursor]['parents'] ?? [] as $par) {
                if (!isset($visited[$par])) { $queue[] = $par; }
            }
        }
        $result[$name] = [
            'parent' => $meta['parents'][0] ?? null,
            'properties' => array_keys($props),
            'required_for_rich_results' => REQUIRED_FOR_RICH_RESULTS[$name] ?? [],
        ];
    }
    ksort($result);
    return $result;
}

$graph = fetch_vocab();
$index = build_index($graph);

if (!is_dir(dirname(OUTPUT_PATH))) {
    mkdir(dirname(OUTPUT_PATH), 0755, true);
}
file_put_contents(
    OUTPUT_PATH,
    json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

printf("Wrote %s (%d types, %s)\n",
    OUTPUT_PATH,
    count($index),
    number_format(filesize(OUTPUT_PATH))
);
