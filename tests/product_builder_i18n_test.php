<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../scripts/product_builder/i18n.php';
function langAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$_GET=[];$_COOKIE=[];$_SESSION=['permission'=>900,'user_id'=>999010];
langAssert(pb_resolve_language()==='sk','Slovak is the default.');
$_GET=['pb_lang'=>'en'];langAssert(pb_resolve_language()==='en','Explicit English choice.');
$_COOKIE[pb_language_cookie()]='en';$_GET=['pb_lang'=>['sk']];langAssert(pb_resolve_language()==='en','Malformed language ignored.');
$_GET=[];$_SESSION['user_id']=999011;langAssert(pb_resolve_language()==='sk','Cookie is scoped to the user.');
$_SESSION['product_builder_languages'][999011]='en';langAssert(pb_resolve_language()==='en','Session fallback.');
$GLOBALS['pbLanguage']='sk';
foreach([
 'This SKU is already used by another product. Choose a unique SKU.'=>'Toto SKU už používa iný produkt. Zvoľte jedinečné SKU.',
 'Component name G: enter a value.'=>'Názov komponentu G: vyplňte hodnotu.',
 'Box name: maximum 150 characters.'=>'Názov krabice: najviac 150 znakov.',
 'Recommended price currency: maximum 3 characters.'=>'Mena – Odporúčaná cena: najviac 3 znakov.',
 'Length (cm): enter a non-negative number with up to 5 digits before and 2 digits after the decimal point.'=>'Dĺžka (cm): zadajte nezáporné číslo s najviac 5 číslicami pred a 2 číslicami za desatinnou čiarkou.',
 'CSV row 8: Brand: enter a value.'=>'CSV riadok 8: Značka: vyplňte hodnotu.',
 'Missing component of type S.'=>'Chýba komponent typu S.',
] as $source=>$expected)langAssert(pb_translate_error($source)===$expected,'Translated API message: '.$source);
langAssert(pb_t('Unknown custom label')==='Unknown custom label','Unknown keys fall back to English.');
langAssert(pb_t('Delete design {name}?',['name'=>'{name} <script>'])==='Vymazať dizajn {name} <script>?','Interpolation is one pass; output escaping belongs to renderers.');
$GLOBALS['pbLanguage']='en';langAssert(pb_translate_error('Box name: enter a value.')==='Box name: enter a value.','English API errors unchanged.');
require_once __DIR__.'/../includes/conn.php';
function localizedPage(string $mode,string $lang):string{
 global $pdo,$conn;
 $_SESSION=['permission'=>900,'user_id'=>999010];$_COOKIE=[];$_GET=['page'=>'product_'.$mode,'pb_lang'=>$lang];
 ob_start();require __DIR__.'/../includes/product_'.$mode.'.php';$body=ob_get_clean();
 return '<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="/darkscrub/"><link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css"><link rel="stylesheet" href="dist/css/adminlte.min.css"><script src="js/jquery-3.6.0.min.js"></script><script src="js/bootstrap.min.js"></script></head><body class="dark-mode" style="padding:24px;background:#343a40">'.$body.'<script src="plugins/jquery/jquery.min.js"></script><script src="plugins/jquery-ui/jquery-ui.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script></body></html>';
}
foreach(['builder','listings','controls'] as $mode)foreach(['sk','en'] as $lang){
 $html=localizedPage($mode,$lang);$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
 langAssert($xpath->query('//*[@data-pb-language]')->length===2,'Both language options: '.$mode);
 langAssert($xpath->query('//*[@id="product-builder"]')->item(0)->getAttribute('lang')===$lang,'Page language attribute.');
 langAssert(str_contains($html,'"language":"'.$lang.'"'),'Client language matches server.');
 if($mode==='builder')langAssert($xpath->query('//label[@for="pb-name"]')->item(0)->textContent===($lang==='sk'?'Názov produktu *':'Product name *'),'Static product label is localized.');
 if($mode==='builder')langAssert($xpath->query('//*[@id="pb-amazon-price"]')->item(0)->getAttribute('placeholder')===($lang==='sk'?'Nezadané':'Not set'),'Conditional price placeholder is localized.');
 if($mode==='controls')langAssert(str_contains($html,$lang==='sk'?'Dizajny grafiky':'Graphics designs'),'Tile text localized.');
 file_put_contents(__DIR__.'/../.cache/product-builder/i18n-'.$mode.'-'.$lang.'.html',$html);
}
echo "Product languages: default, per-user persistence, validation messages, interpolation and all three page renderings OK (no data writes).\n";
