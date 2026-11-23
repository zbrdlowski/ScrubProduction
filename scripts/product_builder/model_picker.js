/* Staged multiple model selection. Applying commits to the form; Cancel never does. */
(function(){'use strict';
window.ProductModelPicker={create({api,t,esc,onApply}){
 const el=id=>document.getElementById(id),tree=el('pb-model-tree'),selected=el('pb-model-selected');
 const key=row=>JSON.stringify([row.brand,row.model,row.year_range,row.modelcode]);
 const text=row=>[row.brand,row.model,row.year_range,row.modelcode].filter(Boolean).join(' · ');
 const normalized=value=>String(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[–—‑]/g,'-');
 let catalogue=[],chosen=new Map(),groups=[],leaves=[],opened=new Set(),sequence=0,loading=false;
 function counts(){
  el('pb-model-count').textContent=t('{count} compatible models selected',{count:chosen.size});
  el('pb-model-apply').disabled=loading||!chosen.size;el('pb-model-clear').disabled=!chosen.size||loading;
  selected.innerHTML=Array.from(chosen.values()).map((row,index)=>'<li><span>'+esc(text(row))+'</span><button type="button" class="btn btn-sm btn-outline-danger" data-model-remove="'+index+'" aria-label="'+esc(t('Remove {model}',{model:text(row)}))+'"><i class="fas fa-times" aria-hidden="true"></i></button></li>').join('')||'<li class="pb-muted">'+esc(t('No compatible models selected.'))+'</li>';
 }
 function render(){
  counts();if(loading)return;
  const terms=normalized(el('pb-model-search').value).split(/[\s·]+/).filter(Boolean);
  const visible=catalogue.filter(row=>terms.every(term=>normalized(text(row)).includes(term)));
  const brands=new Map();groups=[];leaves=[];
  visible.forEach(row=>{if(!brands.has(row.brand))brands.set(row.brand,new Map());const models=brands.get(row.brand);if(!models.has(row.model))models.set(row.model,[]);models.get(row.model).push(row);});
  function group(label,items,path,children){
   const index=groups.push(items)-1,all=items.every(row=>chosen.has(key(row))),some=items.some(row=>chosen.has(key(row)));
   return '<details data-model-branch="'+esc(path)+'"'+(terms.length||opened.has(path)?' open':'')+'><summary><input type="checkbox" data-model-group="'+index+'" aria-label="'+esc(label)+'"'+(all?' checked':'')+' data-partial="'+(!all&&some)+'"><span>'+esc(label)+'</span><small class="pb-muted">'+items.length+'</small></summary><div class="pb-model-children">'+children+'</div></details>';
  }
  let html='';
  brands.forEach((models,brand)=>{
   let content='',brandRows=[];
   models.forEach((items,model)=>{
    brandRows.push(...items);
    const children=items.map(row=>{const index=leaves.push(row)-1;return '<label class="pb-model-leaf"><input type="checkbox" data-model-leaf="'+index+'" aria-label="'+esc(text(row))+'"'+(chosen.has(key(row))?' checked':'')+'><span>'+esc(row.year_range)+' <code>'+esc(row.modelcode)+'</code></span></label>';}).join('');
    content+=group(model,items,JSON.stringify([brand,model]),children);
   });
   html+=group(brand,brandRows,JSON.stringify([brand]),content);
  });
  tree.innerHTML=html||'<p class="pb-muted p-3">'+esc(t('No models match this search.'))+'</p>';
  tree.querySelectorAll('[data-partial="true"]').forEach(input=>{input.indeterminate=true;});
 }
 async function load(){
  const request=++sequence;loading=true;counts();el('pb-model-error').hidden=true;tree.setAttribute('aria-busy','true');tree.innerHTML='<p class="pb-muted p-3">'+esc(t('Loading models…'))+'</p>';
  try{const result=await api('model_catalogue');if(sequence!==request)return;catalogue=result.rows;}
  catch(error){if(sequence!==request)return;el('pb-model-error').textContent=error.message;el('pb-model-error').hidden=false;}
  finally{if(sequence===request){loading=false;tree.setAttribute('aria-busy','false');render();}}
 }
 tree.addEventListener('click',event=>{if(event.target.matches('input'))event.stopPropagation();});
 tree.addEventListener('toggle',event=>{const node=event.target;if(node.isConnected&&node.matches('[data-model-branch]')){if(node.open)opened.add(node.dataset.modelBranch);else opened.delete(node.dataset.modelBranch);}},true);
 tree.addEventListener('change',event=>{
  const input=event.target;let rows=[];
  if(input.matches('[data-model-leaf]'))rows=[leaves[Number(input.dataset.modelLeaf)]];
  if(input.matches('[data-model-group]'))rows=groups[Number(input.dataset.modelGroup)];
  const focusSelector=input.matches('[data-model-leaf]')?'[data-model-leaf="'+input.dataset.modelLeaf+'"]':'[data-model-group="'+input.dataset.modelGroup+'"]';
  rows.forEach(row=>{if(input.checked)chosen.set(key(row),row);else chosen.delete(key(row));});render();tree.querySelector(focusSelector)?.focus({preventScroll:true});
 });
 selected.addEventListener('click',event=>{const button=event.target.closest('[data-model-remove]');if(!button)return;const row=Array.from(chosen.values())[Number(button.dataset.modelRemove)];if(row)chosen.delete(key(row));render();});
 el('pb-model-search').addEventListener('input',render);
 el('pb-model-clear').addEventListener('click',()=>{chosen.clear();render();});
 el('pb-model-refresh').addEventListener('click',load);
 el('pb-model-apply').addEventListener('click',()=>{if(!loading&&chosen.size)onApply(Array.from(chosen.values()));});
 return {open(rows){chosen=new Map(rows.map(row=>[key(row),{...row}]));opened=new Set();rows.forEach(row=>{opened.add(JSON.stringify([row.brand]));opened.add(JSON.stringify([row.brand,row.model]));});el('pb-model-search').value='';load();}};
}};
})();
