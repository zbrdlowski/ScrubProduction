(function () {
'use strict';
$(function () {
const cfg = window.productBuilderConfig;
if (!cfg) return;
const el = id => document.getElementById(id);
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const form = el('pb-product-form');
const pageMode=cfg.pageMode||'builder';
const bundleComponentTypes=value=>cfg.bundleComponents?.[value]||Array.from(new Set(Array.from(value,type=>type==='T'?'G':type)));
const normalizeComponent=row=>row.component_type==='T'?{...row,component_type:'G'}:row;
const t=(key,values={})=>{const text=cfg.language==='sk'?(cfg.translations?.[key]??key):key;return text.replace(/\{(\w+)\}/g,(match,name)=>Object.hasOwn(values,name)?String(values[name]):match);};
// Persist this user's preference for all three pages, without affecting other modules.
if(cfg.languageCookie)document.cookie=cfg.languageCookie+'='+cfg.language+'; Max-Age=31536000; Path=/; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');
document.querySelectorAll('[data-pb-language]').forEach(link=>link.addEventListener('click',event=>{
  if(link.dataset.pbLanguage===cfg.language){event.preventDefault();return;}
  const changedLibrary=Array.from(document.querySelectorAll('.pb-modal form')).some(node=>node.dataset.unsaved==='1');
  if((dirty||changedLibrary)&&!confirm(t('Switch language and discard unsaved changes?'))){event.preventDefault();return;}
  if(form){const url=new URL(link.href);const id=product?.id||(form.inert?cfg.editProductId:0);if(id)url.searchParams.set('edit',id);else url.searchParams.delete('edit');link.href=url;}
  dirty=false;
}));
document.querySelectorAll('.pb-modal form').forEach(node=>{
  for(const event of ['input','change'])node.addEventListener(event,()=>{node.dataset.unsaved='1';});
  node.addEventListener('reset',()=>{delete node.dataset.unsaved;});
});
// Browser-native validation follows browser language; supply module-language messages.
document.querySelectorAll('#product-builder form,.pb-modal form').forEach(node=>{
  node.addEventListener('reset',()=>{node.querySelectorAll('[data-pb-validity]').forEach(input=>{input.setCustomValidity('');delete input.dataset.pbValidity;});});
  node.addEventListener('invalid',event=>{const input=event.target;if(input.validity.customError)return;input.dataset.pbValidity='1';input.setCustomValidity(t(input.validity.valueMissing?(input.tagName==='SELECT'?'Please select an option.':'Please fill out this field.'):'Please enter a valid value.'));},true);
  for(const event of ['input','change'])node.addEventListener(event,event=>{if(event.target.dataset.pbValidity){event.target.setCustomValidity('');delete event.target.dataset.pbValidity;}});
});
// index.php loads Bootstrap 5 and 4. Keep every module modal on one interface.
const modalInterface=$.fn.modal,modalOpeners=new WeakMap();
// The bundled Bootstrap Tooltip owns its matching Popper dependency.
const Tooltip=window.bootstrap.Tooltip;let uniTooltip=null;
function modalAction(id,action) {
  const modal=el(id);
  if(action==='show'){closeComponentFocus();modalOpeners.set(modal,document.activeElement);}
  modalInterface.call($(modal),action);
}
document.querySelectorAll('#product-builder [data-toggle="modal"][data-target^="#pb-"]').forEach(button=>{
  button.addEventListener('click',event=>{
    event.preventDefault();event.stopPropagation();modalAction(button.dataset.target.slice(1),'show');
  });
});
document.querySelectorAll('.pb-modal [data-dismiss="modal"]').forEach(button=>{
  button.addEventListener('click',event=>{
    event.preventDefault();event.stopPropagation();modalAction(button.closest('.pb-modal').id,'hide');
  });
});
$('.pb-modal').on('hidden.bs.modal',function(){
  const opener=modalOpeners.get(this);
  if(opener?.isConnected&&!document.querySelector('.modal.show'))opener.focus({preventScroll:true});
  modalOpeners.delete(this);
});
let categories = [], product = null, specific = null, cache = {}, dirty = false, bundle = '', key = cfg.requestKey, listSeq = 0, modelSeq = 0, skuGeneration = 0, skuPending = false;
const msg = (id,text,ok=false) => { const b=el(id); b.textContent=text; b.className='alert alert-'+(ok?'success':'danger'); b.hidden=!text; };
async function api(action,data,query={}) {
  const opts={credentials:'same-origin',headers:{}};
  if (data !== undefined) { opts.method='POST'; opts.headers['X-CSRF-Token']=cfg.csrf;
    if(data instanceof FormData) opts.body=data;
    else {opts.headers['Content-Type']='application/json'; opts.body=JSON.stringify(data);} }
  const response=await fetch('scripts/product_builder/api.php?'+new URLSearchParams({action,pb_lang:cfg.language,...query}),opts);
  let result;
  try {result=await response.json();} catch (_) {throw new Error(t("Invalid server response. Check that you are signed in and refresh the page."));}
  if(!response.ok || !result.ok) throw new Error(result.error || t("Operation failed."));
  return result;
}
function options(select,rows,placeholder,value,label) {
  select.replaceChildren(new Option(placeholder,''));
  rows.forEach(r=>select.add(new Option(label(r),String(value(r))))); select.disabled=!rows.length;
}
const uni=()=>categories.find(r=>String(r.id)===el('pb-uni-category')?.value);
const kind=()=>form?.querySelector('[name="category_kind"]:checked').value;
function summary() {
  if(!form)return;
  const b=el('pb-bundle').value,c=kind()==='universal'?uni():specific;
  el('pb-summary-bundle').textContent=b||t("Not selected");
  el('pb-summary-category').textContent=c?[c.brand,c.category_name||c.model,c.year_range].filter(Boolean).join(' · '):t("Not selected");
  el('pb-summary-code').textContent=c?.modelcode||'—';
  const categoryText=c?[c.brand,c.category_name||c.model,c.year_range,c.modelcode].filter(Boolean).join(' | '):t("Select a listing category…");
  form.querySelectorAll('[data-component-category-text]').forEach(label=>{label.textContent=categoryText;label.title=categoryText;});
  el('pb-sku').required=!!product;
  const lookingUp=!!el('pb-components').querySelector('[data-lookup-pending="1"]');
  el('pb-generate-sku').disabled=!b||skuPending;el('pb-generate-sku').setAttribute('aria-busy',String(skuPending));
  el('pb-save').disabled=!b||!c||lookingUp||skuPending;
  el('pb-save').textContent=skuPending?t('Generating SKU…'):lookingUp?t("Looking up product code…"):product?t("Save product changes"):el('pb-sku').value.trim()?t("Save product"):t("Save product and generate SKU");
}
function uniSelection() {
  const r=uni(),i=el('pb-uni-info'); uniTooltip?.dispose();uniTooltip=null;i.hidden=!r;
  if(r){i.title=r.included_models||t("Included models have not been specified yet.");uniTooltip=new Tooltip(i,{trigger:'hover focus',container:'body',html:false});}
  el('pb-uni-meta').textContent=r?t('Modelcode: {code} · No license: {unlicensed} · Licensed: {licensed}',{code:r.modelcode,unlicensed:r.price_unlicensed??'—',licensed:r.price_licensed??'—'}):''; summary();
}
function uniBrand(id='',missingLabel='') {
  const rows=categories.filter(r=>r.brand===el('pb-uni-brand').value);
  options(el('pb-uni-category'),rows,rows.length?t("Select a category…"):t("Select a brand first…"),r=>r.id,r=>r.category_name);
  if(id&&missingLabel&&!rows.some(r=>String(r.id)===String(id))){el('pb-uni-category').add(new Option(missingLabel,id));el('pb-uni-category').disabled=false;}
  el('pb-uni-category').value=id; uniSelection();
}
function renderUni() {
  const q=el('pb-uni-search').value.toLocaleLowerCase();
  el('pb-uni-rows').innerHTML=categories.filter(r=>[r.brand,r.category_name,r.modelcode,r.included_models].join(' ').toLocaleLowerCase().includes(q)).map(r=>'<tr><td><strong>'+esc(r.brand)+'</strong><div class="pb-muted">'+esc(r.category_name)+'</div></td><td><code>'+esc(r.modelcode)+'</code></td><td class="pb-coverage">'+esc(r.included_models||'—')+'</td><td>'+(cfg.canEdit?'<button class="btn btn-sm btn-outline-info" type="button" data-uni-edit="'+r.id+("\">"+esc(t("Edit"))+"</button>"):'')+'</td></tr>').join('')||("<tr><td colspan=\"4\" class=\"pb-muted py-4\">"+esc(t("No categories match this selection."))+"</td></tr>");
}
let categoryLoadSeq=0;
async function loadCategories() {
  const seq=++categoryLoadSeq,data=await api('categories');if(seq!==categoryLoadSeq)return;
  // Read the current selection after the request: the user may have changed it meanwhile.
  const selected=form?el('pb-uni-category').value:'';
  categories=data.rows; if(el('pb-stat-categories'))el('pb-stat-categories').textContent=categories.length;
  const brands=[...new Set(categories.map(r=>r.brand))];
  if(form){
    const brand=categories.find(r=>String(r.id)===selected)?.brand||el('pb-uni-brand').value;
    options(el('pb-uni-brand'),brands,t("Select a brand…"),x=>x,x=>x);
    if(brand&&!brands.includes(brand)){el('pb-uni-brand').add(new Option(brand,brand));el('pb-uni-brand').disabled=false;}
    el('pb-uni-brand').value=brand;uniBrand(selected,t('Unavailable category #{id}',{id:selected}));
    el('pb-uni-empty').hidden=categories.length>0;
  }
  if(el('pb-brand-list'))el('pb-brand-list').innerHTML=brands.map(b=>'<option value="'+esc(b)+'"></option>').join('');
  renderUni();
}
const priceFields=Object.keys(cfg.priceFields);
function priceText(row,channel) {
  const value=row[channel+'_price'];
  if(value===null||value===undefined||value==='')return '—';
  const amount=Number(String(value).replace(',','.'));
  return Number.isFinite(amount)?amount.toFixed(2)+' '+(row[channel+'_currency']||'EUR'):'—';
}
function readPrices() {
  return Object.fromEntries(priceFields.flatMap(channel=>[[channel+'_price',el('pb-'+channel+'-price').value.trim()],[channel+'_currency',el('pb-'+channel+'-currency').value]]));
}
function pricesDetail(row) {
  return '<dl class="pb-pricing-grid pb-price-overview">'+priceFields.map(channel=>{
    const state=row.channel_listings?.[channel],listed=state?.status==='listed',url=listed?listingUrl(state.url):'';
    const price=listed?priceText({value_price:state.price,value_currency:state.currency},'value'):priceText(row,channel);
    return '<div><dt>'+esc(t(cfg.priceFields[channel]))+'</dt><dd><span class="pb-price-overview-amount">'+esc(price)+'</span>'+(channel==='recommended'?'':'<div class="small">'+esc(t(listingStatuses[state?.status||'do_not_list']))+'</div>')+(url?'<a href="'+esc(url)+'" target="_blank" rel="noopener noreferrer">'+esc(t('Open listing'))+' <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>':'')+'</dd></div>';
  }).join('')+'</dl>';
}
function weightText(value) {
  return value===null||value===undefined||value===''?'—':Number(value).toFixed(3)+' kg';
}
function savedBoxText(row) {
  if(!row.box_id)return '—';
  const dimensions=['box_length_cm','box_width_cm','box_height_cm'].map(key=>row[key]);
  return '<span>'+esc(row.box_name||t('Box #{id}',{id:row.box_id}))+'</span>'+(dimensions.every(value=>value!==null&&value!==undefined)?'<div class="pb-muted small">'+dimensions.map(value=>esc(Number(value))).join(' × ')+' cm</div>':'');
}
function packagingCell(row) {
  return savedBoxText(row)+'<div class="pb-box-weight small" title="'+esc(t('Total packed weight, including the box'))+'"><span class="pb-muted">'+esc(t('Weight'))+': </span><span data-weight-value>'+esc(weightText(row.weight_kg))+'</span></div>';
}
function copyButton(value,label) {
  return '<button type="button" class="btn pb-copy-code" data-pb-copy="'+esc(value)+'" title="'+esc(label)+'" aria-label="'+esc(label)+'"><i class="far fa-copy" aria-hidden="true"></i></button>';
}
function copyableCode(value,kind) {
  if(value===null||value===undefined||value==='')return '<code>—</code>';
  const label=t(kind==='sku'?'Copy SKU':'Copy modelcode');
  return '<span class="pb-code-copy"><code>'+esc(value)+'</code>'+copyButton(value,label)+'</span>';
}
async function copyListingCode(text) {
  if(navigator.clipboard&&window.isSecureContext){try{await navigator.clipboard.writeText(text);return;}catch(_){} }
  // Match the existing Product Chart fallback for HTTP and denied Clipboard API access.
  const previousFocus=document.activeElement,textarea=document.createElement('textarea');
  textarea.value=text;textarea.readOnly=true;textarea.style.cssText='position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0;font-size:16px';
  textarea.addEventListener('copy',event=>{if(event.clipboardData){event.clipboardData.setData('text/plain',text);event.preventDefault();event.stopPropagation();}});
  (document.querySelector('.pb-modal.show')||componentFocus?.row.querySelector('.pb-product-identity')||document.body).appendChild(textarea);
  try{textarea.focus({preventScroll:true});textarea.select();textarea.setSelectionRange(0,text.length);if(!document.execCommand('copy'))throw new Error('Copy failed');}
  finally{textarea.remove();previousFocus?.focus({preventScroll:true});}
}
const copyFeedbackTimers=new WeakMap();
async function copyCodeButton(button) {
  if(button.dataset.copyPending)return;
  const label=button.dataset.copyLabel||button.getAttribute('aria-label');button.dataset.copyLabel=label;
  clearTimeout(copyFeedbackTimers.get(button));button.dataset.copyPending='1';button.setAttribute('aria-busy','true');
  let success=false;
  try{await copyListingCode(button.dataset.pbCopy);success=true;try{navigator.vibrate?.(25);}catch(_){} }
  catch(_){}
  const feedback=t(success?'Copied!':'Copy failed. Select and copy the value manually.');
  button.classList.toggle('pb-copy-success',success);button.classList.toggle('pb-copy-failed',!success);
  button.querySelector('i').className=success?'fas fa-check':'fas fa-exclamation-triangle';button.title=feedback;button.setAttribute('aria-label',feedback);
  el('pb-copy-status').textContent=feedback;delete button.dataset.copyPending;button.setAttribute('aria-busy','false');
  copyFeedbackTimers.set(button,setTimeout(()=>{if(!button.isConnected)return;button.classList.remove('pb-copy-success','pb-copy-failed');button.querySelector('i').className='far fa-copy';button.title=label;button.setAttribute('aria-label',label);},1800));
}
function packagingDetail(row) {
  return ("<dl class=\"pb-packaging-overview\"><div><dt>"+esc(t("Box"))+"</dt><dd>")+savedBoxText(row)+("</dd></div><div><dt title=\""+esc(t("Total packed weight, including the box"))+"\">"+esc(t("Weight (kg)"))+"</dt><dd>")+esc(weightText(row.weight_kg))+'</dd></div></dl>';
}
function categoryCell(row) {
  const universal=row.category_kind==='universal',label=t(universal?'Universal listing':'Specific listing');
  return '<div class="pb-category-meta"><span class="pb-category-badge" data-kind="'+(universal?'universal':'specific')+'" title="'+esc(label)+'" aria-label="'+esc(label)+'">'+(universal?'U':'S')+'</span>'+copyableCode(row.modelcode,'modelcode')+'</div><div>'+esc([row.uni_brand||row.brand,row.uni_name||row.model,row.year_range].filter(Boolean).join(' · '))+'</div>';
}
const imageRows=new Map(),imagePending=new Set(),imageErrors=new Map();
let imageModalId=null,imageModalVersion=1,imageModalClosing=false,imageModalNext=null;
function rememberImage(row) {
  const id=String(row.id),old=imageRows.get(id),next={id,img_status:row.img_status||'none',img_path:row.img_path||'',img_version:Number(row.img_version)||1};
  if(!old||(!imagePending.has(id)&&next.img_version>=old.img_version))imageRows.set(id,next);
  return imageRows.get(id);
}
function imageCellContents(row) {
  const id=String(row.id),pending=imagePending.has(id),error=imageErrors.get(id);
  return '<div class="pb-img-controls"><select class="form-control form-control-sm pb-img-select" data-img-select="'+esc(id)+'" aria-label="'+esc(t('IMG status'))+'"'+(!cfg.canEdit||pending?' disabled':'')+'>'+[['none','✗'],['R','R'],['A','A'],['Path','Path']].map(([value,label])=>'<option value="'+value+'"'+(row.img_status===value?' selected':'')+'>'+label+'</option>').join('')+'</select>'+(row.img_status==='Path'?'<button type="button" class="btn pb-img-open" data-img-path="'+esc(id)+'" title="'+esc(t(cfg.canEdit?'Edit folder path':'View folder path'))+'" aria-label="'+esc(t(cfg.canEdit?'Edit folder path':'View folder path'))+'"'+(pending?' disabled':'')+'><i class="far fa-folder-open" aria-hidden="true"></i></button>'+copyButton(row.img_path,t('Copy folder path')):'')+'</div>'+(pending?'<span class="pb-img-feedback" role="status">'+esc(t('Saving…'))+'</span>':error?'<span class="pb-img-feedback" role="alert">'+esc(error)+'</span><button type="button" class="btn btn-sm btn-outline-light" data-img-reload="'+esc(id)+'">'+esc(t('Reload IMG status'))+'</button>':'');
}
function imageCell(row) {
  const state=rememberImage(row);
  return '<td class="pb-img-cell" data-img-cell="'+esc(state.id)+'" data-img-status="'+esc(state.img_status)+'" aria-busy="'+imagePending.has(state.id)+'">'+imageCellContents(state)+'</td>';
}
function renderImage(id) {
  const row=imageRows.get(String(id));if(!row)return;
  document.querySelectorAll('[data-img-cell]').forEach(cell=>{if(cell.dataset.imgCell!==String(id))return;cell.dataset.imgStatus=row.img_status;cell.setAttribute('aria-busy',String(imagePending.has(String(id))));cell.innerHTML=imageCellContents(row);});
}
async function saveImage(id,status,path,version) {
  id=String(id);if(!cfg.canEdit||imagePending.has(id))return false;
  imagePending.add(id);imageErrors.delete(id);renderImage(id);
  try {
    const result=await api('save_product_image',{id,img_status:status,img_path:path,img_version:version});
    imageRows.set(id,{...result.image,id,img_path:result.image.img_path||'',img_version:Number(result.image.img_version)});
    el('pb-img-status-message').textContent=t('IMG status saved.');return true;
  } catch(e) {imageErrors.set(id,e.message);return false;}
  finally {imagePending.delete(id);renderImage(id);}
}
async function reloadImage(id) {
  id=String(id);if(imagePending.has(id))return false;
  imagePending.add(id);renderImage(id);
  try {
    const row=(await api('product',undefined,{id})).product;
    imageRows.set(id,{id,img_status:row.img_status||'none',img_path:row.img_path||'',img_version:Number(row.img_version)||1});imageErrors.delete(id);
    if(imageModalId===id){imageModalVersion=imageRows.get(id).img_version;msg('pb-img-message','');el('pb-img-reload').hidden=true;}
    return true;
  } catch(e){imageErrors.set(id,e.message);if(imageModalId===id)msg('pb-img-message',e.message);return false;}
  finally{imagePending.delete(id);renderImage(id);}
}
function updatePathCopy() {
  const input=el('pb-img-path-input'),button=el('pb-img-copy');button.dataset.pbCopy=input.value;button.disabled=!input.value;
}
function openImagePath(id) {
  id=String(id);const row=imageRows.get(id);if(!row||imagePending.has(id))return;
  if(imageModalClosing){imageModalNext=id;return;}
  imageModalId=id;imageModalVersion=row.img_version;
  const pathForm=el('pb-img-form');pathForm.reset();el('pb-img-path-input').value=row.img_path;el('pb-img-path-input').readOnly=!cfg.canEdit;
  el('pb-img-save').hidden=!cfg.canEdit;el('pb-img-reload').hidden=true;msg('pb-img-message','');updatePathCopy();modalAction('pb-img-modal','show');
}
el('pb-products')?.addEventListener('change',async event=>{
  const select=event.target.closest('[data-img-select]');if(!select||!cfg.canEdit)return;
  const id=select.dataset.imgSelect,row=imageRows.get(id),status=select.value;select.value=row.img_status;
  if(status==='Path'){openImagePath(id);return;}
  if(status!==row.img_status)await saveImage(id,status,row.img_path,row.img_version);
});
el('pb-img-form')?.addEventListener('submit',async event=>{
  event.preventDefault();if(!cfg.canEdit||imageModalId===null||imagePending.has(imageModalId))return;
  const pathForm=event.target,id=imageModalId;pathForm.inert=true;msg('pb-img-message','');
  const saved=await saveImage(id,'Path',el('pb-img-path-input').value,imageModalVersion);
  pathForm.inert=false;
  if(saved){delete pathForm.dataset.unsaved;modalAction('pb-img-modal','hide');}
  else{msg('pb-img-message',imageErrors.get(id)||t('Operation failed.'));el('pb-img-reload').hidden=false;}
});
el('pb-img-path-input')?.addEventListener('input',updatePathCopy);
el('pb-img-copy')?.addEventListener('click',event=>copyCodeButton(event.currentTarget));
el('pb-img-reload')?.addEventListener('click',async event=>{event.currentTarget.disabled=true;await reloadImage(imageModalId);el('pb-img-reload').disabled=false;});
$('#pb-img-modal').on('hide.bs.modal',event=>{
  if(imagePending.has(imageModalId)){event.preventDefault();return;}imageModalClosing=true;
}).on('hidden.bs.modal',()=>{
  imageModalClosing=false;imageModalId=null;el('pb-img-form').reset();
  const next=imageModalNext;imageModalNext=null;if(next!==null)openImagePath(next);
}).on('shown.bs.modal',()=>el('pb-img-path-input').focus());
window.addEventListener('beforeunload',event=>{if(imagePending.size||el('pb-img-form')?.dataset.unsaved==='1'){event.preventDefault();event.returnValue='';}});
function productAction(row) {
  if(pageMode==='listings'&&cfg.canEdit)return '<a class="btn btn-sm btn-outline-info" data-product-edit-link="'+esc(row.id)+'" href="index.php?page=product_builder&amp;edit='+encodeURIComponent(row.id)+("\">"+esc(t("Edit"))+"</a>");
  return '<button type="button" class="btn btn-sm btn-outline-info" data-product="'+esc(row.id)+'">'+(form?t("Edit"):t("Detail"))+'</button>';
}
const listingChannels={web:'Web',ebay:'eBay',amazon:'Amazon'};
const listingStatuses={do_not_list:'Do not list',list:'List',listed:'Listed'};
const channelRows=new Map(),channelPending=new Set(),channelErrors=new Map();
const channelKey=(id,channel)=>String(id)+':'+channel;
function listingUrl(value) {
  try{const url=new URL(String(value||''));return ['http:','https:'].includes(url.protocol)&&!url.username&&!url.password?url.href:'';}catch(_){return '';}
}
function rememberChannel(row,channel) {
  const key=channelKey(row.id,channel),old=channelRows.get(key),saved=row.channel_listings?.[channel];
  const next={product_id:Number(row.id),channel,status:'do_not_list',url:null,price:null,currency:'EUR',version:0,...saved};
  const state=old&&(channelPending.has(key)||old.version>Number(next.version))?old:next;
  state.name=row.name;state.sku=row.sku;state.suggestedPrice=row[channel+'_price'];state.suggestedCurrency=row[channel+'_currency']||'EUR';
  channelRows.set(key,state);return state;
}
function channelPrice(state) {
  const listed=state.status==='listed';
  return priceText({value_price:listed?state.price:state.suggestedPrice,value_currency:listed?state.currency:state.suggestedCurrency},'value');
}
function channelContents(state) {
  const key=channelKey(state.product_id,state.channel),pending=channelPending.has(key),error=channelErrors.get(key),url=state.status==='listed'?listingUrl(state.url):'';
  return '<div class="pb-channel-controls"><select class="form-control form-control-sm pb-channel-select" data-channel-select="'+esc(key)+'" aria-label="'+esc(t('{channel} listing status',{channel:listingChannels[state.channel]}))+'"'+(!cfg.canEdit||pending||error?' disabled':'')+'>'+Object.entries(listingStatuses).map(([value,label])=>'<option value="'+value+'"'+(state.status===value?' selected':'')+'>'+esc(t(label))+'</option>').join('')+'</select>'+(cfg.canEdit&&state.status==='listed'?'<button type="button" class="btn pb-channel-edit" data-channel-edit="'+esc(key)+'" title="'+esc(t('Edit listed price and URL'))+'" aria-label="'+esc(t('Edit listed price and URL'))+'"'+(pending?' disabled':'')+'><i class="fas fa-pen" aria-hidden="true"></i></button>':'')+'</div>'+(url?'<a class="pb-channel-link" href="'+esc(url)+'" target="_blank" rel="noopener noreferrer" title="'+esc(url)+'">'+esc(t('Open listing'))+' <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>':'')+'<div class="pb-channel-price" title="'+esc(t(state.status==='listed'?'Listed price':'Suggested price'))+'">'+esc(channelPrice(state))+'</div>'+(pending?'<small class="pb-channel-feedback" role="status">'+esc(t('Saving…'))+'</small>':error?'<small class="pb-channel-feedback" role="alert">'+esc(error)+'</small><button type="button" class="btn btn-sm btn-outline-light" data-channel-reload="'+esc(key)+'">'+esc(t('Reload channel data'))+'</button>':'');
}
function channelCell(row,channel) {
  const state=rememberChannel(row,channel);
  return '<td class="pb-price-column pb-channel-cell" data-price-cell="'+channel+'" data-channel-cell="'+esc(channelKey(row.id,channel))+'" data-listing-status="'+esc(state.status)+'">'+channelContents(state)+'</td>';
}
function renderChannel(key) {
  const state=channelRows.get(key);if(!state)return;
  document.querySelectorAll('[data-channel-cell]').forEach(cell=>{if(cell.dataset.channelCell===key){cell.dataset.listingStatus=state.status;cell.innerHTML=channelContents(state);}});
}
async function saveChannel(key,changes) {
  const state=channelRows.get(key);if(!state||channelPending.has(key))return null;
  channelPending.add(key);channelErrors.delete(key);renderChannel(key);
  try{
    const response=await api('save_channel_listing',{product_id:state.product_id,channel:state.channel,version:state.version,...changes});
    channelRows.set(key,{...state,...response.listing});
    el('pb-channel-status-message').textContent=t('{channel}: {status}',{channel:listingChannels[state.channel],status:t(listingStatuses[response.listing.status])});
    return response.listing;
  }catch(e){channelErrors.set(key,e.message);throw e;}
  finally{channelPending.delete(key);renderChannel(key);}
}
async function reloadChannel(key) {
  const state=channelRows.get(key);if(!state||channelPending.has(key))return;
  channelPending.add(key);renderChannel(key);
  const inModal=channelModalKey===key;
  if(inModal){el('pb-channel-form').inert=true;el('pb-channel-modal').querySelector('.close').disabled=true;}
  try{
    const row=(await api('product',undefined,{id:state.product_id})).product;
    channelPending.delete(key);channelRows.delete(key);rememberChannel(row,state.channel);channelErrors.delete(key);
    if(channelModalKey===key)fillChannelModal(key);
  }catch(e){channelErrors.set(key,e.message);if(channelModalKey===key)msg('pb-channel-message',e.message);}
  finally{
    channelPending.delete(key);renderChannel(key);
    if(inModal){el('pb-channel-form').inert=false;el('pb-channel-modal').querySelector('.close').disabled=false;}
  }
}
let channelModalKey=null,channelModalClosing=false,channelModalNext=null;
function fillChannelModal(key) {
  const state=channelRows.get(key);if(!state)return;
  const hasPrice=state.price!==null&&state.price!==undefined&&state.price!=='';
  el('pb-channel-title').textContent=t('{channel} listing',{channel:listingChannels[state.channel]});
  el('pb-channel-product').textContent=[state.name,state.sku].filter(Boolean).join(' · ');
  el('pb-channel-price').value=hasPrice?state.price:(state.suggestedPrice??'');
  el('pb-channel-currency').value=hasPrice?state.currency:state.suggestedCurrency;
  el('pb-channel-url').value=state.url||'';
  msg('pb-channel-message','');el('pb-channel-reload').hidden=true;
  el('pb-channel-form').dataset.unsaved='0';
  el('pb-channel-form').querySelectorAll('[data-pb-validity]').forEach(input=>{input.setCustomValidity('');delete input.dataset.pbValidity;});
}
function openChannelModal(key) {
  if(!cfg.canEdit||!channelRows.has(key)||channelPending.has(key))return;
  if(channelModalClosing){channelModalNext=key;return;}
  channelModalKey=key;fillChannelModal(key);
  modalAction('pb-channel-modal','show');
  const cell=Array.from(document.querySelectorAll('[data-channel-cell]')).find(node=>node.dataset.channelCell===key);
  if(cell)modalOpeners.set(el('pb-channel-modal'),cell.querySelector('[data-channel-select]'));
}
$('#pb-channel-modal').on('hide.bs.modal',event=>{if(channelModalKey&&channelPending.has(channelModalKey)){event.preventDefault();return;}channelModalClosing=true;}).on('hidden.bs.modal',()=>{
  channelModalKey=null;channelModalClosing=false;el('pb-channel-form').dataset.unsaved='0';
  const next=channelModalNext;channelModalNext=null;if(next)openChannelModal(next);
});
el('pb-channel-form')?.addEventListener('submit',async event=>{
  event.preventDefault();const f=event.currentTarget,key=channelModalKey;if(!cfg.canEdit||!key||!f.reportValidity())return;
  const payload={status:'listed',price:el('pb-channel-price').value.trim(),currency:el('pb-channel-currency').value,url:el('pb-channel-url').value.trim()};
  if(!listingUrl(payload.url)){msg('pb-channel-message',t('Enter a valid http:// or https:// listing URL.'));return;}
  f.inert=true;el('pb-channel-modal').querySelector('.close').disabled=true;
  try{await saveChannel(key,payload);f.dataset.unsaved='0';modalAction('pb-channel-modal','hide');}
  catch(e){msg('pb-channel-message',e.message);el('pb-channel-reload').hidden=false;}
  finally{f.inert=false;el('pb-channel-modal').querySelector('.close').disabled=false;}
});
el('pb-channel-reload')?.addEventListener('click',()=>{if(channelModalKey)reloadChannel(channelModalKey);});
el('pb-products')?.addEventListener('change',async event=>{
  const select=event.target.closest('[data-channel-select]');if(!select||!cfg.canEdit)return;
  const key=select.dataset.channelSelect,status=select.value,state=channelRows.get(key);if(!state)return;
  select.value=state.status;
  if(status==='listed'){openChannelModal(key);return;}
  try{await saveChannel(key,{status});}catch(_){}
});
el('pb-products')?.addEventListener('click',event=>{
  const edit=event.target.closest('[data-channel-edit]');if(edit){openChannelModal(edit.dataset.channelEdit);return;}
  const reload=event.target.closest('[data-channel-reload]');if(reload)reloadChannel(reload.dataset.channelReload);
});

function productCreatedDate(value) {
  // Format the database date without converting timezones in the browser.
  const match=String(value||'').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T]|$)/);
  if(!match||match[1]==='0000'||Number(match[2])<1||Number(match[2])>12||Number(match[3])<1||Number(match[3])>31)return {key:'',short:'—',label:t("No creation date")};
  return {key:match[1]+'-'+match[2]+'-'+match[3],short:match[3]+'.'+match[2]+'.'+match[1].slice(-2),label:match[3]+'.'+match[2]+'.'+match[1]};
}
let listPage=1,listPerPage=100,listPages=1,listTotal=0,listFrom=0,listTo=0,listLoading=false,listLoaded=false;
function renderPagination() {
  document.querySelectorAll('[data-list-pagination]').forEach(pager=>{
    pager.querySelector('[data-list-range]').textContent=listLoading?t('Loading products…'):listLoaded?t('Showing {from}–{to} of {total} listings.',{from:listFrom,to:listTo,total:listTotal}):t('Unable to load the data. Please try again.');
    const size=pager.querySelector('[data-list-page-size]');size.value=String(listPerPage);size.disabled=listLoading;
    const number=pager.querySelector('[data-list-page-number]');number.value=String(listPage);number.max=String(listPages);number.disabled=listLoading||!listLoaded||!listTotal;
    pager.querySelector('[data-list-page-count]').textContent=t('of {pages}',{pages:listPages});
    pager.querySelectorAll('[data-list-page]').forEach(button=>{button.disabled=listLoading||!listLoaded||(['first','previous'].includes(button.dataset.listPage)?listPage<=1:listPage>=listPages);});
  });
  el('pb-saved-products')?.setAttribute('aria-busy',String(listLoading));
}
// Keep the expanded listing in place and lift only its two rows above the page dimmer.
let componentFocus=null,componentsBackdrop=null;
function closeComponentFocus({restoreFocus=false}={}) {
  if(!componentFocus)return;
  const {toggle,row,detail}=componentFocus;
  componentFocus=null;
  toggle.setAttribute('aria-expanded','false');detail.hidden=true;
  row.classList.remove('pb-components-focused');detail.classList.remove('pb-components-focused');
  if(componentsBackdrop)componentsBackdrop.hidden=true;
  if(restoreFocus&&toggle.isConnected)toggle.focus({preventScroll:true});
}
function openComponentFocus(toggle,detail) {
  closeComponentFocus();
  if(!componentsBackdrop){
    componentsBackdrop=document.createElement('div');
    componentsBackdrop.className='pb-components-backdrop';componentsBackdrop.setAttribute('aria-hidden','true');
    componentsBackdrop.addEventListener('click',()=>closeComponentFocus({restoreFocus:true}));
    document.body.append(componentsBackdrop);
  }
  const row=toggle.closest('.pb-product-row');
  componentFocus={toggle,row,detail};
  toggle.setAttribute('aria-expanded','true');detail.hidden=false;
  row.classList.add('pb-components-focused');detail.classList.add('pb-components-focused');
  componentsBackdrop.hidden=false;
}
document.addEventListener('keydown',event=>{
  if(event.key==='Escape'&&componentFocus&&!document.querySelector('.modal.show')){
    event.preventDefault();closeComponentFocus({restoreFocus:true});
  }
});
document.addEventListener('focusin',event=>{
  if(componentFocus&&!componentFocus.row.contains(event.target)&&!componentFocus.detail.contains(event.target))closeComponentFocus();
});
function showListLoading() {
  closeComponentFocus();
  listLoading=true;renderPagination();
  el('pb-products').innerHTML='<tr><td colspan="12" class="text-center py-4 pb-muted">'+esc(t('Loading products…'))+'</td></tr>';
}
async function loadProducts({page=listPage,perPage=listPerPage}={}) {
  if(!el('pb-products'))return;
  const seq=++listSeq;showListLoading();
  try {
    const data=await api('products',undefined,{q:el('pb-search').value,page,per_page:perPage}); if(seq!==listSeq)return;
    const pagination=data.pagination||{page:1,per_page:perPage,pages:1,total:data.rows.length,from:data.rows.length?1:0,to:data.rows.length};
    listPage=pagination.page;listPerPage=pagination.per_page;listPages=pagination.pages;listTotal=pagination.total;listFrom=pagination.from;listTo=pagination.to;listLoaded=true;
    Object.entries(data.stats).forEach(([k,v])=>{if(el('pb-stat-'+k))el('pb-stat-'+k).textContent=v;});
    let previousDay=null;
    el('pb-products').innerHTML=data.rows.map((r,index)=>{
      const created=productCreatedDate(r.created_at);
      const separator=created.key!==previousDay?'<tr class="pb-day-separator-row"><td colspan="12"><div class="pb-day-separator"><span>'+esc(created.label)+'</span></div></td></tr>':'';
      previousDay=created.key;
      return separator+'<tr class="pb-product-row'+(index%2===0?' pb-row-tinted':'')+'"><td class="pb-created-column" title="'+esc(r.created_at||t("No creation date"))+'">'+(created.key?'<time datetime="'+esc(created.key)+'">'+esc(created.short)+'</time>':'—')+'</td><td class="pb-product-identity"><strong>'+esc(r.name)+'</strong><div>'+copyableCode(r.sku,'sku')+'</div></td><td><span class="badge pb-bundle-badge" data-bundle="'+esc(r.bundle_type)+'">'+esc(r.bundle_type)+'</span></td><td class="pb-category-cell">'+categoryCell(r)+'</td>'+imageCell(r)+priceFields.map(channel=>channel==='recommended'?'<td class="pb-price-column" data-price-cell="'+channel+'">'+esc(priceText(r,channel))+'</td>':channelCell(r,channel)).join('')+'<td data-box-cell>'+packagingCell(r)+'</td><td><button type="button" class="btn btn-sm btn-outline-info pb-expand" data-product-expand="'+r.id+'" aria-expanded="false" aria-controls="pb-list-components-'+r.id+'" aria-label="'+esc(t('Show components for {name}',{name:r.name}))+'"><i class="fas fa-chevron-right mr-1" aria-hidden="true"></i> '+esc(t('{count} components',{count:Number(r.component_count)}))+'</button></td><td>'+productAction(r)+'</td></tr><tr id="pb-list-components-'+r.id+'" class="pb-expanded-row" hidden><td colspan="12"><div data-component-detail role="region" aria-label="'+esc(t('Components of {name}',{name:r.name}))+'"></div></td></tr>';
    }).join('')||("<tr><td colspan=\"12\" class=\"text-center py-4 pb-muted\">"+esc(t("No products match this selection yet."))+"</td></tr>");
  } catch(e) {
    if(seq!==listSeq)return;
    listLoaded=false;
    el('pb-products').innerHTML='<tr><td colspan="12" class="text-center py-4">'+esc(e.message)+' <button type="button" class="btn btn-sm btn-outline-info ml-2" data-list-retry>'+esc(t('Retry'))+'</button></td></tr>';
    // Keep list failures beside the retry button; a successful retry clears them.
  } finally {
    if(seq===listSeq){listLoading=false;renderPagination();}
  }
}
let designs=[],graphicsVersions=[],designGroup='G',designsReady=false,designLoadSeq=0;
const designGroupName=type=>type==='V'?t("Graphics versions"):type==='G'?t("Graphics"):t("Seat Covers");
const currentDesignLibrary=()=>designGroup==='V'?graphicsVersions:designs.filter(d=>d.component_type===designGroup);
const libraryText=(design,version,values={})=>t(designGroup==='V'?version:design,values);
function designPicker(type,row={}) {
  if(!['G','S'].includes(type))return '';
  const selected=String(row.design_id||''),choices=designs.filter(d=>d.component_type===type);
  if(selected&&!choices.some(d=>String(d.id)===selected))choices.push({id:selected,name:row.design_name||t('Unavailable design #{id}',{id:selected})});
  return '<label class="pb-design-picker"><span>'+t(type==='G'?'Graphics design':'Seat Covers design')+'</span><select class="form-control form-control-sm" data-field="design_id" aria-label="'+esc(t('Design'))+' '+type+'"'+(!designsReady?' disabled':'')+("><option value=\"\">"+esc(t("Select a design…"))+"</option>")+choices.map(d=>'<option value="'+esc(d.id)+'"'+(String(d.id)===selected?' selected':'')+'>'+esc(d.name)+'</option>').join('')+'</select></label>';
}
function graphicsVersionPicker(row={}) {
  const selected=String(row.graphics_version_id||''),choices=[...graphicsVersions];
  if(selected&&!choices.some(v=>String(v.id)===selected))choices.push({id:selected,name:row.graphics_version_name||t('Unavailable version #{id}',{id:selected})});
  return ("<label class=\"pb-identity-field pb-graphics-version\"><span>"+esc(t("Version"))+"</span><select class=\"form-control form-control-sm\" data-field=\"graphics_version_id\" aria-label=\""+esc(t("Graphics version"))+"\"")+(!designsReady?' disabled':'')+("><option value=\"\">"+esc(t("Select…"))+"</option>")+choices.map(v=>'<option value="'+esc(v.id)+'"'+(String(v.id)===selected?' selected':'')+'>'+esc(v.name)+'</option>').join('')+'</select></label>';
}
function generatedComponentName(type,row) {
  const design=designs.find(d=>String(d.id)===String(row.design_id)),name=design?.name||row.design_name||'';
  if(!name)return '';
  const version=type==='G'?(graphicsVersions.find(v=>String(v.id)===String(row.graphics_version_id))?.name||row.graphics_version_name||''):'';
  return [name,version].filter(Boolean).join(' ');
}
function updateComponentName(row) {
  const value={design_id:row.querySelector('[data-field="design_id"]').value,graphics_version_id:row.querySelector('[data-field="graphics_version_id"]')?.value};
  const next=generatedComponentName(row.dataset.componentRow,value),previous=row.dataset.generatedName||'',input=row.querySelector('[data-field="label"]');
  // Keep text appended after the generated design/version while the user changes a selection.
  const suffix=previous&&input.value.startsWith(previous)&&/^\s/.test(input.value.slice(previous.length))?input.value.slice(previous.length):'';
  if(next)input.value=next+suffix;
  row.dataset.generatedName=next;
}
function initDesignPickers() {
  if(!form||!$.fn.select2)return;
  $(el('pb-components')).find('[data-field="design_id"]').each(function(){
    if(!$(this).hasClass('select2-hidden-accessible'))$(this).select2({width:'100%',placeholder:t("Select a design…"),allowClear:true,dropdownCssClass:'pb-design-dropdown',language:{noResults:()=>t('No results found'),searching:()=>t('Searching…'),removeAllItems:()=>t('Remove all items'),removeItem:()=>t('Remove item'),search:()=>t('Search')}});
  });
}
function destroyDesignPickers(root) {
  if($.fn.select2)$(root).find('.select2-hidden-accessible[data-field="design_id"]').select2('destroy');
}
function refreshDesignPickers() {
  if(!form)return;
  el('pb-components').querySelectorAll('[data-field="design_id"],[data-field="graphics_version_id"]').forEach(select=>{
    const isDesign=select.dataset.field==='design_id',value=select.value,name=select.selectedOptions[0]?.textContent;
    const enhanced=isDesign&&$(select).hasClass('select2-hidden-accessible');
    const open=enhanced&&$(select).next('.select2-container').hasClass('select2-container--open');
    const search=open?$('.select2-container--open .select2-search__field').val():'';
    if(open)$(select).select2('close');
    const rows=isDesign?designs.filter(d=>d.component_type===select.closest('[data-component-row]').dataset.componentRow):graphicsVersions;
    select.replaceChildren(new Option(t(isDesign?'Select a design…':'Select…'),''));
    rows.forEach(row=>select.add(new Option(row.name,String(row.id))));
    if(value&&!rows.some(row=>String(row.id)===value))select.add(new Option(name||t(isDesign?'Unavailable design #{id}':'Unavailable version #{id}',{id:value}),value));
    select.value=value;select.disabled=!designsReady;
    // Update Select2 only; never fire the component-name generator or mark the draft dirty.
    if(enhanced)$(select).trigger('change.select2');
    if(open){$(select).select2('open');$('.select2-container--open .select2-search__field').val(search).trigger('input');}
  });
  initDesignPickers();
}
function renderDesigns() {
  const query=el('pb-design-search').value.trim().toLocaleLowerCase();
  const group=currentDesignLibrary(),rows=group.filter(d=>d.name.toLocaleLowerCase().includes(query));
  el('pb-design-rows').innerHTML=rows.map(d=>'<tr><td>'+esc(d.name)+'</td><td>'+Number(d.usage_count||0)+'</td><td>'+(cfg.canEdit?'<button type="button" class="btn btn-sm btn-outline-info" data-design-edit="'+d.id+("\">"+esc(t("Edit"))+"</button>"):'')+'</td></tr>').join('')||'<tr><td colspan="3" class="pb-muted py-4">'+esc(libraryText('No designs found.','No versions found.'))+'</td></tr>';
  el('pb-design-count').textContent=libraryText('{shown} of {total} designs','{shown} of {total} versions',{shown:rows.length,total:group.length});
}
async function loadDesigns() {
  const seq=++designLoadSeq,data=await api('designs');if(seq!==designLoadSeq)return;
  designs=data.rows.sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'}));graphicsVersions=data.versions||[];designsReady=true;
  refreshDesignPickers();renderDesigns();
}
const designForm=el('pb-design-form');
function designEditor(row={}) {
  if(!designForm)return;
  designForm.reset();['id','version','name'].forEach(key=>{designForm.elements[key].value=row[key]??'';});
  designForm.elements.component_type.value=designGroup;
  el('pb-design-edit-title').textContent=row.id?libraryText('Edit design','Edit version'):libraryText('New design','New version');
  el('pb-design-name-label').textContent=designGroup==='V'?t("Version name"):t("Design name");
  el('pb-design-name').maxLength=designGroup==='V'?30:255;
  el('pb-design-save').textContent=libraryText('Save design','Save version');
  el('pb-version-order-group').hidden=designGroup!=='V';el('pb-version-order').disabled=designGroup!=='V';
  el('pb-version-order').value=row.sort_order??Math.min(9999,Math.max(0,...graphicsVersions.map(v=>Number(v.sort_order)))+1);
  el('pb-design-delete').hidden=!row.id;el('pb-design-delete').disabled=Number(row.usage_count||0)>0;
  el('pb-design-usage').textContent=Number(row.usage_count||0)>0?t('Used by {count} listing(s). Saved component names are preserved when this entry is renamed.',{count:row.usage_count}):'';
}
document.querySelectorAll('[data-design-manager]').forEach(button=>button.addEventListener('click',async()=>{
  designGroup=button.dataset.designManager;el('pb-design-title').textContent=t(designGroup==='V'?'Graphics versions':designGroup==='G'?'Graphics designs':'Seat Covers designs');
  el('pb-design-column').textContent=designGroup==='V'?t("Version"):t("Design name");el('pb-design-search').placeholder=designGroup==='V'?t("Search versions…"):t("Search designs…");
  el('pb-design-search').setAttribute('aria-label',t(designGroup==='V'?'Search versions':'Search designs'));el('pb-design-refresh').title=t(designGroup==='V'?'Refresh versions':'Refresh designs');el('pb-design-refresh').setAttribute('aria-label',el('pb-design-refresh').title);
  el('pb-design-search').value='';msg('pb-design-message','');designEditor();renderDesigns();modalAction('pb-design-modal','show');
  try{await loadDesigns();}catch(e){msg('pb-design-message',e.message);}
}));
el('pb-design-search')?.addEventListener('input',renderDesigns);
el('pb-design-refresh')?.addEventListener('click',async()=>{
  try{await loadDesigns();designEditor();msg('pb-design-message','');}catch(e){msg('pb-design-message',e.message);}
});
if(designForm){
  el('pb-design-new').addEventListener('click',()=>{designEditor();el('pb-design-name').focus();});
  el('pb-design-rows').addEventListener('click',event=>{const button=event.target.closest('[data-design-edit]');if(button)designEditor(currentDesignLibrary().find(d=>String(d.id)===button.dataset.designEdit));});
  designForm.addEventListener('submit',async event=>{
    event.preventDefault();if(!designForm.reportValidity())return;designForm.inert=true;
    try{const saved=await api(designGroup==='V'?'save_graphics_version':'save_design',Object.fromEntries(new FormData(designForm)));await loadDesigns();designEditor(currentDesignLibrary().find(d=>String(d.id)===String(saved.id)));msg('pb-design-message',designGroup==='V'?t("Version saved."):t("Design saved."),true);}
    catch(e){msg('pb-design-message',e.message);}finally{designForm.inert=false;}
  });
  el('pb-design-delete').addEventListener('click',async()=>{
    if(!confirm(libraryText('Delete design {name}?','Delete version {name}?',{name:designForm.elements.name.value})))return;designForm.inert=true;
    try{await api(designGroup==='V'?'delete_graphics_version':'delete_design',Object.fromEntries(new FormData(designForm)));await loadDesigns();designEditor();msg('pb-design-message',designGroup==='V'?t("Version deleted."):t("Design deleted."),true);}
    catch(e){msg('pb-design-message',e.message);}finally{designForm.inert=false;}
  });
}

let boxes=[],boxLoadSeq=0,boxesReady=false,productWeightEdited=false;
function prefillBoxWeight() {
  if(product||productWeightEdited)return;
  const box=boxes.find(row=>String(row.id)===el('pb-product-box').value),input=el('pb-product-weight');
  input.value=box?.weight_kg??'';input.setCustomValidity('');delete input.dataset.pbValidity;
}
function renderBoxPicker(selected) {
  const select=el('pb-product-box');if(!select)return;
  selected=String(selected??select.value??'');
  select.replaceChildren(new Option(t("No box selected"),''));
  for(const box of boxes)select.add(new Option(box.name+' · '+[box.length_cm,box.width_cm,box.height_cm].map(Number).join(' × ')+' cm',String(box.id)));
  if(selected&&!boxes.some(box=>String(box.id)===selected))select.add(new Option(String(product?.box_id)===selected&&product?.box_name?product.box_name:t('Unavailable box #{id}',{id:selected}),selected));
  select.value=selected;select.disabled=!boxesReady;select.title=select.selectedOptions[0]?.textContent||'';
}
const boxForm=el('pb-box-form');
function renderBoxes() {
  const query=el('pb-box-search').value.trim().toLocaleLowerCase(),rows=boxes.filter(row=>row.name.toLocaleLowerCase().includes(query));
  el('pb-box-rows').innerHTML=rows.map(row=>'<tr><td>'+esc(row.name)+'</td><td class="pb-box-measure">'+['length_cm','width_cm','height_cm'].map(key=>esc(Number(row[key]).toFixed(2))).join(' × ')+'</td><td class="pb-box-measure">'+esc(Number(row.weight_kg).toFixed(3))+'</td><td>'+(cfg.canEdit?'<button type="button" class="btn btn-sm btn-outline-info" data-box-edit="'+esc(row.id)+("\">"+esc(t("Edit"))+"</button>"):'')+'</td></tr>').join('')||'<tr><td colspan="4" class="pb-muted py-4">'+(boxes.length?t("No boxes match this search."):t("No boxes yet."))+'</td></tr>';
  el('pb-box-count').textContent=t('{shown} of {total} boxes',{shown:rows.length,total:boxes.length});
}
async function loadBoxes() {
  const seq=++boxLoadSeq,data=await api('boxes');if(seq!==boxLoadSeq)return;
  boxes=data.rows;boxesReady=true;renderBoxes();renderBoxPicker();
}
function boxEditor(row={}) {
  if(!boxForm)return;
  boxForm.reset();['id','version','name','length_cm','width_cm','height_cm','weight_kg'].forEach(key=>{boxForm.elements[key].value=row[key]??(key==='weight_kg'?'0':'');boxForm.elements[key].setCustomValidity('');});
  el('pb-box-edit-title').textContent=row.id?t("Edit box"):t("New box");el('pb-box-delete').hidden=!row.id;el('pb-box-delete').disabled=Number(row.usage_count||0)>0;
  el('pb-box-delete').title=Number(row.usage_count||0)>0?t("This box is used by saved products."):'';el('pb-box-usage').textContent=Number(row.usage_count||0)>0?t('Used by {count} product(s). Change their box before deleting this one.',{count:row.usage_count}):'';
}
$('#pb-box-modal').on('show.bs.modal',async()=>{
  el('pb-box-search').value='';msg('pb-box-message','');boxEditor();renderBoxes();
  try{await loadBoxes();}catch(e){msg('pb-box-message',e.message);}
});
el('pb-box-search')?.addEventListener('input',renderBoxes);
el('pb-box-refresh')?.addEventListener('click',async()=>{
  try{await loadBoxes();boxEditor();msg('pb-box-message','');}catch(e){msg('pb-box-message',e.message);}
});
if(boxForm){
  el('pb-box-new').addEventListener('click',()=>{boxEditor();msg('pb-box-message','');el('pb-box-name').focus();});
  el('pb-box-rows').addEventListener('click',event=>{const button=event.target.closest('[data-box-edit]');if(button){boxEditor(boxes.find(row=>String(row.id)===button.dataset.boxEdit));msg('pb-box-message','');}});
  for(const key of ['length_cm','width_cm','height_cm'])boxForm.elements[key].addEventListener('input',()=>boxForm.elements[key].setCustomValidity(''));
  boxForm.addEventListener('submit',async event=>{
    event.preventDefault();
    for(const key of ['length_cm','width_cm','height_cm']){const input=boxForm.elements[key];input.setCustomValidity(Number(input.value.replace(',','.'))===0?t("Enter a dimension greater than zero."):'');}
    if(!boxForm.reportValidity())return;boxForm.inert=true;
    try{const saved=await api('save_box',Object.fromEntries(new FormData(boxForm)));await loadBoxes();boxEditor(boxes.find(row=>String(row.id)===String(saved.id)));msg('pb-box-message',t("Box saved."),true);await loadProducts();}
    catch(e){msg('pb-box-message',e.message);}finally{boxForm.inert=false;}
  });
  el('pb-box-delete').addEventListener('click',async()=>{
    if(!confirm(t('Delete box {name}?',{name:boxForm.elements.name.value})))return;boxForm.inert=true;
    try{await api('delete_box',Object.fromEntries(new FormData(boxForm)));await loadBoxes();boxEditor();msg('pb-box-message',t("Box deleted."),true);await loadProducts();}
    catch(e){msg('pb-box-message',e.message);}finally{boxForm.inert=false;}
  });
}

function readComponents() {
  return Array.from(el('pb-components').querySelectorAll('[data-component-row]'),row=>{
    const data={component_type:row.dataset.componentRow,generated_name:row.dataset.generatedName,lookup_pending:row.dataset.lookupPending==='1',lookup_color_only:row.dataset.lookupColorOnly==='1'};row.querySelectorAll('[data-field]').forEach(i=>{data[i.dataset.field]=i.value;});
    data.options=Object.fromEntries(Array.from(row.querySelectorAll('[data-spec]'),input=>[input.dataset.spec,input.value]));return data;
  });
}
const disabledGraphicsSpecs=new Set(['graphics_name','graphics_name_font','graphics_number_font','graphics_printer']);
function specFields(type,row={}) {
  const fields=(cfg.specFields?.[type]||[]).map(field=>({...field}));
  // Retain saved settings even if their definition is later hidden or removed in Settings.
  for(const key of Object.keys(row.options||{}))if(!fields.some(field=>field.key===key))fields.push({key,label:key.replace(/_/g,' '),type:/note/i.test(key)||String(row.options[key]).includes('\n')?'textarea':'text',storage:'options',items:[]});
  if(!fields.some(field=>field.storage==='notes'))fields.push({key:'component_notes',label:t("Component details"),type:'textarea',storage:'notes',items:[]});
  // Listing-level settings are narrower than the shared Custom Orders form.
  if(type==='G')for(const field of fields){
    if(disabledGraphicsSpecs.has(field.key))field.disabled=true;
    if(field.key==='graphics_number'){
      field.type='select';field.numberChoice=true;
      field.items=[{value:'Yes',label:'Yes'},{value:'No',label:'No'}];
    }
  }
  return fields;
}
function componentSpecValue(field,row) {
  const value=field.storage==='notes'?row.notes:row.options?.[field.key];
  if(!field.numberChoice)return value;
  const saved=String(value??'').trim();
  // Legacy rider numbers (including 0) mean that this listing includes a number.
  return saved?(saved.toLowerCase()==='no'?'No':'Yes'):'';
}
function specControl(type,field,row) {
  const value=componentSpecValue(field,row);
  const attribute=(field.storage==='notes'?'data-field="notes"':'data-spec="'+esc(field.key)+'"')+(field.disabled?' disabled':'');
  const aria=field.storage==='notes'?t('Component details {type}',{type}):t(field.label==='Name'?'Rider name':field.label)+' '+type;
  let control;
  if(field.type==='select'){
    const choices=[...(field.items||[])];
    if(value&&!choices.some(option=>option.value===value))choices.push({value,label:value});
    control='<select '+attribute+' aria-label="'+esc(aria)+("\" class=\"form-control form-control-sm\"><option value=\"\">"+esc(t("Select…"))+"</option>")+choices.map(option=>'<option value="'+esc(option.value)+'"'+(option.value===value?' selected':'')+'>'+esc(t(option.label))+'</option>').join('')+'</select>';
  }else if(field.type==='textarea')control='<textarea '+attribute+' aria-label="'+esc(aria)+'" class="form-control form-control-sm" rows="1" maxlength="10000">'+esc(value)+'</textarea>';
  else control='<input '+attribute+' aria-label="'+esc(aria)+'" class="form-control form-control-sm" maxlength="10000" value="'+esc(value)+'">';
  return '<label class="pb-spec-field'+(field.disabled?' pb-spec-field-disabled':'')+'"><span>'+esc(t(field.label==='Name'?'Rider name':field.label))+'</span>'+control+'</label>';
}
function componentNameFields(type,row) {
  const hints={G:t("Graphics design name"),F:t("Fitting / installation"),P:t("Plastic kit or part name"),S:t("Seat cover design name"),T:t("Trim kit name")};
  const name=("<input aria-label=\""+esc(t("Component name"))+" ")+type+'" class="form-control form-control-sm" data-field="label" required maxlength="255" placeholder="'+hints[type]+'" value="'+esc(row.label||(type==='F'?'Fitting':''))+'">';
  const reference='<label class="pb-identity-field"><span>'+(type==='P'?t("Product code / barcode"):t("Reference / code"))+("</span><input aria-label=\""+esc(t("Reference"))+" ")+type+'" class="form-control form-control-sm" data-field="reference" maxlength="100" placeholder="'+(type==='P'?t("Enter barcode"):t("Optional reference"))+'" value="'+esc(row.reference)+'"></label>';
  const labeledName='<label class="pb-identity-field"><span>'+esc(t("Component name"))+'</span>'+name+'</label>';
  if(['G','S'].includes(type))return '<div class="pb-design-fields'+(type==='G'?' pb-graphics-fields':'')+'">'+designPicker(type,row)+(type==='G'?graphicsVersionPicker(row):'')+labeledName+reference+'</div>';
  if(type==='P')return '<div class="pb-component-identity">'+reference+labeledName+'<label class="pb-identity-field"><span>'+esc(t("Color"))+'</span><input aria-label="'+esc(t("Color P"))+'" class="form-control form-control-sm" data-field="color" maxlength="100" placeholder="'+esc(t("Color"))+'" value="'+esc(row.color)+'"></label></div>';
  if(type==='F')return '<div class="pb-component-identity pb-fitting-identity">'+labeledName+reference+'</div>';
  return name+'<div class="pb-item-meta pb-item-meta-single">'+reference+'</div>';
}
function componentRow(type,row={}) {
  const fields=specFields(type,row),main=fields.filter(field=>field.type!=='textarea'),notes=fields.filter(field=>field.type==='textarea');
  return '<div class="pb-component-row pb-item-'+type+'" data-component-row="'+type+'" data-generated-name="'+esc(row.generated_name??generatedComponentName(type,row))+'"'+(row.lookup_pending?' data-resume-lookup="1"':'')+(row.lookup_color_only?' data-lookup-color-only="1"':'')+'>'+
    '<div class="pb-item-grid">'+
      ("<div class=\"pb-item-cell pb-item-type\"><div class=\"pb-item-heading\">"+esc(t("Type"))+"</div><div class=\"pb-item-cell-body\"><span class=\"pb-type pb-type-")+type+'" title="'+esc(t(cfg.types[type]))+'">'+type+'</span></div></div>'+
      ("<div class=\"pb-item-cell pb-item-name\"><div class=\"pb-item-heading\">"+esc(t("Component name"))+"</div><div class=\"pb-item-cell-body\">")+
        componentNameFields(type,row)+'</div></div>'+
      ("<div class=\"pb-item-cell pb-item-qty\"><div class=\"pb-item-heading\">"+esc(t("Qty"))+"</div><div class=\"pb-item-cell-body\"><input aria-label=\""+esc(t("Quantity"))+" ")+type+'" type="number" min="1" max="9999" step="1" required class="form-control form-control-sm" data-field="quantity" value="'+esc(row.quantity||1)+'"></div></div>'+
      ("<div class=\"pb-item-cell pb-item-category\"><div class=\"pb-item-heading\">"+esc(t("Category Info"))+"</div><div class=\"pb-item-cell-body\"><button type=\"button\" class=\"btn btn-sm btn-outline-info pb-category-trigger\" data-component-category title=\""+esc(t("Change the category for this listing"))+"\"><span data-component-category-text>"+esc(t("Select a listing category…"))+"</span><i class=\"fas fa-chevron-right\" aria-hidden=\"true\"></i></button>")+
        (type==='P'?'<small class="pb-muted pb-lookup-status" data-lookup-status role="status">'+(row.reference?t("Change the product code to reload its name and color."):t("Enter a product code to fill in its name and color."))+'</small>':'')+'</div></div>'+
      ("<div class=\"pb-item-cell pb-item-delete\"><div class=\"pb-item-heading\">"+esc(t("Delete"))+"</div><div class=\"pb-item-cell-body\"><button type=\"button\" class=\"btn btn-sm btn-outline-danger\" data-remove-component aria-label=\""+esc(t("Remove component"))+"\"><i class=\"fas fa-trash-alt\" aria-hidden=\"true\"></i></button></div></div>")+
    '</div>'+
    (main.length?'<div class="pb-spec-bar">'+main.map(field=>specControl(type,field,row)).join('')+'</div>':'')+
    '<div class="pb-spec-notes">'+notes.map(field=>specControl(type,field,row)).join('')+'</div></div>';
}
const plasticLookups=new WeakMap();
function lookupPlastic(row,{immediate=false,preserve=false,colorOnly=false}={}) {
  const code=row.querySelector('[data-field="reference"]').value.trim();
  const inputs={label:row.querySelector('[data-field="label"]'),color:row.querySelector('[data-field="color"]')};
  let state=plasticLookups.get(row);
  if(!state){state={seq:0,code:null,auto:preserve?{}:{label:inputs.label.value,color:inputs.color.value}};plasticLookups.set(row,state);}
  if(code===state.code){if(immediate&&state.timer){clearTimeout(state.timer);state.start();}return;}
  clearTimeout(state.timer);const seq=++state.seq;state.code=code;
  // Remove values belonging to the previous code, preserving fields the user has edited.
  const fields=colorOnly?['color']:['label','color'];
  if(!colorOnly)for(const field of fields)if(inputs[field].value===state.auto[field])inputs[field].value='';
  state.auto={};
  const status=row.querySelector('[data-lookup-status]');
  const report=(text,error=false)=>{status.textContent=text;status.className=error?'text-warning':'pb-muted';};
  row.dataset.lookupPending=code?'1':'0';row.dataset.lookupColorOnly=colorOnly?'1':'0';summary();
  if(!code){report(t("Enter a product code to fill in its name and color."));return;}
  report(t("Looking up product code…"));
  const before={label:inputs.label.value,color:inputs.color.value};
  state.start=async()=>{
    state.timer=null;
    if(!row.isConnected||seq!==state.seq)return;
    try{
      const item=(await api('plastic_item',undefined,{barcode:code})).item;
      if(!row.isConnected||seq!==state.seq)return;
      if(!item){report(t("Product code not found. Enter the component name and color manually."),true);return;}
      let changed=false;
      for(const field of fields){
        if(inputs[field].value===before[field]&&(!colorOnly||!before[field].trim())){
          const value=item[field]||'';changed=changed||inputs[field].value!==value;
          inputs[field].value=value;state.auto[field]=value;
        }
      }
      if(changed)dirty=true;
      if(colorOnly)report(changed?t("Color loaded from inventory. Save the product to keep it."):inputs.color.value?t("Current color kept."):t("This inventory item has no color. Enter it manually."));
      else report(t("Name and color loaded from inventory. You can edit both fields."));
    }catch(e){if(row.isConnected&&seq===state.seq)report(e.message,true);}
    finally{if(row.isConnected&&seq===state.seq){row.dataset.lookupPending='0';summary();}}
  };
  if(immediate)state.start();else state.timer=setTimeout(state.start,350);
}
function detailField(key,label,value,attribute='') {
  const empty=value===null||value===undefined||String(value).trim()==='';
  return '<div class="pb-detail-field" data-detail-field="'+esc(key)+'"><dt>'+esc(label)+'</dt><dd'+attribute+'>'+(empty?'<span class="pb-detail-empty" title="'+esc(t('Not set'))+'">—</span>':esc(value))+'</dd></div>';
}
function savedComponentFields(component) {
  return specFields(component.component_type,component).filter(field=>!field.disabled).map(field=>{
    const value=componentSpecValue(field,component);
    const option=field.items?.find(item=>String(item.value)===String(value));
    const display=value===null||value===undefined?'':field.type==='select'?t(option?.label||String(value)):String(value);
    return {...field,value:display,label:t(field.label==='Name'?'Rider name':field.label)};
  });
}
function detailSpecifications(fields) {
  const specs=fields.filter(field=>field.type!=='textarea');
  return specs.length?'<dl class="pb-detail-specs">'+specs.map(field=>detailField(field.key,field.label,field.value)).join('')+'</dl>':'';
}
function detailNotes(fields) {
  const notes=fields.filter(field=>field.type==='textarea'&&String(field.value).trim()!=='');
  return notes.length?'<dl class="pb-detail-notes">'+notes.map(field=>detailField(field.key,field.label,field.value)).join('')+'</dl>':'';
}
function detailDesignFields(component) {
  const type=component.component_type;if(!['G','S'].includes(type))return [];
  const design=designs.find(row=>String(row.id)===String(component.design_id));
  const fields=[{key:'design_id',label:t('Design'),value:design?.name||component.design_name||(component.design_id?'#'+component.design_id:'')}];
  if(type==='G'){
    const version=graphicsVersions.find(row=>String(row.id)===String(component.graphics_version_id));
    fields.push({key:'graphics_version_id',label:t('Version'),value:version?.name||component.graphics_version_name||(component.graphics_version_id?'#'+component.graphics_version_id:'')});
  }
  return fields;
}
function detailDesign(component) {
  const fields=detailDesignFields(component);
  return fields.length?'<dl class="pb-detail-design">'+fields.map(field=>detailField(field.key,field.label,field.value)).join('')+'</dl>':'';
}
function detailCompactComponent(component,fields) {
  const type=component.component_type;
  const hasDesign=['G','S'].includes(type);
  const columns=hasDesign?detailDesignFields(component):fields.filter(field=>field.type!=='textarea');
  columns.push({key:'reference',label:t('Reference / code'),value:component.reference});
  if(component.color)columns.push({key:'color',label:t('Color'),value:component.color});
  columns.push({key:'quantity',label:t('Quantity'),value:component.quantity},{key:'supplier',label:t('Preferred supplier'),value:'Scrub',attribute:' data-inventory-supplier'});
  const heading='<th scope="col">'+esc(t('Type'))+'</th><th scope="col">'+esc(t('Component name'))+'</th>'+columns.map(field=>'<th scope="col">'+esc(field.label)+'</th>').join('');
  const cells='<td><span class="pb-compact-type">'+esc(type)+'</span></td><td><strong class="pb-detail-name">'+esc(component.label)+'</strong></td>'+columns.map(field=>'<td><dl>'+detailField(field.key,field.label,field.value,field.attribute||'')+'</dl></td>').join('');
  return '<article class="pb-detail-card pb-compact-component" data-detail-component="'+esc(type)+'"><div class="pb-compact-scroll"><table class="table table-sm mb-0 pb-compact-table"><thead><tr>'+heading+'</tr></thead><tbody><tr>'+cells+'</tr></tbody></table></div>'+(hasDesign?detailSpecifications(fields):'')+detailNotes(fields)+'</article>';
}
function detailComponentCard(component) {
  const fields=savedComponentFields(component),type=component.component_type;
  if(['G','F','S'].includes(type))return detailCompactComponent(component,fields);
  let meta=detailField('reference',t('Reference / code'),component.reference)+detailField('quantity',t('Quantity'),component.quantity);
  if(['G','F','S'].includes(type))meta+=detailField('supplier',t('Preferred supplier'),'Scrub',' data-inventory-supplier');
  if(component.color)meta+=detailField('color',t('Color'),component.color);
  return '<article class="pb-detail-card" data-detail-component="'+esc(type)+'"><header class="pb-detail-card-header"><strong class="pb-detail-name">'+esc(component.label)+'</strong><dl class="pb-detail-meta">'+meta+'</dl></header>'+detailDesign(component)+detailSpecifications(fields)+detailNotes(fields)+'</article>';
}
function plasticInventoryCells(component) {
  const reason={ambiguous:t('Multiple inventory items use this product code.'),not_found:t('Product code not found in inventory.'),no_code:t('No product code saved.')}[component.inventory_status]||'';
  const title=reason?' title="'+esc(reason)+'"':'';
  return '<td data-inventory-supplier'+title+'>'+esc(component.inventory_supplier||'—')+'</td><td data-inventory-quantity'+title+'>'+esc(component.inventory_quantity??'—')+'</td>';
}
function detailPlastics(components) {
  const headers=['Type','Component name / details','Product code / reference','Color','Quantity','Preferred supplier','Stock quantity'];
  return '<div class="pb-plastics-scroll"><table class="table table-sm mb-0 pb-plastics-table"><thead><tr>'+headers.map(label=>'<th scope="col">'+esc(t(label))+'</th>').join('')+'</tr></thead><tbody>'+components.map(component=>{
    const fields=savedComponentFields(component);
    return '<tr data-detail-component="P"><td><span class="pb-plastic-type">P</span></td><td><strong>'+esc(component.label)+'</strong>'+detailSpecifications(fields)+detailNotes(fields)+'</td><td><code>'+esc(component.reference||'—')+'</code></td><td>'+esc(component.color||'—')+'</td><td>'+esc(component.quantity)+'</td>'+plasticInventoryCells(component)+'</tr>';
  }).join('')+'</tbody></table></div>';
}
function componentsDetail(components) {
  components=components?.map(normalizeComponent);
  if(!components?.length)return '<p class="pb-muted mb-0">'+esc(t('No components saved.'))+'</p>';
  // Group by the first occurrence of each type; preserve the saved order within each group.
  const groups=new Map();components.forEach(component=>{const type=component.component_type;if(!groups.has(type))groups.set(type,[]);groups.get(type).push(component);});
  return '<div class="pb-component-details pb-bundle-detail">'+Array.from(groups,([type,rows])=>'<section class="pb-detail-section pb-item-'+esc(type)+'" data-detail-type="'+esc(type)+'"><header class="pb-detail-section-header"><h3><span class="pb-type pb-type-'+esc(type)+'" aria-hidden="true">'+esc(type)+'</span> '+esc(t(cfg.types[type]||type))+'</h3><span class="pb-detail-count">'+esc(t('Components'))+': '+rows.length+'</span></header><div class="pb-detail-section-body">'+(type==='P'?detailPlastics(rows):rows.map(detailComponentCard).join(''))+'</div></section>').join('')+'</div>';
}
function renderComponents() {
  bundle=el('pb-bundle').value;el('pb-start').hidden=!!bundle;el('pb-form-body').hidden=!bundle;
  destroyDesignPickers(el('pb-components'));
  el('pb-components').innerHTML=bundleComponentTypes(bundle).map(type=>{
    const rows=cache[type]?.length?cache[type]:[{}];
    return '<div class="pb-component" data-component-group="'+type+'"><div class="pb-component-header"><strong><span class="pb-type pb-type-'+type+' mr-2">'+type+'</span>'+esc(t(cfg.types[type]))+'</strong><button type="button" class="btn btn-sm btn-outline-info" data-add-component="'+type+("\">"+esc(t("+ Component"))+"</button></div>")+rows.map(r=>componentRow(type,r)).join('')+'</div>';
  }).join('');
  initDesignPickers();
  el('pb-components').querySelectorAll('[data-component-row="P"][data-resume-lookup]').forEach(row=>lookupPlastic(row,{preserve:true,colorOnly:row.dataset.lookupColorOnly==='1'}));
  summary();
}
function categoryKind(){el('pb-specific').hidden=kind()!=='specific';el('pb-universal').hidden=kind()!=='universal';summary();}
function cancelSkuGeneration() {
  skuGeneration++;skuPending=false;el('pb-sku-status').textContent='';
}
async function generateSku() {
  const selectedBundle=el('pb-bundle').value;if(!selectedBundle||skuPending)return;
  const generation=++skuGeneration,requestKey=key,previousSku=el('pb-sku').value;
  skuPending=true;el('pb-sku-status').textContent=t('Generating SKU…');summary();
  try {
    const result=await api('generate_sku',{bundle_type:selectedBundle});
    // Never replace a manual edit or data in another product after a late response.
    if(generation!==skuGeneration||key!==requestKey||el('pb-bundle').value!==selectedBundle||el('pb-sku').value!==previousSku)return;
    el('pb-sku').value=result.sku;el('pb-sku').setCustomValidity('');delete el('pb-sku').dataset.pbValidity;
    dirty=true;el('pb-sku-status').textContent=t('New SKU generated. Save the product to apply it.');
  } catch(e) {
    if(generation===skuGeneration){msg('pb-message',e.message);el('pb-sku-status').textContent=e.message;}
  } finally {
    if(generation===skuGeneration){skuPending=false;summary();}
  }
}
function resetProduct(ask=true) {
  if(ask&&dirty&&!confirm(t("Discard unsaved product changes?")))return;
  if(ask&&pageMode==='builder'){const url=new URL(window.location.href);url.searchParams.delete('edit');history.replaceState(null,'',url);}
  cancelSkuGeneration();
  productWeightEdited=false;product=null;specific=null;cache={};bundle='';key=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');form.reset();renderBoxPicker('');
  el('pb-edit-label').textContent=t("New product");el('pb-cancel').hidden=true;
  el('pb-specific-label').textContent=t("Category Info · Select brand / model / years");uniBrand();renderComponents();categoryKind();dirty=false;
}
function editProduct(row) {
  resetProduct(false);product=row;key=row.request_key;el('pb-sku').value=row.sku;el('pb-name').value=row.name;el('pb-bundle').value=row.bundle_type;el('pb-notes').value=row.notes;
  renderBoxPicker(row.box_id||'');el('pb-product-weight').value=row.weight_kg??'';
  for(const channel of priceFields){el('pb-'+channel+'-price').value=row[channel+'_price']??'';el('pb-'+channel+'-currency').value=row[channel+'_currency']||'EUR';}
  form.querySelector('[name="category_kind"][value="'+row.category_kind+'"]').checked=true;
  if(row.category_kind==='universal'){el('pb-uni-brand').value=categories.find(c=>String(c.id)===String(row.uni_category_id))?.brand||'';uniBrand(String(row.uni_category_id));}
  else{specific={brand:row.brand,model:row.model,year_range:row.year_range,modelcode:row.modelcode};el('pb-specific-label').textContent=[row.brand,row.model,row.year_range,row.modelcode].join(' · ');}
  row.components.map(normalizeComponent).forEach(c=>{(cache[c.component_type]||=[]).push(c);});
  el('pb-edit-label').textContent=t('Editing product #{id}',{id:row.id});el('pb-cancel').hidden=false;renderComponents();categoryKind();dirty=false;
  // Older listings predate the separate color field. Fill only missing colors on opening.
  el('pb-components').querySelectorAll('[data-component-row="P"]').forEach(component=>{
    if(component.querySelector('[data-field="reference"]').value.trim()&&!component.querySelector('[data-field="color"]').value.trim()){
      lookupPlastic(component,{immediate:true,preserve:true,colorOnly:true});
    }
  });
}
async function loadModelLevel(level) {
  const seq=++modelSeq;msg('pb-model-error','');el('pb-model-apply').disabled=true;
  const brand=el('pb-model-brand').value,model=el('pb-model-name').value;
  if(level==='brands')options(el('pb-model-brand'),[],t("Loading brands…"),x=>x,x=>x);
  if(level!=='years')options(el('pb-model-name'),[],t("Select a model…"),x=>x,x=>x);
  options(el('pb-model-years'),[],t("Select years…"),x=>x,x=>x);
  if((level==='models'&&!brand)||(level==='years'&&(!brand||!model)))return;
  try{
    const data=await api('models',undefined,{level,brand,model});if(seq!==modelSeq)return;
    if(level==='brands')options(el('pb-model-brand'),data.rows,t("Select a brand…"),r=>r.brand,r=>r.brand);
    if(level==='models')options(el('pb-model-name'),data.rows,t("Select a model…"),r=>r.model,r=>r.model);
    if(level==='years')options(el('pb-model-years'),data.rows,t("Select years…"),r=>JSON.stringify(r),r=>r.year_range+' · '+r.modelcode);
  }catch(e){if(seq===modelSeq)msg('pb-model-error',e.message);}
}
if(form){
  $(el('pb-components')).on('change.pbDesign','[data-field="design_id"],[data-field="graphics_version_id"]',function(){
    updateComponentName(this.closest('[data-component-row]'));
    dirty=true;
  });
  form.addEventListener('input',()=>{dirty=true;});form.addEventListener('change',()=>{dirty=true;});
  el('pb-sku').addEventListener('input',()=>{cancelSkuGeneration();summary();});
  el('pb-generate-sku').addEventListener('click',generateSku);
  el('pb-product-box').addEventListener('change',()=>{el('pb-product-box').title=el('pb-product-box').selectedOptions[0]?.textContent||'';prefillBoxWeight();});
  el('pb-product-weight').addEventListener('input',()=>{productWeightEdited=true;});
  for(const eventName of ['input','change'])el('pb-components').addEventListener(eventName,event=>{
    const row=event.target.closest('[data-component-row="P"]');
    if(row&&event.target.dataset.field==='reference')lookupPlastic(row,{immediate:eventName==='change'});
  });
  el('pb-components').addEventListener('keydown',event=>{
    const row=event.target.closest('[data-component-row="P"]');
    if(event.key==='Enter'&&row&&event.target.dataset.field==='reference'){event.preventDefault();lookupPlastic(row,{immediate:true});}
  });
  el('pb-bundle').addEventListener('change',()=>{cancelSkuGeneration();const rows=readComponents();for(const type of bundleComponentTypes(bundle))cache[type]=rows.filter(r=>r.component_type===type);renderComponents();});
  form.querySelectorAll('[name="category_kind"]').forEach(i=>i.addEventListener('change',categoryKind));
  el('pb-uni-brand').addEventListener('change',()=>uniBrand());el('pb-uni-category').addEventListener('change',uniSelection);
  el('pb-new').addEventListener('click',()=>{resetProduct();el('pb-bundle').focus();});el('pb-cancel').addEventListener('click',()=>resetProduct());
  el('pb-components').addEventListener('click',event=>{
    if(event.target.closest('[data-component-category]')){
      if(kind()==='specific')modalAction('pb-model-modal','show');
      else{el('pb-category-panel').scrollIntoView({behavior:'smooth',block:'center'});el('pb-uni-category').focus({preventScroll:true});}
      return;
    }
    const add=event.target.closest('[data-add-component]'),remove=event.target.closest('[data-remove-component]');
    if(add){add.closest('[data-component-group]').insertAdjacentHTML('beforeend',componentRow(add.dataset.addComponent));initDesignPickers();dirty=true;summary();}
    if(remove){if(remove.closest('[data-component-group]').querySelectorAll('[data-component-row]').length===1){msg('pb-message',t("Each type in the bundle must contain at least one component."));return;}destroyDesignPickers(remove.closest('[data-component-row]'));remove.closest('[data-component-row]').remove();dirty=true;summary();}
  });
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(skuPending||!form.reportValidity()||el('pb-components').querySelector('[data-lookup-pending="1"]'))return;
    const c=kind()==='specific'?(specific||{}):{uni_category_id:el('pb-uni-category').value};
    const data={id:product?.id||0,version:product?.version||0,request_key:key,sku:el('pb-sku').value,name:el('pb-name').value,bundle_type:el('pb-bundle').value,category_kind:kind(),notes:el('pb-notes').value,components:readComponents(),box_id:el('pb-product-box').value,weight_kg:el('pb-product-weight').value.trim(),...readPrices(),...c};
    form.inert=true;el('pb-save').disabled=true;
    try{
      const saved=await api('save_product',data);dirty=false;
      product={...data,...saved.product,version:Number(data.version)+1};
      editProduct((await api('product',undefined,{id:saved.product.id})).product);
      msg('pb-message',t('Product saved. SKU: {sku}',{sku:saved.product.sku}),true);await loadProducts();
    }catch(e){msg('pb-message',e.message);}finally{form.inert=false;summary();el('pb-message').scrollIntoView({behavior:'smooth',block:'center'});}
  });
  $('#pb-model-modal').on('show.bs.modal',()=>loadModelLevel('brands'));
  el('pb-model-brand').addEventListener('change',()=>loadModelLevel('models'));el('pb-model-name').addEventListener('change',()=>loadModelLevel('years'));
  el('pb-model-years').addEventListener('change',()=>{el('pb-model-apply').disabled=!el('pb-model-years').value;});
  el('pb-model-refresh').addEventListener('click',()=>loadModelLevel('brands'));
  el('pb-model-apply').addEventListener('click',()=>{
    if(!el('pb-model-years').value)return;specific={brand:el('pb-model-brand').value,model:el('pb-model-name').value,...JSON.parse(el('pb-model-years').value)};
    el('pb-specific-label').textContent=[specific.brand,specific.model,specific.year_range,specific.modelcode].join(' · ');dirty=true;summary();modalAction('pb-model-modal','hide');
  });
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
}
const uf=el('pb-uni-form');
if(uf){
  function uniEditor(row={}) {
    uf.reset();Array.from(uf.elements).forEach(i=>{if(i.name)i.value=row[i.name]??'';});
    el('pb-uni-edit-title').textContent=row.id?t("Edit UNI category"):t("New UNI category");el('pb-generate-code').disabled=!!row.id;
    el('pb-uni-delete').hidden=!row.id;el('pb-uni-delete').disabled=Number(row.usage_count||0)>0;
    el('pb-uni-delete').title=Number(row.usage_count||0)>0?t("This category is used by a saved product."):'';
  }
  el('pb-uni-new').addEventListener('click',()=>uniEditor());
  el('pb-uni-rows').addEventListener('click',event=>{const b=event.target.closest('[data-uni-edit]');if(b)uniEditor(categories.find(r=>String(r.id)===b.dataset.uniEdit));});
  el('pb-generate-code').addEventListener('click',async()=>{
    el('pb-generate-code').disabled=true;
    try{el('pb-edit-code').value=(await api('generate_modelcode',{})).code;}catch(e){msg('pb-uni-message',e.message);}finally{el('pb-generate-code').disabled=!!uf.elements.id.value;}
  });
  uf.addEventListener('submit',async event=>{
    event.preventDefault();if(!uf.reportValidity())return;uf.inert=true;
    try{const saved=await api('save_uni',Object.fromEntries(new FormData(uf)));await loadCategories();uniEditor(categories.find(r=>String(r.id)===String(saved.id)));msg('pb-uni-message',t("Category saved."),true);}
    catch(e){msg('pb-uni-message',e.message);}finally{uf.inert=false;}
  });
  el('pb-uni-delete').addEventListener('click',async()=>{
    if(!confirm(t('Delete UNI category {name}?',{name:uf.elements.category_name.value})))return;uf.inert=true;
    try{await api('delete_uni',{id:uf.elements.id.value,version:uf.elements.version.value});await loadCategories();uniEditor();msg('pb-uni-message',t("Category deleted."),true);}
    catch(e){msg('pb-uni-message',e.message);}finally{uf.inert=false;}
  });
  el('pb-csv').addEventListener('change',()=>{el('pb-csv-label').textContent=el('pb-csv').files[0]?.name || t("Choose a CSV file");});
  el('pb-import-form').addEventListener('submit',async event=>{
    event.preventDefault();const f=el('pb-csv').files[0];if(!f)return;const data=new FormData();data.append('csv',f);event.target.inert=true;
    try{const r=(await api('import_uni',data)).result;await loadCategories();msg('pb-uni-message',t('Added: {added} · Existing skipped: {skipped}',{added:r.added,skipped:r.skipped}),true);event.target.reset();el('pb-csv-label').textContent=t("Choose a CSV file");}
    catch(e){msg('pb-uni-message',e.message);}finally{event.target.inert=false;}
  });
}
el('pb-products')?.addEventListener('click',async event=>{
  const copy=event.target.closest('[data-pb-copy]');if(copy){event.preventDefault();await copyCodeButton(copy);return;}
  const imagePath=event.target.closest('[data-img-path]');if(imagePath){openImagePath(imagePath.dataset.imgPath);return;}
  const imageReload=event.target.closest('[data-img-reload]');if(imageReload){await reloadImage(imageReload.dataset.imgReload);return;}
  const toggle=event.target.closest('[data-product-expand]');
  if(toggle){
    const detail=el(toggle.getAttribute('aria-controls')),content=detail.querySelector('[data-component-detail]');
    const open=toggle.getAttribute('aria-expanded')!=='true';
    if(!open){closeComponentFocus({restoreFocus:true});return;}
    openComponentFocus(toggle,detail);
    if(detail.dataset.loading)return;
    detail.dataset.loading='1';content.textContent=t("Loading components…");
    try{
      const r=(await api('product',undefined,{id:toggle.dataset.productExpand})).product;
      if(detail.isConnected){content.innerHTML=componentsDetail(r.components);}
    }catch(e){if(detail.isConnected)content.textContent=e.message+' '+t('Close and reopen to retry.');}
    finally{delete detail.dataset.loading;}
    return;
  }
  const b=event.target.closest('[data-product]');if(!b)return;
  if(form&&dirty&&!confirm(t("Discard unsaved changes and open another product?")))return;closeComponentFocus();b.disabled=true;
  try{
    const r=(await api('product',undefined,{id:b.dataset.product})).product;
    if(form){editProduct(r);el('pb-bundle').scrollIntoView({behavior:'smooth',block:'center'});}
    else{el('pb-detail-title').textContent=r.name;el('pb-detail').innerHTML='<p><code>'+esc(r.sku)+'</code></p><p>'+esc([r.bundle_type,r.uni_brand||r.brand,r.uni_name||r.model,r.year_range,r.modelcode].filter(Boolean).join(' · '))+'</p>'+pricesDetail(r)+packagingDetail(r)+componentsDetail(r.components)+'<div class="pb-coverage">'+esc(r.notes)+'</div>';modalAction('pb-detail-modal','show');}
  }catch(e){msg('pb-message',e.message);}finally{b.disabled=false;}
});
let timer;
const refreshProducts=options=>loadProducts(options).catch(e=>msg('pb-message',e.message));
el('pb-search')?.addEventListener('input',()=>{
  clearTimeout(timer);listSeq++;showListLoading();
  timer=setTimeout(()=>refreshProducts({page:1}),250);
});
el('pb-saved-products')?.addEventListener('click',event=>{
  const button=event.target.closest('[data-list-page]');
  if(button&&!button.disabled){
    const pages={first:1,previous:listPage-1,next:listPage+1,last:listPages};
    refreshProducts({page:pages[button.dataset.listPage]});
    if(button.closest('.card-footer'))el('pb-saved-products').scrollIntoView({behavior:'smooth',block:'start'});
  }
  if(event.target.closest('[data-list-retry]'))refreshProducts({page:1});
});
el('pb-saved-products')?.addEventListener('change',event=>{
  if(event.target.matches('[data-list-page-size]'))refreshProducts({page:1,perPage:Number(event.target.value)});
  if(event.target.matches('[data-list-page-number]')){
    const number=Number(event.target.value);
    if(Number.isInteger(number)&&number>0)refreshProducts({page:Math.min(number,listPages)});
    else renderPagination();
  }
});
el('pb-saved-products')?.addEventListener('keydown',event=>{
  if(event.key==='Enter'&&event.target.matches('[data-list-page-number]')){event.preventDefault();event.target.blur();}
});
el('pb-uni-search')?.addEventListener('input',renderUni);
$('#pb-uni-modal').on('show.bs.modal',()=>loadCategories().catch(e=>msg('pb-uni-message',e.message)));
// Returning from another tab refreshes the libraries, never the product or listing table.
let librariesInitialized=false,libraryRefreshTask=null,libraryRefreshTimer=null,libraryRefreshPending=false,libraryRefreshManual=false;
const refreshLibrariesButton=el('pb-refresh-libraries');
async function refreshLibraries(manual) {
  refreshLibrariesButton.disabled=true;refreshLibrariesButton.setAttribute('aria-busy','true');
  const status=el('pb-library-status');
  if(manual){status.textContent=t('Refreshing libraries…');status.className='small pb-muted mb-2';status.hidden=false;}
  const task=Promise.allSettled([loadCategories(),loadDesigns(),loadBoxes()]);libraryRefreshTask=task;
  const results=await task;
  const failed=results.some(result=>result.status==='rejected');
  if(failed){status.textContent=t('Some libraries could not be refreshed. Your draft is preserved. Try Refresh libraries again.');status.className='small text-warning mb-2';status.hidden=false;}
  else if(manual){status.textContent=t('Libraries refreshed. Your draft is preserved.');status.className='small text-info mb-2';status.hidden=false;}
  else{status.textContent='';status.hidden=true;}
  libraryRefreshTask=null;refreshLibrariesButton.disabled=false;refreshLibrariesButton.setAttribute('aria-busy','false');
}
function runLibraryRefresh() {
  if(!libraryRefreshPending||document.visibilityState==='hidden')return;
  // Do not interfere with initial loading or an active product/library save.
  if(!librariesInitialized||libraryRefreshTask||form.inert||document.querySelector('.pb-modal form[inert]')){
    libraryRefreshTimer=setTimeout(runLibraryRefresh,250);return;
  }
  const manual=libraryRefreshManual;libraryRefreshPending=false;libraryRefreshManual=false;
  refreshLibraries(manual);
}
function queueLibraryRefresh(manual=false) {
  if(!form||!refreshLibrariesButton)return;
  libraryRefreshPending=true;libraryRefreshManual=libraryRefreshManual||manual;
  clearTimeout(libraryRefreshTimer);libraryRefreshTimer=setTimeout(runLibraryRefresh,manual?0:200);
}
if(form&&refreshLibrariesButton){
  refreshLibrariesButton.addEventListener('click',()=>queueLibraryRefresh(true));
  window.addEventListener('focus',()=>queueLibraryRefresh());
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')queueLibraryRefresh();});
  window.addEventListener('pageshow',event=>{if(event.persisted)queueLibraryRefresh();});
}
const initialLoads=[];
if(el('pb-uni-modal'))initialLoads.push(loadCategories());
if(el('pb-design-modal'))initialLoads.push(loadDesigns());
if(el('pb-products'))initialLoads.push(loadProducts());
if(form)initialLoads.push(loadBoxes());
const openProductId=Number(cfg.editProductId||0);
if(form&&openProductId>0){form.inert=true;el('pb-new').disabled=true;}
Promise.all(initialLoads).then(async()=>{
  if(!form||openProductId<=0)return;
  const row=(await api('product',undefined,{id:openProductId})).product;
  editProduct(row);el('pb-editor-title').scrollIntoView({behavior:'smooth',block:'start'});
}).catch(e=>msg('pb-message',e.message)).finally(()=>{librariesInitialized=true;if(form){form.inert=false;el('pb-new').disabled=false;if(refreshLibrariesButton)refreshLibrariesButton.disabled=false;}});
});
})();
