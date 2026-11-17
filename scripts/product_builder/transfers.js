/* Transfer D-base: one preparation state per source Graphics item. */
(function(){'use strict';$(function(){
const cfg=window.productBuilderConfig;if(cfg?.pageMode!=='transfers')return;
const el=id=>document.getElementById(id),body=el('pb-products'),panel=el('pb-saved-products');
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const t=(key,values={})=>(cfg.language==='sk'?(cfg.translations?.[key]??key):key).replace(/\{(\w+)\}/g,(m,k)=>Object.hasOwn(values,k)?String(values[k]):m);
if(cfg.languageCookie)document.cookie=cfg.languageCookie+'='+cfg.language+'; Max-Age=31536000; Path=/; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');
let page=1,perPage=100,pages=1,total=0,from=0,to=0,seq=0,loading=false,loaded=false,rows=[],timer;
const filterInputs=Array.from(panel.querySelectorAll('[data-transfer-filter]'));
const selectedFilters=()=>filterInputs.filter(input=>input.checked).map(input=>input.dataset.transferFilter).join(',');
const saving=new Set(),date=value=>{const m=String(value||'').match(/^(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'.'+m[2]+'.'+m[1]:'—';};
async function api(action,data,query={}){
 const options={credentials:'same-origin',headers:{}};
 if(data!==undefined){options.method='POST';options.headers={'Content-Type':'application/json','X-CSRF-Token':cfg.csrf};options.body=JSON.stringify(data);}
 const response=await fetch('scripts/product_builder/api.php?'+new URLSearchParams({action,pb_lang:cfg.language,...query}),options);
 let result;try{result=await response.json();}catch(_){throw Error(t('Invalid server response. Check that you are signed in and refresh the page.'));}
 if(!response.ok||!result.ok)throw Error(result.error||t('Operation failed.'));return result;
}
function message(text,ok=false){const node=el('pb-message');node.textContent=text;node.hidden=!text;node.className='alert alert-'+(ok?'success':'danger');}
function icon(name,label){return '<button type="button" class="btn btn-sm btn-outline-secondary" disabled aria-label="'+esc(t(label))+'" title="'+esc(t('Available after creating the listing.'))+'"><i class="fas fa-'+name+'" aria-hidden="true"></i></button>';}
function unavailable(extra=''){return '<td class="pb-transfer-unavailable '+extra+'" aria-disabled="true">—</td>';}
function rowHtml(row){
 const state=row.img_status||'',linked=!!row.product_id||row.status==='completed',busy=saving.has(Number(row.id));
 const editable=cfg.canEdit&&!linked&&!busy&&!loading,ready=state==='ready'&&!linked,displayState=linked?'completed':state;
 const category=row.snapshot?.category||{},categoryText=[category.brand,category.model,category.year_range].filter(Boolean).join(' · ')||row.snapshot?.category_text||'—';
 const dateText=date(row.created_at),target=Number(cfg.openTransferId)===Number(row.id);
 let html='<tr class="pb-product-row'+(target?' pb-transfer-target':'')+'" data-transfer-row="'+Number(row.id)+'" data-transfer-state="'+esc(displayState)+'">';
 html+='<td class="pb-created-column" title="'+esc(row.created_at)+'">'+esc(dateText.replace(/\.(\d{2})(\d{2})$/,'.$2'))+'</td>';
 html+='<td class="pb-product-identity"><strong>'+esc(row.title)+'</strong><div><code title="'+esc(t('Source order'))+'">'+esc(row.order_number)+'</code></div></td>';
 html+='<td><span class="badge pb-bundle-badge" data-bundle="G">G</span></td>';
 html+='<td class="pb-category-cell">'+(category.modelcode?'<div class="pb-category-meta"><code>'+esc(category.modelcode)+'</code></div>':'')+'<small>'+esc(categoryText)+'</small></td>';
 html+='<td class="pb-transfer-img" data-state="'+esc(displayState)+'">';
 if(linked)html+='<span class="pb-transfer-created"><i class="fas fa-check-circle" aria-hidden="true"></i>'+esc(t('Listing created'))+'</span>';
 else html+='<select class="form-control form-control-sm pb-img-select" data-transfer-img="'+Number(row.id)+'" aria-label="IMG · '+esc(row.order_number)+'"'+(editable?'':' disabled')+'>'+[['','—'],['skip','Skip'],['gen','Gen'],['ready','Ready']].map(([value,label])=>'<option value="'+value+'"'+(state===value?' selected':'')+'>'+label+'</option>').join('')+'</select>';
 html+='<span class="pb-transfer-feedback" role="status">'+(busy?esc(t('Saving…')):'')+'</span></td>';
 const folderEnabled=!busy&&!loading&&((ready&&cfg.canEdit)||((ready||linked)&&row.folder_path));
 const folderLabel=t(ready&&cfg.canEdit?'Edit folder path':'View folder path');
 html+='<td class="pb-icon-column"><div class="pb-cell-actions"><button type="button" class="btn btn-sm btn-outline-info" data-transfer-folder="'+Number(row.id)+'" title="'+esc(folderLabel)+'" aria-label="'+esc(folderLabel)+'"'+(folderEnabled?'':' disabled')+'><i class="far fa-folder-open" aria-hidden="true"></i></button>';
 if(row.folder_path&&(ready||linked))html+='<button type="button" class="btn pb-copy-code" data-transfer-copy="'+Number(row.id)+'" title="'+esc(t('Copy folder path'))+'" aria-label="'+esc(t('Copy folder path'))+'"><i class="far fa-copy" aria-hidden="true"></i></button>';
 html+='</div></td>'+unavailable('pb-price-column');
 for(const channel of ['Web','eBay','Amazon']){
  html+='<td class="pb-price-column pb-transfer-unavailable"><select class="form-control form-control-sm pb-channel-select" disabled aria-label="'+channel+'"><option>—</option></select><div class="pb-channel-price">—</div></td>';
  html+='<td class="pb-icon-column pb-transfer-unavailable">'+icon('external-link-alt','Open listing')+' '+icon('pencil-alt','Edit listing')+'</td>'+unavailable('pb-channel-stats');
 }
 html+=unavailable()+'<td><button type="button" class="btn btn-sm btn-outline-secondary" disabled>'+esc(t('{count} components',{count:1}))+'</button></td><td>';
 if(ready&&cfg.canEdit&&!busy&&!loading)html+='<a class="btn btn-sm btn-success" title="'+esc(t('Move to Product Builder'))+'" data-transfer-prepare="'+Number(row.id)+'" href="index.php?page=product_builder&amp;transfer='+Number(row.id)+'"><i class="fas fa-arrow-right mr-1" aria-hidden="true"></i>'+esc(t('Product Builder'))+'</a>';
 else html+='<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="'+esc(linked?(row.product_sku||t('Listing created')):t('Set IMG to Ready to continue.'))+'">'+esc(t(linked?'Listing created':'Product Builder'))+'</button>';
 return html+'</td></tr>';
}
function renderRows(){
 let day='';body.innerHTML=rows.map(row=>{const current=date(row.created_at);let separator='';if(current!==day){day=current;separator='<tr class="pb-day-separator-row"><td colspan="19"><div class="pb-day-separator">'+esc(day)+'</div></td></tr>';}return separator+rowHtml(row);}).join('')||'<tr><td colspan="19" class="text-center py-4 pb-muted">'+esc(t(selectedFilters()||el('pb-search').value.trim()?'No transfers match this selection.':'Select at least one status filter.'))+'</td></tr>';
}
function pagination(){
 filterInputs.forEach(input=>{input.disabled=saving.size>0;});
 panel.querySelectorAll('[data-list-pagination]').forEach(pager=>{
  pager.querySelector('[data-list-range]').textContent=loading?t('Loading products…'):loaded?t('Showing {from}–{to} of {total} transfers.',{from,to,total}):t('Unable to load the data. Please try again.');
  const size=pager.querySelector('[data-list-page-size]');size.value=String(perPage);size.disabled=loading||saving.size>0;
  const input=pager.querySelector('[data-list-page-number]');input.value=String(page);input.max=String(pages);input.disabled=loading||!loaded||!total||saving.size>0;
  pager.querySelector('[data-list-page-count]').textContent=t('of {pages}',{pages});
  pager.querySelectorAll('[data-list-page]').forEach(button=>button.disabled=loading||!loaded||saving.size>0||(['first','previous'].includes(button.dataset.listPage)?page<=1:page>=pages));
 });
}
async function refresh(){
 if(saving.size||folderContext)return;
 el('pb-transfer-search-hint').hidden=!el('pb-search').value.trim()||filterInputs.find(input=>input.dataset.transferFilter==='completed').checked;
 const request=++seq;loading=true;pagination();renderRows();
 try{const result=await api('transfers',undefined,{page,per_page:perPage,q:el('pb-search').value,img_filters:selectedFilters()});if(request!==seq)return;
  ({page,per_page:perPage,pages,total,from,to,rows}=result);loaded=true;
 }catch(error){if(request!==seq)return;loaded=false;body.innerHTML='<tr><td colspan="19" class="text-center py-4">'+esc(error.message)+' <button type="button" class="btn btn-sm btn-outline-info" data-list-retry>'+esc(t('Retry'))+'</button></td></tr>';}
 finally{if(request===seq){loading=false;if(loaded)renderRows();pagination();}}
}
body.addEventListener('change',async event=>{
 const input=event.target.closest('[data-transfer-img]');if(!input)return;
 const id=Number(input.dataset.transferImg),row=rows.find(item=>Number(item.id)===id);if(!row||saving.has(id)||loading||!cfg.canEdit)return;
 const state=input.value;saving.add(id);renderRows();pagination();message('');
 try{const result=await api('save_transfer_image',{id,version:row.version,img_status:state});rows=rows.map(item=>Number(item.id)===id?result.transfer:item);message(t('Transfer saved.'),true);}
 catch(error){
  // A rejected or uncertain save must never leave a misleading Ready row behind.
  try{const latest=await api('transfer',undefined,{id});rows=rows.map(item=>Number(item.id)===id?latest.transfer:item);}catch(_){}
  message(error.message);
 }finally{saving.delete(id);renderRows();pagination();if(el('pb-search').value!==lastSearch){lastSearch=el('pb-search').value;page=1;}await refresh();}
});
// Keep the modal on the same Bootstrap interface as Product Builder.
const modalInterface=$.fn.modal,folderModal=el('pb-transfer-folder-modal'),folderInput=el('pb-transfer-folder-input');
let folderContext=null,folderBusy=false,folderDirty=false,lastSearch='';
function folderError(text){const node=el('pb-transfer-folder-error');node.textContent=text;node.hidden=!text;}
function folderControls(){
 const editable=folderContext?.editable&&!folderBusy;
 folderInput.readOnly=!editable;el('pb-transfer-folder-save').hidden=!folderContext?.editable;el('pb-transfer-folder-save').disabled=!editable;
 el('pb-transfer-folder-copy').disabled=!folderInput.value.trim()||folderBusy;
 folderModal.querySelectorAll('[data-dismiss]').forEach(button=>{button.disabled=folderBusy;});
 el('pb-transfer-folder-reload').disabled=folderBusy;
}
function fillFolder(row){
 folderContext={id:Number(row.id),version:row.version,editable:!!cfg.canEdit&&!row.product_id&&row.status==='pending'&&row.img_status==='ready'};
 folderInput.value=row.folder_path||'';el('pb-transfer-folder-source').textContent=row.title+' · '+row.order_number;
 folderDirty=false;folderError('');el('pb-transfer-folder-reload').hidden=true;folderControls();
}
body.addEventListener('click',async event=>{
 const button=event.target.closest('[data-transfer-folder]');
 if(button&&!button.disabled){const row=rows.find(item=>Number(item.id)===Number(button.dataset.transferFolder));if(!row)return;fillFolder(row);modalInterface.call($(folderModal),'show');}
 const copy=event.target.closest('[data-transfer-copy]');
 if(copy){const row=rows.find(item=>Number(item.id)===Number(copy.dataset.transferCopy));if(row?.folder_path)await copyFolder(row.folder_path,copy);}
});
folderModal.querySelectorAll('[data-dismiss="modal"]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();modalInterface.call($(folderModal),'hide');}));
$(folderModal).on('shown.bs.modal',()=>folderInput.focus());
$(folderModal).on('hide.bs.modal',event=>{if(folderBusy||(folderDirty&&!confirm(t('Discard unsaved changes?'))))event.preventDefault();});
$(folderModal).on('hidden.bs.modal',async()=>{const id=folderContext?.id;folderContext=null;folderDirty=false;await refresh();body.querySelector('[data-transfer-folder="'+id+'"]')?.focus({preventScroll:true});});
folderInput.addEventListener('input',()=>{folderDirty=true;folderControls();});
el('pb-transfer-folder-form').addEventListener('submit',async event=>{
 event.preventDefault();if(!folderContext?.editable||folderBusy)return;
 const {id,version}=folderContext;folderBusy=true;saving.add(id);folderControls();pagination();folderError('');
 let saved=false;
 try{const result=await api('save_transfer_folder',{id,version,folder_path:folderInput.value});rows=rows.map(row=>Number(row.id)===id?result.transfer:row);folderDirty=false;saved=true;message(t('Folder path saved.'),true);}
 catch(error){folderError(error.message);el('pb-transfer-folder-reload').hidden=false;}
 finally{folderBusy=false;saving.delete(id);folderControls();pagination();renderRows();}
 if(saved)modalInterface.call($(folderModal),'hide');
});
el('pb-transfer-folder-reload').addEventListener('click',async()=>{
 if(!folderContext||folderBusy)return;
 if(folderDirty&&!confirm(t('Discard unsaved changes?')))return;
 folderBusy=true;folderControls();
 try{const result=await api('transfer',undefined,{id:folderContext.id});rows=rows.map(row=>Number(row.id)===Number(result.transfer.id)?result.transfer:row);fillFolder(result.transfer);renderRows();}
 catch(error){folderError(error.message);}
 finally{folderBusy=false;folderControls();}
});
async function copyFolder(path,button){
 try{
  let copied=false;
  if(navigator.clipboard&&window.isSecureContext){try{await navigator.clipboard.writeText(path);copied=true;}catch(_){}}
  if(!copied){const area=document.createElement('textarea');area.value=path;area.style.cssText='position:fixed;left:-9999px';(folderContext?folderModal:document.body).append(area);area.focus({preventScroll:true});area.select();try{if(!document.execCommand('copy'))throw Error();}finally{area.remove();button.focus({preventScroll:true});}}
  try{navigator.vibrate?.(30);}catch(_){}el('pb-copy-status').textContent=t('Copied!');button.classList.add('text-success');const glyph=button.querySelector('i');const old=glyph.className;glyph.className='fas fa-check';setTimeout(()=>{button.classList.remove('text-success');glyph.className=old;},1200);
 }catch(_){if(folderContext)folderError(t('Copy failed. Select and copy the folder path manually.'));else message(t('Copy failed. Select and copy the folder path manually.'));}
}
el('pb-transfer-folder-copy').addEventListener('click',event=>copyFolder(folderInput.value,event.currentTarget));

el('pb-search').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{if(!saving.size)lastSearch=el('pb-search').value;page=1;refresh();},250);});
panel.addEventListener('click',event=>{
 const button=event.target.closest('[data-list-page]');if(button&&!button.disabled){const action=button.dataset.listPage;page=action==='first'?1:action==='last'?pages:page+(action==='next'?1:-1);refresh();}
 if(event.target.closest('[data-list-retry]'))refresh();
});
panel.addEventListener('change',event=>{
 if(event.target.matches('[data-transfer-filter]')){clearTimeout(timer);page=1;refresh();}
 if(event.target.matches('[data-list-page-size]')){perPage=Number(event.target.value);page=1;refresh();}
 if(event.target.matches('[data-list-page-number]')){page=Math.max(1,Math.min(pages,Number(event.target.value)||1));refresh();}
});
panel.addEventListener('keydown',event=>{if(event.key==='Enter'&&event.target.matches('[data-list-page-number]')){event.preventDefault();event.target.blur();}});
window.addEventListener('focus',()=>{if(!saving.size&&!loading)refresh();});
document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'&&!saving.size&&!loading)refresh();});
(async()=>{
 if(cfg.openTransferId){try{const result=await api('transfer',undefined,{id:cfg.openTransferId});el('pb-search').value=result.transfer.order_number;lastSearch=el('pb-search').value;}catch(error){message(error.message);}}
 await refresh();const target=body.querySelector('.pb-transfer-target');if(target)target.scrollIntoView({block:'center'});
})();
});})();
