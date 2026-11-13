<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../scripts/product_builder/service.php';
function channelAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function channelReject(callable $call):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid channel update accepted.');}
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components','product_builder_channel_listings'] as $table){$ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));}
$pdo->exec("INSERT INTO product_builder_uni_categories(brand,category_name,modelcode,included_models) VALUES ('KTM','All','ZA23','Models')");
$base=['name'=>'Channel listing','sku'=>'CHANNEL-TEST','recommended_price'=>'99','web_price'=>'89.90','web_currency'=>'EUR','ebay_price'=>'109.90','ebay_currency'=>'USD','bundle_type'=>'G','category_kind'=>'universal','uni_category_id'=>(int)$pdo->lastInsertId(),'request_key'=>bin2hex(random_bytes(16)),'notes'=>'Keep notes','components'=>[['component_type'=>'G','label'=>'Graphics','quantity'=>1,'options'=>['graphics_number'=>'Yes']]]];
$id=(int)pb_save_product($pdo,$base,null)['id'];
$read=fn()=>pb_channel_listings($pdo,[$id])[$id];
$readProduct=fn()=>$pdo->query('SELECT * FROM product_builder_products WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
$before=$readProduct();$components=$pdo->query('SELECT * FROM product_builder_components')->fetchAll(PDO::FETCH_ASSOC);
foreach($read() as $channel=>$row)channelAssert($row['status']==='do_not_list'&&$row['version']===0&&$row['price']===null,'Unconfigured channel defaults are explicit.');
channelAssert(pb_channel_listings($pdo,[])===[],'Empty overview needs no query.');
$save=fn($channel,$version,$status,$extra=[])=>pb_save_channel_listing($pdo,array_replace(['product_id'=>$id,'channel'=>$channel,'version'=>$version,'status'=>$status,'listed_date'=>'2026-10-29'],$extra),7);
$save('web',0,'list');$save('ebay',0,'list');
$url='https://shop.example.test/product/example%20kit?variant=AT&source=team';
$save('web',1,'listed',['price'=>'149,90','currency'=>'GBP','url'=>$url]);
channelAssert($read()['web']['price']==='149.90'&&$read()['web']['currency']==='GBP'&&$read()['web']['url']===$url,'Listed amount, currency and URL round-trip.');
$save('ebay',1,'listed',['price'=>'0','currency'=>'USD','url'=>'http://example.test/free']);
channelAssert($read()['ebay']['price']==='0.00'&&$read()['amazon']['version']===0,'Zero price is allowed and channels are independent.');
$snapshot=$read();channelReject(fn()=>$save('web',1,'do_not_list'));channelAssert($read()===$snapshot,'Stale writer cannot overwrite a newer channel.');
$save('web',2,'do_not_list');channelAssert($read()['web']['url']===$url&&$read()['web']['price']==='149.90','Pausing retains last listing details.');
$save('web',3,'list');channelAssert($read()['web']['url']===$url,'Scheduling again retains last listing details.');
$save('web',4,'listed',['price'=>'150','currency'=>'AUD','url'=>$url]);
$valid=['listed_date'=>'2026-10-29','price'=>'10','currency'=>'EUR','url'=>'https://example.test/product'];
channelAssert($read()['web']['listed_date']==='2026-10-29','Listing date survives status changes and relisting.');
foreach(['','2026-02-30','29.10.2026','2026-1-01','0999-12-31',"2026-1\x00-01",[]] as $bad)channelReject(fn()=>$save('amazon',0,'listed',array_replace($valid,['listed_date'=>$bad])));
foreach(['','-1','1.234','100000000',[],"1e2"] as $bad)channelReject(fn()=>$save('amazon',0,'listed',array_replace($valid,['price'=>$bad])));
foreach(['','XXX',[], 'EURO'] as $bad)channelReject(fn()=>$save('amazon',0,'listed',array_replace($valid,['currency'=>$bad])));
foreach(['','javascript:alert(1)','data:text/html,test','file:///C:/x','https://user:pass@example.test/p','https://example.test/a b',"https://example.test/\npath",str_repeat('a',2049),[]] as $bad)channelReject(fn()=>$save('amazon',0,'listed',array_replace($valid,['url'=>$bad])));
foreach(['invalid','',[],null,true] as $bad)channelReject(fn()=>pb_save_channel_listing($pdo,['product_id'=>$id,'channel'=>'web','version'=>5,'status'=>$bad],null));
channelReject(fn()=>pb_save_channel_listing($pdo,['product_id'=>$id,'channel'=>'recommended','version'=>0,'status'=>'list'],null));
channelReject(fn()=>pb_save_channel_listing($pdo,['product_id'=>$id,'channel'=>'amazon','status'=>'list'],null));
channelReject(fn()=>pb_save_channel_listing($pdo,['product_id'=>999999,'channel'=>'web','version'=>0,'status'=>'list'],null));
channelAssert($read()['amazon']['version']===0,'Rejected changes create no channel records.');
channelAssert($readProduct()===$before,'Channel writes preserve product, suggested prices and product revision.');
channelAssert($pdo->query('SELECT * FROM product_builder_components')->fetchAll(PDO::FETCH_ASSOC)===$components,'Channel writes preserve components.');
$edit=$base+['id'=>$id,'version'=>1];$edit['name']='Edited draft';$edit['web_price']='80';pb_save_product($pdo,$edit,null);
channelAssert($read()['web']['price']==='150.00'&&$read()['web']['status']==='listed','Saving an open product form preserves actual listing data.');
$overview=pb_product_overview($pdo)['rows'][0];channelAssert($overview['channel_listings']===$read()&&$overview['web_price']==='80.00','Overview returns suggested and listed prices separately.');
echo "Channel persistence: defaults, states, independent currencies, zero, URL validation, stale writes, preservation and product editing OK (temporary tables only).\n";
