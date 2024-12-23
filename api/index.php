<?php

namespace Lens;

require '../vendor/autoload.php';

use Phpfastcache\Helper\Psr16Adapter;

$Psr16Adapter = new Psr16Adapter('Files');

$url = $_GET['url'];
$domain = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
$host = parse_url($url, PHP_URL_HOST);
ini_set('user_agent', 'Lens by Robb Knight; https://lens.rknight.me');

$cacheKey = md5($url);

header("Access-Control-Allow-Origin: https://lens.rknight.me");
header('Content-Type: application/json; charset=utf-8');

if ($Psr16Adapter->has($cacheKey)) {
    echo json_encode($Psr16Adapter->get($cacheKey));
    die;
}

try {
    $contents = @file_get_contents($url);

    $document = new \DOMDocument();
    @$document->loadHTML($contents);
} catch (\Throwable $e) {
    echo json_encode([
        'error' => 'Unable to fetch URL',
    ]);
    die;
}

$titleElements = $document->getElementsByTagName('title');
$metaElements = $document->getElementsByTagName('meta');
$linkElements = $document->getElementsByTagName('link');
$aElements = $document->getElementsByTagName('a');

$normaliseUrl = function($path) use ($domain) {
    if (strpos($path, 'http') === 0) return $path;
    if (strpos($path, '/') === 0) return $domain . $path;
    return $domain . '/' . $path;
};

$site = [
    'domain' => $domain,
    'url' => $url,
    'host' => $host,

    'title' => null,
    'description' => null,
    'image' => null,
    'fediverse' => null,
    'icon' => null,
    'homeIcon' => null,
    'themeColor' => null,

    'found' => [],
    'feeds' => [],
    'relme' => [],
];

$data = [
    'charset' => null,
    'domain' => $domain,
    'url' => $url,
    'og:url' => null,

    'title' => null,
    'og:title' => null,
    
    'description' => null,
    'og:description' => null,
    'og:image' => null,
    
    'icon' => null,
    'apple-touch-icon' => null,
    'theme-color' => null,
    
    'fediverse:creator' => null,
    
    'generator' => null,

    'blogroll' => [],

    'feeds' => [],

    'relme' => [],
];

foreach (iterator_to_array($titleElements) as $el) {
    $data['title'] = $el->nodeValue;
    $site['found'][] = 'title';
}

foreach (iterator_to_array($metaElements) as $mel) {
    $name = $mel->getAttribute('name');
    $property = $mel->getAttribute('property');
    $content = $mel->getAttribute('content');

    if ($mel->getAttribute('charset')) {
        $site['charset'] = $mel->getAttribute('charset');
        $site['found'][] = 'charset';
    }

    if ($property === 'og:title' || $name === 'og:title') {
        $data['og:title'] = $content;
        $site['found'][] = 'og:title';
    }
    if ($name === 'description') {
        $data['description'] = $content;
        $site['found'][] = 'description';
    }
    if ($property === 'og:description' || $name === 'og:description') {
        $data['og:description'] = $content;
        $site['found'][] = 'og:description';
    }
    if ($property === 'og:image' || $name === 'og:image') {
        $data['og:image'] = $normaliseUrl($content);
        $site['found'][] = 'og:image';
    }

    if ($property === 'fediverse:creator') {
        $data['fediverse:creator'] = $content;
        $site['found'][] = 'fediverse:creator';
    }

    if ($name === 'fediverse:creator') {
        $data['fediverse:creator'] = $content;
        $site['found'][] = 'fediverse:creator';
    }

    if ($name === 'generator') {
        $data['generator'] = $content;
        $site['found'][] = 'generator';
    }
    if ($name === 'theme-color') {
        $data['theme-color'] = $content;
        $site['found'][] = 'theme-color';
    }

    if ($property === 'og:url') {
        $data['og:url'] = $normaliseUrl($content);
        $site['found'][] = 'og:url';
    }
}

foreach (iterator_to_array($linkElements) as $link) {
    $type = $link->getAttribute('type');
    $href = $normaliseUrl($link->getAttribute('href'));
    $title = $link->getAttribute('title');
    $rel = $link->getAttribute('rel');

    match ($type) {
        'application/rss+xml' => $data['feeds'][] = [ 'title' => $title, 'href' => $href, 'type' => 'rss' ],
        'application/atom+xml' => $data['feeds'][] = [ 'title' => $title, 'href' => $href, 'type' => 'atom' ],
        'application/json' => $data['feeds'][] = [ 'title' => $title, 'href' => $href, 'type' => 'json' ],
        default => null,
    };
    
    if ($type === 'image/x-icon') {
        $data['icon'] = $href;
        $site['found'][] = 'icon';
    }
    if ($rel === 'icon') {
        $data['icon'] = $href;
        $site['found'][] = 'icon';
    }
    if ($rel === 'apple-touch-icon') {
        $data['apple-touch-icon'] = $href;
        $site['found'][] = 'apple-touch-icon';
    }
    if ($rel === 'blogroll') $data['blogroll'][] = [ 'title' => $title, 'href' => $href ];
}

if (is_null($data['icon'])) {
    $faviconPath = $domain . '/favicon.ico';
    $headers = get_headers($faviconPath, true);
    $faviconExists = str_contains($headers[0] ?? '', '200');

    $data['icon'] = $faviconExists ? $faviconPath : null;
    $site['found'][] = 'icon';
}


foreach (iterator_to_array($aElements) as $a) {
    $rel = $a->getAttribute('rel');
    $href = $normaliseUrl($a->getAttribute('href'));
    if ($rel === 'me') $data['relme'][] = $href;
}

$site['charset'] = $data['charset'];
$site['title'] = $data['og:title'] ?? $data['title'];
$site['description'] = $data['og:description'] ?? $data['description'];
$site['image'] = $data['og:image'];
$site['fediverse'] = $data['fediverse:creator'];
$site['feeds'] = $data['feeds'];
$site['icon'] = $data['icon'];
$site['themeColor'] = $data['theme-color'];
$site['homeIcon'] = $data['apple-touch-icon'];
$site['relme'] = $data['relme'];

$ttl = 60 * 2;

if (in_array($url, [
    'https://rknight.me',
    'https://gkeenan.co',
    'https://localghost.dev'])
) {
    $ttl = 60 * 15;
}

$Psr16Adapter->set($cacheKey, $site, $ttl);

echo json_encode($site);
die;