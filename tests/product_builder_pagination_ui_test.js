const {chromium}=require('playwright'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const errors=[],requests=[];let failNext=false,release=null;
 const rows=Array.from({length:237},(_,n)=>{const id=237-n;return {id,name:'Listing '+id,sku:'SKU_'+String(id).padStart(4,'0'),bundle_type:'P',category_kind:'specific',brand:'KTM',model:'SX',year_range:'2026',modelcode:id===1?'OLDEST':'AB23',created_at:'2026-11-0'+(4+Math.floor((id-1)/80))+' 12:00:00',recommended_price:'199.90',recommended_currency:'EUR',component_count:1};});
 const page=await browser.newPage({viewport:{width:1500,height:1000}});page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/scripts/product_builder/api.php?*',async route=>{
  const url=new URL(route.request().url()),action=url.searchParams.get('action');let body={ok:true},status=200;
  if(action==='products'){
   const q=url.searchParams.get('q')||'',size=Number(url.searchParams.get('per_page')),requested=Number(url.searchParams.get('page'));requests.push({q,size,page:requested});
   assert.ok([25,50,100].includes(size));assert.ok(Number.isInteger(requested)&&requested>0);
   const matches=rows.filter(row=>[row.name,row.sku,row.modelcode].some(v=>v.toLowerCase().includes(q.toLowerCase()))),total=matches.length,pages=Math.max(1,Math.ceil(total/size)),current=Math.min(requested,pages),offset=(current-1)*size;
   Object.assign(body,{rows:matches.slice(offset,offset+size),stats:{total:237,specific:237,universal:0},pagination:{page:current,per_page:size,pages,total,from:total?offset+1:0,to:Math.min(offset+size,total)}});
   if(failNext){failNext=false;status=503;body={ok:false,error:'Temporary list failure'};}
   if(q==='Slow query')await new Promise(resolve=>{release=resolve;});
  }else if(action==='product'){body.product={...rows.find(row=>String(row.id)===url.searchParams.get('id')),components:[{component_type:'P',label:'Oldest component',reference:'KT00295',color:'ORANGE',quantity:1,options:{},inventory_supplier:'Polisport',inventory_quantity:8,inventory_status:'matched'}]};}
  else if(['categories','boxes'].includes(action))body.rows=[];
  else if(action==='designs')Object.assign(body,{rows:[],versions:[]});
  else throw Error('Unexpected action '+action);
  await route.fulfill({status,json:body});
 });
 const base='http://localhost/darkscrub/.cache/product-builder/';
 const ready=async(number,size,total)=>page.waitForFunction(({number,size,total})=>{const panel=document.getElementById('pb-saved-products'),input=document.querySelector('[data-list-page-number]'),range=document.querySelector('[data-list-range]');return panel?.getAttribute('aria-busy')==='false'&&input?.value===String(number)&&document.querySelector('[data-list-page-size]')?.value===String(size)&&range?.textContent.includes(String(total))&&!range.textContent.includes('Loading');},{number,size,total});
 const action=name=>page.locator('[data-list-page="'+name+'"]').first();
 await page.goto(base+'i18n-listings-sk.html');await ready(1,100,237);
 assert.equal(await page.locator('.pb-product-row').count(),100);assert.equal(await page.locator('[data-list-pagination]').count(),2);assert.equal(await action('first').isDisabled(),true);assert.equal(await action('previous').isDisabled(),true);
 assert.deepEqual(await page.locator('[data-list-range]').allTextContents(),['Zobrazené 1–100 z 237 listingov.','Zobrazené 1–100 z 237 listingov.']);
 await action('next').click();await ready(2,100,237);assert.match(await page.locator('.pb-product-identity').first().textContent(),/Listing 137/);assert.match(await page.locator('[data-list-range]').first().textContent(),/101–200/);
 await page.locator('[data-list-page="last"]').last().click();await ready(3,100,237);assert.equal(await page.locator('.pb-product-row').count(),37);assert.equal(await action('next').isDisabled(),true);assert.equal(await action('last').isDisabled(),true);
 assert.match(await page.locator('.pb-product-identity').last().textContent(),/SKU_0001/);await page.locator('[data-product-expand="1"]').click();await page.locator('#pb-list-components-1 .pb-component-details').waitFor();assert.match(await page.locator('#pb-list-components-1').textContent(),/Polisport/);assert.equal(await page.locator('[data-product-edit-link="1"]').getAttribute('href'),'index.php?page=product_builder&edit=1');
 await page.locator('[data-list-page-size]').first().selectOption('25');await ready(1,25,237);assert.equal(await page.locator('.pb-product-row').count(),25);assert.deepEqual(await page.locator('[data-list-page-count]').allTextContents(),['z 10','z 10']);
 await action('last').click();await ready(10,25,237);assert.equal(await page.locator('.pb-product-row').count(),12);await action('previous').click();await ready(9,25,237);
 await page.locator('[data-list-page-number]').first().fill('6');await page.locator('[data-list-page-number]').first().press('Enter');await ready(6,25,237);assert.match(await page.locator('[data-list-range]').first().textContent(),/126–150/);
 await page.locator('[data-list-page-number]').first().fill('999');await page.locator('[data-list-page-number]').first().press('Enter');await ready(10,25,237);
 await page.fill('#pb-search','OLDEST');await ready(1,25,1);assert.equal(await page.locator('.pb-product-row').count(),1);assert.match(await page.locator('.pb-product-identity').textContent(),/SKU_0001/);assert.equal(requests.at(-1).page,1);
 await page.fill('#pb-search','Nothing matches');await page.waitForFunction(()=>document.querySelector('#pb-products').textContent.includes('Zatiaľ'));await ready(1,25,0);assert.equal(await page.locator('.pb-product-row').count(),0);assert.equal(await page.locator('[data-list-page-number]').first().isDisabled(),true);
 await page.fill('#pb-search','');await ready(1,25,237);await page.locator('[data-list-page-size]').last().selectOption('50');await ready(1,50,237);
 await page.fill('#pb-search','Slow query');while(!release)await new Promise(r=>setTimeout(r,10));await page.fill('#pb-search','OLDEST');await ready(1,50,1);
 const late=page.waitForResponse(r=>r.url().includes('q=Slow+query'));release();await late;await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));assert.match(await page.locator('.pb-product-identity').textContent(),/SKU_0001/);
 await page.fill('#pb-search','');await ready(1,50,237);failNext=true;await action('next').click();await page.locator('[data-list-retry]').waitFor();assert.equal(await page.locator('.pb-product-row').count(),0);assert.match(await page.locator('#pb-products').textContent(),/Temporary list failure/);await page.locator('[data-list-retry]').click();await ready(1,50,237);
 await page.locator('#pb-saved-products').screenshot({path:'.cache/product-builder/pagination-listings-sk.png'});
 await page.setViewportSize({width:390,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await page.locator('[data-list-pagination]').first().screenshot({path:'.cache/product-builder/pagination-mobile.png'});
 await page.setViewportSize({width:1500,height:1000});await page.goto(base+'listings-reader.html');await ready(1,100,237);assert.equal(await page.locator('[data-product-edit-link]').count(),0);await action('last').click();await ready(3,100,237);await page.locator('[data-product="1"]').click();await page.locator('#pb-detail-modal.show').waitFor();assert.match(await page.locator('#pb-detail').textContent(),/Oldest component/);
 assert.deepEqual(await page.locator('[data-list-range]').allTextContents(),['Showing 201–237 of 237 listings.','Showing 201–237 of 237 listings.']);
 await page.goto(base+'i18n-builder-en.html');await ready(1,100,237);await action('last').click();await ready(3,100,237);assert.equal(await page.locator('#pb-stat-total').textContent(),'237');assert.equal(await page.locator('#pb-product-form').count(),1);
 assert.deepEqual(errors,[]);console.log('Pagination UI: all pages, oldest row/detail/edit, 25/50/100, direct jump, search reset, stale response, retry, SK/EN, reader/Builder and mobile OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
