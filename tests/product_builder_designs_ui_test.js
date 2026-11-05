const {chromium}=require('playwright');
const fs=require('fs'),assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  let designs=JSON.parse(fs.readFileSync('.cache/product-builder/designs.json','utf8'));
  const categories=JSON.parse(fs.readFileSync('.cache/product-builder/categories.json','utf8'));
  const g=designs.find(d=>d.component_type==='G'&&d.name==='G1'),s=designs.find(d=>d.component_type==='S');
  let listing=null,nextId=90001,saves=0;
  await page.route('**/scripts/product_builder/api.php?*',async route=>{
   const action=new URL(route.request().url()).searchParams.get('action'),data=route.request().method()==='POST'?JSON.parse(route.request().postData()):{};
   let result={ok:true};
   if(action==='boxes'){await route.fulfill({json:{ok:true,rows:[]}});return;}
   else if(action==='categories')result.rows=categories;
   else if(action==='designs'){result.rows=designs;result.versions=JSON.parse(fs.readFileSync('.cache/product-builder/graphics-versions.json','utf8'));}
   else if(action==='products')Object.assign(result,{rows:listing?[listing]:[],stats:{total:listing?1:0,specific:0,universal:listing?1:0}});
   else if(action==='save_design'){
    if(designs.some(d=>d.component_type===data.component_type&&d.name.toLowerCase()===data.name.toLowerCase()&&String(d.id)!==String(data.id))){await route.fulfill({status:422,json:{ok:false,error:'A design with this name already exists in this group.'}});return;}
    const id=Number(data.id)||nextId++,previous=designs.find(d=>Number(d.id)===id);
    designs=designs.filter(d=>Number(d.id)!==id).concat({...data,id,version:Number(data.version||0)+1,usage_count:previous?.usage_count||0});result.id=id;
   }else if(action==='delete_design')designs=designs.filter(d=>String(d.id)!==data.id);
   else if(action==='save_product'){
    assert.equal(data.components[0].design_id,String(g.id));assert.equal(data.components[1].design_id,String(s.id));
    assert.equal(data.components[0].label,'My custom graphics label');assert.equal(data.components[0].options.graphics_finish,'Matte');
    assert.equal(data.components[1].label,s.name);assert.equal(data.components[1].options.seat_waterproof_seams,'✓');
    saves++;listing={...data,id:77,sku:data.sku||data.bundle_type+'_DESIGNTEST',version:Number(data.version||0)+1,component_count:2};
    listing.components=listing.components.map(c=>({...c,design_name:designs.find(d=>String(d.id)===c.design_id)?.name}));
    designs.forEach(d=>{if(listing.components.some(c=>c.design_id===String(d.id)))d.usage_count=1;});result.product={id:77,sku:listing.sku};
   }else if(action==='product')result.product=listing;
   else throw new Error('Unexpected API action '+action);
   await route.fulfill({json:result});
  });
  await page.goto(process.env.PRODUCT_BUILDER_TEST_URL||'http://localhost/darkscrub/.cache/product-builder/preview.html');
  await page.selectOption('#pb-bundle','GS');
  const gr=page.locator('[data-component-row="G"]'),sr=page.locator('[data-component-row="S"]');
  await page.waitForFunction(()=>document.querySelectorAll('[data-component-row="G"] [data-field="design_id"] option').length===454);
  assert.equal(await sr.locator('[data-field="design_id"] option').count(),1065);
  for(const row of [gr,sr]){
    const positions=await row.evaluate(root=>['.select2-selection',...(root.dataset.componentRow==='G'?['[data-field="graphics_version_id"]']:[]),'[data-field="label"]','[data-field="reference"]'].map(selector=>{const box=root.querySelector(selector).getBoundingClientRect();return {x:box.x,y:box.y,right:box.right};}));
    assert.ok(positions.every(position=>Math.abs(position.y-positions[0].y)<2),'Design, name and reference share one desktop row.');
    assert.ok(positions.slice(1).every((position,i)=>position.x>=positions[i].right),'Three distinct adjacent columns.');
  }
  await gr.locator('.select2-selection').click();await page.locator('.select2-container--open .select2-search__field').fill('G1');
  await page.getByRole('option',{name:'G1',exact:true}).click();
  assert.equal(await gr.locator('[data-field="label"]').inputValue(),'G1');
  await sr.locator('.select2-selection').click();await page.locator('.select2-container--open .select2-search__field').fill(s.name);
  await page.getByRole('option',{name:s.name,exact:true}).click();
  await gr.locator('[data-field="label"]').fill('My custom graphics label');
  await gr.locator('[data-spec="graphics_finish"]').selectOption('Matte');await sr.locator('[data-spec="seat_waterproof_seams"]').selectOption('✓');
  await page.selectOption('#pb-bundle','G');await page.selectOption('#pb-bundle','GS');
  assert.equal(await gr.locator('[data-field="design_id"]').inputValue(),String(g.id));assert.equal(await sr.locator('[data-field="design_id"]').inputValue(),String(s.id));
  assert.equal(await gr.locator('[data-field="label"]').inputValue(),'My custom graphics label');
  await page.locator('[data-design-manager="G"]').click();await page.waitForFunction(()=>document.getElementById('pb-design-count').textContent==='453 of 453 designs');
  assert.equal(await page.locator('#pb-design-title').textContent(),'Graphics designs');
  await page.locator('#pb-design-search').fill('G1');await page.locator('[data-design-edit="'+g.id+'"]').click();
  await page.locator('#pb-design-name').fill('Renamed G1');await page.locator('#pb-design-form [type="submit"]').click();
  await page.waitForFunction(()=>document.getElementById('pb-design-message').textContent==='Design saved.');
  assert.equal(await gr.locator('[data-field="design_id"] option:checked').textContent(),'Renamed G1');
  assert.equal(await gr.locator('[data-field="label"]').inputValue(),'My custom graphics label');
  await page.locator('#pb-design-new').click();await page.locator('#pb-design-name').fill('G2');await page.locator('#pb-design-form [type="submit"]').click();
  await page.waitForFunction(()=>document.getElementById('pb-design-message').textContent.includes('already exists'));
  await page.locator('#pb-design-name').fill('Test <design> & new');await page.locator('#pb-design-form [type="submit"]').click();
  await page.waitForFunction(()=>document.getElementById('pb-design-message').textContent==='Design saved.');
  assert.equal(await gr.locator('[data-field="design_id"] option').count(),455);assert.equal(await sr.locator('[data-field="design_id"] option').count(),1065);
  await page.locator('#pb-design-search').fill('Test <design>');assert.equal(await page.locator('#pb-design-rows tr').count(),1);
  assert.ok((await page.locator('#pb-design-rows').textContent()).includes('Test <design> & new'));assert.equal(await page.locator('#pb-design-rows design').count(),0);
  await page.locator('#pb-design-delete').click();await page.waitForFunction(()=>document.getElementById('pb-design-message').textContent==='Design deleted.');
  assert.equal(await gr.locator('[data-field="design_id"] option').count(),454);
  const close=async()=>{await page.locator('#pb-design-modal [data-dismiss="modal"]').click();await page.locator('#pb-design-modal').waitFor({state:'hidden'});};
  await close();await page.locator('[data-design-manager="S"]').click();
  await page.waitForFunction(()=>document.getElementById('pb-design-count').textContent==='1064 of 1064 designs');
  assert.equal(await page.locator('#pb-design-title').textContent(),'Seat Covers designs');
  await page.locator('#pb-design-search').fill('TEMPER');await page.locator('#pb-design-modal').screenshot({path:'.cache/product-builder/designs-manager.png'});
  await close();await page.locator('#pb-name').fill('Graphics and seat listing');await page.fill('#pb-recommended-price','149.90');
  await page.locator('[name="category_kind"][value="universal"]').check();
  await page.selectOption('#pb-uni-brand',categories[0].brand);await page.selectOption('#pb-uni-category',String(categories[0].id));
  await page.locator('#pb-save').click();await page.waitForFunction(()=>document.getElementById('pb-edit-label').textContent==='Editing product #77');
  assert.equal(saves,1);assert.equal(await sr.locator('[data-field="design_id"]').inputValue(),String(s.id));
  await page.locator('[data-product-expand="77"]').click();await page.waitForFunction(()=>document.querySelector('#pb-list-components-77')?.textContent.includes('Design: Renamed G1'));
  assert.ok((await page.locator('#pb-list-components-77').textContent()).includes('Design: Renamed G1'));
  await page.locator('[data-design-manager="G"]').click();await page.locator('#pb-design-search').fill('Renamed G1');
  await page.locator('[data-design-edit="'+g.id+'"]').click();assert.equal(await page.locator('#pb-design-delete').isDisabled(),true);
  await close();await page.screenshot({path:'.cache/product-builder/designs-components.png',fullPage:true});
  await page.setViewportSize({width:390,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'No mobile page overflow');
  await page.locator('[data-design-manager="S"]').click();await page.waitForTimeout(300);
  assert.equal(await page.evaluate(()=>document.getElementById('pb-design-modal').scrollWidth<=innerWidth),true,'No mobile modal overflow');
  await page.locator('#pb-design-modal').screenshot({path:'.cache/product-builder/designs-mobile.png'});
  assert.deepEqual(errors,[]);
  console.log('Design UI: searchable G/S selection, bundle switching, separate management, escaping, duplicates, save/edit round trip, usage protection and mobile layout OK.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
