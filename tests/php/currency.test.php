<?php
declare(strict_types=1);
// A store that prices in another currency must never have its prices saved as dollars.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();

$dir = sys_get_temp_dir() . '/offgrid-currency-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);

// ---- the country a listing asks for
check('a link can ask for a country price', shopify_country('https://shop.example.com/products/x?country=US') === 'US' && shopify_country('https://shop.example.com/products/x?variant=1&country=gb') === 'GB');
check('no country, or a strange one, is no country', shopify_country('https://shop.example.com/products/x') === null && shopify_country('https://shop.example.com/products/x?country=USA') === null && shopify_country('https://shop.example.com/products/x?country[]=US') === null && shopify_country('https://shop.example.com/products/x?country=') === null);

// ---- reading the store's currency
$calls = 0;
$meta = static function (array $answers) use (&$calls) {
    return static function (string $url) use ($answers, &$calls): array {
        $calls++;
        return $answers[$url] ?? [404, ''];
    };
};
check('a Shopify store says its currency in meta.json', shop_currency('gbp.example.com', $dir, $meta(['https://gbp.example.com/meta.json'=>[200, json_encode(['currency'=>'GBP', 'country'=>'GB'])]])) === 'GBP');
check('a dollar store says USD', shop_currency('usd.example.com', $dir, $meta(['https://usd.example.com/meta.json'=>[200, '{"currency":"USD"}']])) === 'USD');
check('a store with no meta.json, or an odd answer, is not known', shop_currency('none.example.com', $dir, $meta([])) === null && shop_currency('junk.example.com', $dir, $meta(['https://junk.example.com/meta.json'=>[200, 'not json']])) === null && shop_currency('odd.example.com', $dir, $meta(['https://odd.example.com/meta.json'=>[200, '{"currency":"gbp pounds"}']])) === null);
$calls = 0;
shop_currency('gbp.example.com', $dir, $meta([]));
check('the answer is kept, so the store is asked once', $calls === 0);
check('the answer is also kept on disk for a day', is_file($dir . '/currency-' . hash('sha256', 'gbp.example.com') . '.json') && (json_decode((string)file_get_contents($dir . '/currency-' . hash('sha256', 'gbp.example.com') . '.json'), true)['expires'] ?? 0) > time() + 80000);
check('no answer now is not remembered as an answer', shop_currency('down.example.com', $dir, static function () { throw new ScanFailure('down'); }) === null && !is_file($dir . '/currency-' . hash('sha256', 'down.example.com') . '.json'));

// ---- the guard
$refuse = static function (string $host, string $url, array $answers) use ($dir, $meta): string {
    try { shop_currency_guard($host, $url, $dir, $meta($answers)); } catch (ScanFailure $e) { return $e->getMessage(); }
    return '';
};
$gbp = static fn(string $host) => ["https://$host/meta.json"=>[200, '{"currency":"GBP"}']];
$msg = $refuse('g1.example.com', 'https://g1.example.com/products/x', $gbp('g1.example.com'));
check('a pound store is refused, and the message says why and what to do', str_contains($msg, 'GBP') && str_contains($msg, '?country=US') && str_contains($msg, 'wrong'), $msg);
check('a pound store is allowed when the listing asks for its U.S. price', $refuse('g2.example.com', 'https://g2.example.com/products/x?country=US', $gbp('g2.example.com')) === '');
check('only the U.S. price is accepted for a store that is not in dollars', $refuse('g3.example.com', 'https://g3.example.com/products/x?country=GB', $gbp('g3.example.com')) !== '');
check('a dollar store is not refused', $refuse('d1.example.com', 'https://d1.example.com/products/x', ['https://d1.example.com/meta.json'=>[200, '{"currency":"USD"}']]) === '');
check('a store whose currency is not known is not refused', $refuse('u1.example.com', 'https://u1.example.com/products/x', []) === '');

// ---- adding by link
$product = static fn(int $price) => json_encode(['title'=>'Nibbler2 Case', 'vendor'=>'zerofox3D', 'description'=>'<p>A case.</p>', 'tags'=>[], 'variants'=>[['id'=>1, 'title'=>'Default Title', 'public_title'=>null, 'options'=>['Default Title'], 'price'=>$price, 'available'=>true]]]);
$store = static function (string $host, string $currency, ?string $usPageCurrency) use ($product) {
    return static function (string $url) use ($host, $currency, $usPageCurrency, $product): array {
        return match (true) {
            $url === "https://$host/meta.json" => [200, json_encode(['currency'=>$currency])],
            $url === "https://$host/products/case.js" => [200, $product(2399)],
            $url === "https://$host/products/case.js?country=US" => [200, $product(3600)],
            $url === "https://$host/products/case?country=US" => [200, $usPageCurrency === null ? '<html></html>' : '<script>Shopify.currency = {"active":"' . $usPageCurrency . '","rate":"1.33"};</script>'],
            default => [404, ''],
        };
    };
};
$items = [];
$us = addurl_read('https://gb-shop.example.com/products/case', $store('gb-shop.example.com', 'GBP', 'USD'), $items);
check('a pound store that has a U.S. price is read with that price', !empty($us['ok']) && $us['product']['variants'][0]['price'] === 3600, json_encode($us['product']['variants'] ?? $us));
check('the link it will save asks for the U.S. price, and the note says so', str_contains($us['url'], 'country=US') && str_contains($us['currency_note'] ?? '', 'GBP') && str_contains($us['currency_note'], 'U.S. market'));
$noUs = addurl_read('https://gb2-shop.example.com/products/case', $store('gb2-shop.example.com', 'GBP', 'GBP'), $items);
check('a pound store with no dollar price is refused, not read in pounds', empty($noUs['ok']) && ($noUs['reason'] ?? '') === 'currency' && str_contains($noUs['message'], 'GBP') && !isset($noUs['product']), json_encode($noUs));
$noPage = addurl_read('https://gb3-shop.example.com/products/case', $store('gb3-shop.example.com', 'GBP', null), $items);
check('a pound store whose U.S. page cannot be checked is refused too', ($noPage['reason'] ?? '') === 'currency');
$usd = addurl_read('https://us-shop.example.com/products/case', $store('us-shop.example.com', 'USD', null), $items);
check('a dollar store is read as before, with no note', !empty($usd['ok']) && $usd['product']['variants'][0]['price'] === 2399 && !isset($usd['currency_note']) && !str_contains($usd['url'], 'country'));
$unknown = addurl_read('https://nometa-shop.example.com/products/case', static fn(string $url) => str_contains($url, 'meta.json') ? [404, ''] : [200, $product(2399)], $items);
check('a store whose currency cannot be read is read as before', !empty($unknown['ok']));
$already = addurl_read('https://gb4-shop.example.com/products/case?country=US', $store('gb4-shop.example.com', 'GBP', 'USD'), $items);
check('a link that already asks for the U.S. price is not given the parameter twice', !empty($already['ok']) && substr_count($already['url'], 'country=') === 1 && $already['product']['variants'][0]['price'] === 3600, $already['url'] ?? '');
finish();
