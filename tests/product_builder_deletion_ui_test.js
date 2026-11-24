const {chromium}=require('playwright'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
const errors=[],calls=[];let rejectNext=false;
let row={id:801,version:1,request_key:'a'.repeat(32),name:'Original plastics kit',sku:'P_DELETE_FIXTURE',bundle_type:'P',category_kind:'universal',uni_category_id:11,brand:'KTM',model:'Default',modelcode:'AB23',year_range:'',notes:'Saved note',recommended_price:'149.90',recommended_currency:'EUR',web_currency:'EUR',ebay_currency:'USD',amazon_currency:'EUR',img_status:'Path',img_path:'Y:/Images with spaces',img_version:1,created_at:'2026-11-24 12:00:00',deleted_at:null,component_count:1,components:[{component_type:'P',label:'Plastics',reference:'TEST_CODE',color:'Orange',quantity:1,options:{}}],channel_listings:{web:{status:'listed',url:'https://example.test/product',price:'159.90',currency:'EUR',version:1}}};
const handler=async route=>{const req=route.request(),url=new URL(req.url()),action=url.searchParams.get('action'),data=req.method()==='POST'?JSON.parse(req.postData()):null;let body={ok:true};
if(action==='categories')body.rows=[{id:11,brand:'KTM',category_name:'Default',modelcode:'AB23',included_models:'All'}];
else if(action==='designs')Object.assign(body,{rows:[],versions:[]});
else if(action==='boxes')body.rows=[];
else if(action==='product')body.product=row;
else if(action==='products'){const deleted=url.searchParams.get('status')==='deleted';Object.assign(body,{rows:!!row.deleted_at===deleted?[row]:[],stats:{total:row.deleted_at?0:1}})}
else if(action==='delete_product'||action==='restore_product'){
 calls.push({action,data});assert.equal(data.id,801);assert.equal(data.version,row.version);
 if(rejectNext){rejectNext=false;return route.fulfill({status:409,json:{ok:false,error:'Concurrent change fixture'}})}
 row={...row,deleted_at:action==='delete_product'?'2026-11-24 13:00:00':null,version:row.version+1,img_version:row.img_version+1,channel_listings:{web:{...row.channel_listings.web,version:row.channel_listings.web.version+1}}};body.product={id:801,version:row.version,deleted:!!row.deleted_at};
}else throw Error('Unexpected API action: '+action);
await route.fulfill({json:body});};
const makePage=async name=>{const p=await browser.newPage({viewport:{width:1280,height:1000}});p.on('pageerror',e=>errors.push(e.message));p.on('dialog',d=>d.accept());await p.route('**/scripts/product_builder/api.php?*',handler);await p.goto('http://localhost/darkscrub/.cache/product-builder/'+name+'.html');return p};
const page=await makePage('builder-deeplink');await page.waitForFunction(()=>!document.getElementById('pb-delete-product').hidden&&!document.getElementById('pb-product-form').inert);
assert.equal(await page.locator('.pb-products-table>thead>tr>th').count(),19);
await page.fill('#pb-name','Unsaved draft name');
const shown=async()=>page.evaluate(()=>new Promise(resolve=>{const m=document.getElementById('pb-product-state-modal');if(m.classList.contains('show')&&!m.classList.contains('fade'))return resolve();window.jQuery(m).one('shown.bs.modal',resolve);document.getElementById('pb-delete-product').click()}));
await shown();assert.equal(await page.locator('#pb-product-state-name').textContent(),'Original plastics kit');assert.equal(await page.locator('#pb-product-state-sku').textContent(),'P_DELETE_FIXTURE');
await page.locator('#pb-product-state-modal [data-dismiss="modal"]').last().click();await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));assert.equal(calls.length,0);assert.equal(await page.locator('#pb-name').inputValue(),'Unsaved draft name');
rejectNext=true;await shown();await page.locator('#pb-product-state-confirm').click();await page.waitForFunction(()=>document.getElementById('pb-product-state-message').textContent==='Concurrent change fixture'&&!document.getElementById('pb-product-form').inert);
assert.equal(row.deleted_at,null);assert.equal(await page.locator('#pb-name').inputValue(),'Unsaved draft name');
await page.locator('#pb-product-state-confirm').click();await page.waitForFunction(()=>document.getElementById('pb-message').textContent.startsWith('Product deleted.')&&!document.querySelector('.modal-backdrop'));
assert.equal(await page.locator('#pb-name').inputValue(),'');assert.equal(await page.locator('#pb-delete-product').isVisible(),false);assert.equal(new URL(page.url()).searchParams.has('edit'),false);assert.equal(await page.locator('.pb-product-row').count(),0);
await page.check('#pb-show-deleted');await page.locator('[data-product-restore="801"]').waitFor();assert.equal(await page.locator('.pb-deleted-badge').isVisible(),true);
assert.equal(await page.locator('[data-img-select]').isDisabled(),true);for(const select of await page.locator('[data-channel-select]').all())assert.equal(await select.isDisabled(),true);assert.equal(await page.locator('[data-channel-edit]').count(),0);
await page.locator('[data-img-path]').click();await page.locator('#pb-img-modal.show').waitFor();assert.equal(await page.locator('#pb-img-path-input').getAttribute('readonly'),'');assert.equal(await page.locator('#pb-img-save').isVisible(),false);
await page.evaluate(()=>new Promise(resolve=>{const m=document.getElementById('pb-img-modal');window.jQuery(m).one('shown.bs.modal',resolve);if(getComputedStyle(m).display==='none')resolve();setTimeout(resolve,350)}));
await page.locator('#pb-img-modal .close').click();await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));
await page.locator('[data-product-expand="801"]').click();await page.locator('[data-component-detail] .pb-detail-section').waitFor();await page.keyboard.press('Escape');
await page.locator('[data-product-restore="801"]').click();await page.locator('#pb-product-state-modal.show').waitFor();await page.locator('#pb-product-state-confirm').click();await page.waitForFunction(()=>document.getElementById('pb-message').textContent==='Product restored.'&&!document.querySelector('.modal-backdrop'));
assert.equal(row.deleted_at,null);await page.uncheck('#pb-show-deleted');await page.locator('[data-product="801"]').waitFor();await page.locator('[data-product="801"]').click();await page.waitForFunction(()=>document.getElementById('pb-name').value==='Original plastics kit');assert.equal(await page.locator('#pb-delete-product').isVisible(),true);
const reader=await makePage('listings-reader');assert.equal(await reader.locator('#pb-show-deleted,#pb-product-state-modal,#pb-delete-product').count(),0);
const manager=await makePage('listings-editor');assert.equal(await manager.locator('#pb-show-deleted').count(),1);assert.equal(await manager.locator('#pb-delete-product').count(),0);
assert.deepEqual(errors,[]);assert.deepEqual(calls.map(c=>c.action),['delete_product','delete_product','restore_product']);
await page.locator('.pb-editor-header').screenshot({path:'.cache/product-builder/delete-product-button.png'});
console.log('Product deletion UI: editor action, confirmation/cancel/error, preserved draft, deleted filter, read-only archived data, restore, permissions and unchanged table columns OK (mocked API).');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});
