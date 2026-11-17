const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({channel:'chrome',headless:true});
  const page = await browser.newPage({viewport:{width:1440,height:1000}});
  let board = null, columns = [], allCards = [], nextId = 10, failMove = false, postCount = 0;
  const errors = [], calls = [];
  page.on('pageerror', e => errors.push(e.message));
  const card = (id, column_id, title, extra={}) => ({id,column_id,title,description:'Dva návrhy podľa briefu.\nPripraviť verziu na schválenie.',priority:'high',due_date:'2026-11-18',label:'Grafika',reference:'Klient ABC · #1258',reference_url:'https://example.com/orders/1258',completed_at:null,created_at:'2026-11-13 12:00:00',...extra});
  function snapshot(search='') {
    const q=search.toLocaleLowerCase();
    return {ok:true,board,active_count:allCards.filter(card=>columns.some(col=>col.id===card.column_id&&!col.is_done)).length,columns:columns.map(col=>{
      let rows=allCards.filter(c=>c.column_id===col.id);
      if(col.is_done)rows.sort((a,b)=>(b.completed_at||'').localeCompare(a.completed_at||'')||b.id-a.id);
      if(q)rows=rows.filter(c=>[c.title,c.description,c.label,c.reference].some(s=>(s||'').toLocaleLowerCase().includes(q)));
      const limit=col.is_done?board.done_limit:100;
      return {...col,cards:rows.slice(0,limit),total:rows.length,has_more:rows.length>limit};
    })};
  }
  await page.route('**/scripts/kanban/api.php*',async route=>{
    const req=route.request(),url=new URL(req.url()),action=url.searchParams.get('action');
    if(req.method()==='GET'){
      const snap=snapshot(url.searchParams.get('search')||'');
      if(url.searchParams.has('column_id')){
        const id=Number(url.searchParams.get('column_id')),offset=Number(url.searchParams.get('offset'));
        const col=columns.find(c=>c.id===id);
        const rows=allCards.filter(c=>c.column_id===id).sort((a,b)=>(b.completed_at||'').localeCompare(a.completed_at||'')||b.id-a.id);
        const cards=rows.slice(offset,offset+(col.is_done?board.done_limit:100));
        await route.fulfill({json:{ok:true,cards,total:rows.length,has_more:offset+cards.length<rows.length}});return;
      }
      await route.fulfill({json:snap});return;
    }
    const data=req.postDataJSON();++postCount;calls.push({action,data});
    assert.equal(req.headers()['x-csrf-token'],'kanban-ui-test-token');
    if(failMove && action==='move_card'){
      failMove=false;
      await route.fulfill({status:500,json:{ok:false,error:'Test: uloženie zlyhalo.'}});return;
    }
    if(action!=='initialize')assert.equal(data.version,board.version,'Every mutation carries current revision');
    if(action==='initialize'){
      board={id:1,version:1,done_limit:2};
      columns=[{id:1,title:'Backlog',color:'#94a3b8',is_done:0},{id:2,title:'In progress',color:'#fbbf24',is_done:0},{id:3,title:'Done',color:'#34d399',is_done:1}];
      allCards=[card(1,1,'Banner pre jesennú kampaň'),card(2,1,'Podklady k tlači'),card(3,2,'Skontrolovať návrh'),
        card(4,3,'Staršia hotová karta',{completed_at:'2026-11-10 10:00:00'}),
        card(5,3,'Novší hotový návrh',{completed_at:'2026-11-11 10:00:00'}),
        card(6,3,'Schválený vizuál',{completed_at:'2026-11-12 10:00:00'})];
    } else if(action==='move_card'){
      const c=allCards.find(c=>c.id===data.id),old=columns.find(col=>col.id===c.column_id),dest=columns.find(col=>col.id===data.column_id);
      c.column_id=data.column_id;
      c.completed_at=dest.is_done?(old.is_done?c.completed_at:'2026-11-13 12:00:00'):null;
      allCards=allCards.filter(row=>row.id!==c.id);
      const idx=allCards.findIndex(row=>row.id===data.before_id);
      if(idx>=0)allCards.splice(idx,0,c);else allCards.push(c);
    } else if(action==='save_card'){
      if(data.id){Object.assign(allCards.find(c=>c.id===data.id),data);}
      else { const id=nextId++; allCards.push(card(id,data.column_id,data.title,{...data,id})); }
    } else if(action==='save_column'){
      if(data.id)Object.assign(columns.find(c=>c.id===data.id),data);else columns.push({...data,id:nextId++});
    } else if(action==='move_column'){
      const col=columns.find(c=>c.id===data.id);columns=columns.filter(c=>c.id!==data.id);
      const idx=columns.findIndex(c=>c.id===data.before_id);if(idx>=0)columns.splice(idx,0,col);else columns.push(col);
    } else if(action==='settings'){board.done_limit=data.done_limit;}
    else if(action==='delete_card'){allCards=allCards.filter(c=>c.id!==data.id);}
    else if(action==='delete_column'){
      if(allCards.some(c=>c.column_id===data.id)){await route.fulfill({status:422,json:{ok:false,error:'Stĺpec obsahuje karty.'}});return;}
      columns=columns.filter(c=>c.id!==data.id);
    } else throw new Error('Unexpected action '+action);
    if(action!=='initialize')board.version++;
    await route.fulfill({json:{ok:true}});
  });
  const idle=()=>page.waitForFunction(()=>document.getElementById('kb-board').getAttribute('aria-busy')==='false' && !document.getElementById('kb-refresh').disabled);
  const drag=async(source,target,targetOffset=15)=>{
    const a=await source.boundingBox(),b=await target.boundingBox();
    assert(a&&b,'Drag targets are visible');
    await page.mouse.move(a.x+a.width/2,a.y+Math.min(a.height/2,35));
    await page.mouse.down();
    await page.mouse.move(a.x+a.width/2+9,a.y+Math.min(a.height/2,35)+9,{steps:5});
    await page.waitForTimeout(80);
    await page.mouse.move(b.x+b.width/2,b.y+Math.min(b.height/2,targetOffset),{steps:20});
    await page.waitForTimeout(220);
    await page.mouse.up();
    await page.waitForTimeout(180);
    await idle();
  };
  try{
    await page.goto(process.env.KANBAN_TEST_URL||'http://localhost/darkscrub/.cache/kanban/preview.html');
    await idle();
    assert(await page.locator('#kb-onboarding').isVisible());
    assert(!(await page.locator('#navbarKanbanCount').isVisible()),'Zero count is hidden');
    await page.locator('[data-template=general]').click();await idle();
    assert.equal(await page.locator('.kb-lane').count(),3);
    assert.equal(await page.locator('#navbarKanbanCount').innerText(),'3','Backlog and active cards are counted');
    assert.match(await page.locator('#navbarKanbanCount').getAttribute('title'),/vrátane Backlogu/);
    assert.equal(await page.locator('[data-column="3"] .kb-card').count(),2);
    const bg=await page.locator('.kb-card').first().evaluate(el=>getComputedStyle(el).backgroundColor);
    assert.equal(bg,'rgb(50, 62, 75)','Cards use dark palette');
    await page.screenshot({path:'.cache/kanban/desktop.png',fullPage:true});
    await drag(page.locator('.kb-card[data-id="1"] .kb-description'),page.locator('[data-column="2"]'));
    assert.equal(allCards.find(c=>c.id===1).column_id,2,'Real mouse drag moves a card between lanes');
    await drag(page.locator('.kb-card[data-id="1"] .kb-description'),page.locator('[data-column="3"]'));
    assert.equal(allCards.find(c=>c.id===1).column_id,3,'Drag into Done');
    assert.equal(await page.locator('#navbarKanbanCount').innerText(),'2','Done updates navbar immediately');
    assert.equal(await page.locator('[data-column="3"] .kb-card').count(),2,'Done limit after drop');
    await page.locator('[data-more="3"]').click();await idle();
    assert.equal(await page.locator('[data-column="3"] .kb-card').count(),4,'Older cards accessible');
    await drag(page.locator('.kb-card[data-id="1"] .kb-description'),page.locator('[data-column="1"]'));
    assert.equal(allCards.find(c=>c.id===1).completed_at,null,'Drag back reopens card');
    assert.equal(await page.locator('#navbarKanbanCount').innerText(),'3','Reopening updates navbar immediately');
    // Reorder actual cards inside a list.
    await drag(page.locator('.kb-card[data-id="1"] .kb-description'),page.locator('.kb-card[data-id="2"]'));
    assert.deepEqual(await page.locator('[data-column="1"] .kb-card').evaluateAll(els=>els.map(e=>Number(e.dataset.id))),[2,1], JSON.stringify(calls.slice(-3)));
    failMove=true;
    await drag(page.locator('.kb-card[data-id="1"] .kb-description'),page.locator('[data-column="2"]'));
    assert.equal(await page.locator('[data-column="1"] .kb-card[data-id="1"]').count(),1,'Failed drag restores original UI');
    assert.match(await page.locator('#kb-message').innerText(),/zlyhalo/);
    assert.equal(await page.locator('#navbarKanbanCount').innerText(),'3','Rejected moves do not change count');
    await page.locator('#kb-refresh').click();await idle();
    // Move a column by its handle, then reload from server state.
    await drag(page.locator('.kb-lane[data-id="1"] .kb-column-handle'),page.locator('.kb-lane[data-id="3"] .kb-lane-header'));
    assert.notEqual(columns[0].id,1,'Column drag persists');
    await page.locator('#kb-refresh').click();await idle();
    assert.deepEqual(await page.locator('.kb-lane').evaluateAll(els=>els.map(e=>Number(e.dataset.id))),columns.map(c=>c.id));
    await page.locator('#kb-add-card').click();
    const form=page.locator('#kb-card-form');
    await form.locator('[name=title]').fill('<img src=x onerror=alert(1)> Test karta');
    await form.locator('[name=description]').fill('Detailná poznámka pre grafika');
    await form.locator('[name=column_id]').selectOption('1');
    await form.locator('[name=priority]').selectOption('urgent');
    await form.locator('[name=reference]').fill('Objednávka #123');
    await form.locator('[name=label]').fill('Tlač');
    await form.locator('[name=due_date]').fill('2026-11-20');
    await page.screenshot({path:'.cache/kanban/detail.png',fullPage:true});
    await form.locator('[type=submit]').click();await idle();
    assert.equal(await page.locator('.kb-card img').count(),0,'Card text cannot inject markup');
    const newCard=allCards.find(c=>c.title.includes('Test karta'));
    assert(newCard);
    await page.locator('.kb-card-title[data-edit-card="'+newCard.id+'"]').click();
    assert.equal(await form.locator('[name=description]').inputValue(),'Detailná poznámka pre grafika');
    await form.locator('[name=column_id]').selectOption('2');
    await form.locator('[type=submit]').click();await idle();
    assert.equal(allCards.find(c=>c.id===newCard.id).column_id,2,'Keyboard/form move');
    await page.locator('#kb-add-column').click();
    await page.locator('#kb-column-form [name=title]').fill('Na schválenie');
    await page.locator('#kb-column-form [type=submit]').click();await idle();
    assert(columns.some(c=>c.title==='Na schválenie'),'Custom column created');
    await page.locator('#kb-done-limit').fill('1');await page.locator('#kb-done-limit').press('Tab');await idle();
    assert.equal(board.done_limit,1);
    assert.equal(await page.locator('[data-column="3"] .kb-card').count(),1);
    await page.locator('#kb-search').fill('Staršia');
    await page.waitForTimeout(400);await idle();
    assert.equal(await page.locator('.kb-card').count(),1,'Search finds hidden completed card');
    assert.equal(await page.locator('#navbarKanbanCount').innerText(),'4','Search leaves total unchanged');
    assert.equal(await page.locator('.kb-card-title').innerText(),'Staršia hotová karta');
    const before=postCount;
    await drag(page.locator('.kb-card .kb-description'),page.locator('[data-column="2"]'));
    assert.equal(postCount,before,'Dragging filtered results disabled');
    await page.locator('#kb-search').fill('');await page.waitForTimeout(400);await idle();
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:'.cache/kanban/mobile.png',fullPage:true});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Mobile document has no horizontal overflow');
    await page.locator('#kb-add-card').click();
    assert(await page.locator('#kb-card-dialog').isVisible());
    const bounds=await page.locator('#kb-card-dialog').boundingBox();
    assert(bounds.x>=0 && bounds.x+bounds.width<=390,'Modal fits mobile viewport');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#kb-card-dialog').isVisible(),false);
    // Reload proves the front end uses persisted server state rather than local storage.
    await page.reload();await idle();
    assert.equal(await page.locator('.kb-lane').count(),columns.length);
    assert.equal(await page.locator('[data-column="3"] .kb-card').count(),1);
    assert.deepEqual(errors,[],'No browser exceptions');
    assert(calls.filter(c=>c.action==='move_card').length>=5);
    console.log('kanban_ui: drag between lanes, Done/reopen, reordering, rollback, column drag, rich cards, XSS escaping, history, search, persistence and mobile checks passed.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
