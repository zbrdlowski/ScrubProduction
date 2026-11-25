<?php
declare(strict_types=1);
// CLI-only tests and static HTML fixtures; never create an authenticated web session.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../scripts/product_builder/access.php';
function pagesAssert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
foreach(['auth_employee_overrides','auth_employee_roles','auth_role_permissions','auth_permissions','auth_roles'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);
    $pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));
}
$pdo->exec("INSERT INTO auth_permissions(permission_key,module_key,label,is_active) VALUES ('products.view','Products','View',1),('products.manage','Products','Manage',1)");
$permissionIds=$pdo->query('SELECT permission_key,id FROM auth_permissions')->fetchAll(PDO::FETCH_KEY_PAIR);
$grant=$pdo->prepare("INSERT INTO auth_employee_overrides(employee_id,permission_id,effect) VALUES (?,?,'allow')");
foreach([[999002,'products.view'],[999003,'products.view'],[999003,'products.manage'],[999004,'products.manage']] as [$id,$key])$grant->execute([$id,$permissionIds[$key]]);
$_SESSION=[];
foreach(['products','product','transfers','transfer'] as $action)pagesAssert(!pb_can_access_api('GET',$action),'Anonymous users cannot read: '.$action);
pagesAssert(!pb_can_manage_products()&&!pb_can_prepare_transfers(),'Anonymous users cannot write.');
foreach(['save_transfer_image','save_transfer_folder'] as $action)pagesAssert(!pb_can_access_api('POST',$action),'Anonymous users cannot prepare transfers: '.$action);
pagesAssert(auth_page_permission('product_listings')===null,'Listings is available to all signed-in employees.');
pagesAssert(auth_page_permission('product_builder')==='products.view' && auth_page_permission('product_controls')==='products.view','Builder and Controls retain explicit access.');
foreach([999001=>[false,false],999002=>[true,false],999003=>[true,true],999004=>[false,false]] as $id=>[$view,$manage]) {
    $_SESSION=['permission'=>100,'user_id'=>$id,'name'=>'Test user'];
    foreach(['products','product','transfers','transfer'] as $action)pagesAssert(pb_can_access_api('GET',$action),'All signed-in users can read: '.$action);
    foreach(['categories','designs','boxes','models','model_catalogue','plastic_item','transfer_draft','transfer_products'] as $action)pagesAssert(pb_can_access_api('GET',$action)===$view,'Protected library/inventory read: '.$action);
    foreach(['delete_product','restore_product','save_transfer','save_product','save_product_image','save_channel_listing','save_uni','delete_uni','import_uni','generate_modelcode','generate_sku','save_design','delete_design','save_box','delete_box','save_graphics_version','delete_graphics_version'] as $action)pagesAssert(pb_can_access_api('POST',$action)===$manage,'Protected write: '.$action);
    foreach(['products','product','transfers','transfer'] as $action)pagesAssert(pb_can_access_api('POST',$action)===$manage,'Read whitelist must not grant POST access: '.$action);
    pagesAssert(pb_can_prepare_transfers(),'Every signed-in user can prepare transfers.');
    foreach(['save_transfer_image','save_transfer_folder'] as $action)pagesAssert(pb_can_access_api('POST',$action),'All signed-in users can prepare transfers: '.$action);
    pagesAssert(pb_can_manage_products()===$manage,'Editor permission requires both Builder access and manage.');
}
function renderProductPage(string $name,array $session,array $query=[]): string {
    global $pdo,$conn;
    $_SESSION=$session;$_GET=$query+['pb_lang'=>'en'];http_response_code(200);
    ob_start();require __DIR__.'/../includes/'.$name.'.php';return ob_get_clean();
}
function wrapProductPage(string $html): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="/darkscrub/"><link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css"><link rel="stylesheet" href="dist/css/adminlte.min.css"><script src="js/jquery-3.6.0.min.js"></script><script src="js/bootstrap.min.js"></script></head><body class="dark-mode" style="padding:24px;background:#343a40">'.$html.'<script src="plugins/jquery/jquery.min.js"></script><script src="plugins/jquery-ui/jquery-ui.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script></body></html>';
}
$normal=['permission'=>100,'user_id'=>999001,'name'=>'Test reader'];$editor=['permission'=>100,'user_id'=>999003,'name'=>'Test editor'];$viewer=['permission'=>100,'user_id'=>999002,'name'=>'Test viewer'];
$readerHtml=renderProductPage('product_listings',$normal);
pagesAssert(str_contains($readerHtml,'id="pb-products"')&&!str_contains($readerHtml,'id="pb-product-form"')&&!str_contains($readerHtml,'id="pb-uni-modal"')&&!str_contains($readerHtml,'class="pb-stats"'),'Listings contains only the listing interface and detail modal.');
pagesAssert(str_contains($readerHtml,'"canEdit":false'),'Regular reader has no edit capability.');
$editorHtml=renderProductPage('product_listings',$editor);pagesAssert(str_contains($editorHtml,'"canEdit":true')&&!str_contains($editorHtml,'id="pb-product-form"'),'Editors still use the separate Builder page.');
pagesAssert(!str_contains($readerHtml,'id="pb-show-deleted"')&&str_contains($editorHtml,'id="pb-show-deleted"'),'Deleted-products filter is available only to managers.');
$controlsHtml=renderProductPage('product_controls',$editor);pagesAssert(substr_count($controlsHtml,'class="pb-control-tile ')===5,'Five management tiles are rendered.');
pagesAssert(!str_contains($controlsHtml,'id="pb-products"')&&!str_contains($controlsHtml,'id="pb-product-form"'),'Controls has no product editor or listing table.');
foreach(['pb-uni-form','pb-design-form','pb-box-form','pb-import-form'] as $id)pagesAssert(str_contains($controlsHtml,'id="'.$id.'"'),'Management form is available: '.$id);
$viewerControls=renderProductPage('product_controls',$viewer);pagesAssert(!str_contains($viewerControls,'id="pb-uni-form"')&&!str_contains($viewerControls,'id="pb-design-form"')&&!str_contains($viewerControls,'id="pb-box-form"'),'View-only access cannot edit libraries.');
$blocked=renderProductPage('product_controls',$normal);pagesAssert(!str_contains($blocked,'pb-controls-grid'),'Controls does not bypass products.view.');
$builderHtml=renderProductPage('product_builder',$editor,['edit'=>'801']);pagesAssert(str_contains($builderHtml,'"editProductId":801')&&str_contains($builderHtml,'id="pb-product-form"')&&str_contains($builderHtml,'data-design-manager="G"'),'Builder keeps library buttons and accepts direct edit links.');
$badEdit=renderProductPage('product_builder',$editor,['edit'=>'801<script>']);pagesAssert(str_contains($badEdit,'"editProductId":0'),'Invalid edit parameters are not used.');
$viewerBuilder=renderProductPage('product_builder',$viewer,['edit'=>'801']);pagesAssert(str_contains($viewerBuilder,'"editProductId":0')&&!str_contains($viewerBuilder,'id="pb-product-form"'),'Read-only users cannot open a deep-link editor.');
$target=__DIR__.'/../.cache/product-builder';
foreach(['listings-reader'=>$readerHtml,'listings-editor'=>$editorHtml,'controls-editor'=>$controlsHtml,'controls-viewer'=>$viewerControls,'builder-deeplink'=>$builderHtml] as $name=>$html)file_put_contents($target.'/'.$name.'.html',wrapProductPage($html));
$transferHtml=renderProductPage('product_transfers',$editor);
pagesAssert(auth_page_permission('product_transfers')===null,'Transfer D-base is available to every signed-in employee.');
pagesAssert(str_contains($transferHtml,'id="pb-products"')&&!str_contains($transferHtml,'id="pb-product-form"')&&!str_contains($transferHtml,'id="pb-transfer-modal"'),'Transfers reuse the listing table without the old modal or builder.');
pagesAssert(!str_contains($builderHtml,'id="pb-transfers"')&&!str_contains($builderHtml,'id="pb-transfer-modal"'),'Builder no longer contains the preparation queue or modal.');
pagesAssert(!str_contains(renderProductPage('product_transfers',[]),'id="pb-products"')&&http_response_code()===401,'Transfer D-base still requires signing in.');
$transferReader=renderProductPage('product_transfers',$normal);
pagesAssert(str_contains($transferReader,'id="pb-products"')&&str_contains($transferReader,'"canEdit":false')&&str_contains($transferReader,'"canPrepareTransfers":true')&&http_response_code()===200,'Users without product permissions can prepare transfers without gaining product editing access.');
$transferDraft=renderProductPage('product_builder',$editor,['transfer'=>61]);
file_put_contents($target.'/transfers-editor.html',wrapProductPage($transferHtml));
file_put_contents($target.'/transfers-viewer.html',wrapProductPage($transferReader));
file_put_contents($target.'/builder-transfer.html',wrapProductPage($transferDraft));
// Render the actual navigation, checking links without creating sessions or changing roles.
foreach(['reader'=>$normal,'editor'=>$editor] as $name=>$session) {
    $_SESSION=$session;ob_start();include __DIR__.'/../includes/navbar.php';$nav=ob_get_clean();
    $doc=new DOMDocument();@$doc->loadHTML($nav);$xpath=new DOMXPath($doc);
    $links=$xpath->query('//*[@aria-labelledby="navbarProductDatabase"]/a');$labels=[];foreach($links as $link)$labels[]=trim($link->textContent);
    pagesAssert($labels===($name==='editor'?['Product Builder','Listings','Transfer D-base','Product Controls']:['Listings','Transfer D-base']),'Navbar respects page access for '.$name);
    pagesAssert(!str_contains($nav,'page=product_listing_catalog'),'Retired catalogue is absent from the navbar.');
    // Isolate the actual dropdown markup for a static Bootstrap interaction fixture.
    $menu=$xpath->query('//*[@id="navbarProductDatabase"]/parent::*')->item(0);
    file_put_contents($target.'/navigation-'.$name.'.html',wrapProductPage('<ul class="nav">'.$doc->saveHTML($menu).'</ul>'));
}
echo "Product pages: signed-in listing/transfer reads, protected writes/libraries, read-only pages, Controls forms, direct edit links and role-aware navigation OK (temporary permission tables only).\n";
