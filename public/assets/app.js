const $ = id => document.getElementById(id);
const state = {results: [], running: false, stop: false, controller: null};
const split = () => [...new Set($('domains').value.split(/[\n\r,，]+/u).map(s=>s.trim()).filter(Boolean))];
function count() { $('count').textContent = `${split().length} 个域名`; }
$('domains').addEventListener('input',count);
$('examples').onclick=()=>{$('domains').value='51chigua.com\n51chiguai.com';count();};
function el(tag,text,cls) { const e=document.createElement(tag); if(text!==undefined)e.textContent=text; if(cls)e.className=cls; return e; }
function safeLink(url) { try { const u=new URL(url); if(!['http:','https:'].includes(u.protocol)) return el('span',url); const a=el('a',url); a.href=u.href; a.target='_blank'; a.rel='noopener noreferrer'; return a; } catch { return el('span',url); } }
function errorText(error) { return error ? `${error.message} [${error.code}]` : '未提供'; }
const dialog = $('content-dialog');
function openDialog(title, domain, fields, note = '', links = []) {
 $('dialog-title').textContent = title;
 $('dialog-context').textContent = domain || 'DOMAIN LENS';
 $('dialog-body').replaceChildren();
 for (const field of fields) {
  const section=el('section',undefined,`dialog-field${field.highlight?' selected':''}`);
  section.append(el('h3',field.name));
  section.append(el('p',field.value ?? '未提供'));
  $('dialog-body').append(section);
 }
 if(links.length){const nav=el('nav',undefined,'dialog-links');nav.setAttribute('aria-label','流量数据相关链接');for(const [label,url] of links){const link=safeLink(url);link.textContent=label;nav.append(link);}$('dialog-body').append(nav);}
 $('dialog-note').textContent=note;
 dialog.showModal();
 const selected=$('dialog-body').querySelector('.selected');
 if(selected)selected.scrollIntoView({block:'nearest'});
}
dialog.addEventListener('click',event=>{
 if(event.target!==dialog)return;
 const rect=dialog.getBoundingClientRect();
 if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)dialog.close();
});
function openMetadata(r,selected) {
 const meta=r.seo?.data?.meta;
 openDialog('页面 SEO 内容',r.domain,[['Title','title'],['Description','description'],['Keywords','keywords']].map(([name,key])=>({name,value:meta?.[key]||`页面未提供 ${name}`,highlight:key===selected})), '来自公开 HTML。单字段最多保留 16,000 字符；页面脚本动态生成的内容可能不在抓取结果中。');
}
function metadataPreview(r,key,label,fallback) {
 const wrapper=el('div',undefined,`metadata-field field-${key}`);
 wrapper.append(el('span',label,'metadata-label'));
 const value=r.seo?.data?.meta?.[key];
 if(!value){wrapper.append(el('p',fallback||`未提供 ${label}`,'metadata-missing'));return wrapper;}
 const button=el('button',undefined,'metadata-preview');button.type='button';
 button.setAttribute('aria-label',`查看 ${r.domain} 的完整 ${label}`);
 button.setAttribute('aria-haspopup','dialog');
 button.append(el('span',value,`clamp-${key}`));
 button.append(el('span',`查看全文 ↗ · ${Array.from(value).length.toLocaleString()} 字`,'preview-more'));
 button.onclick=()=>openMetadata(r,key);wrapper.append(button);return wrapper;
}
function formatVisits(value) {
 return typeof value==='number'&&Number.isFinite(value)&&value>=0 ? new Intl.NumberFormat('en-US',{notation:'compact',maximumFractionDigits:2}).format(value) : '—';
}
function openTraffic(r) {
 const traffic=r.traffic;const data=traffic?.data;
 const fields=data?[
  {name:'Monthly Visits · 月访问量',value:new Intl.NumberFormat('en-US',{maximumFractionDigits:2}).format(data.monthly_visits)},
  {name:'统计月份',value:data.month},
  {name:'统计主体域名',value:data.domain},
  {name:'数据来源',value:'TabAPI · 第三方流量估算'},
  {name:'最近月份',value:data.history?.map(item=>`${item.month}  /  ${new Intl.NumberFormat('en-US').format(item.visits)}`).join('\n')||'未提供'},
  {name:'流量数据更新时间',value:new Date(traffic.checked_at).toLocaleString()},
  {name:'缓存状态',value:r.traffic_cache_cleared_at?'已清除，当前展示上次结果':traffic.cache?.hit?'来自项目文件缓存':'本次获取，已写入项目缓存'},
  {name:'缓存有效期',value:traffic.cache?.expires_at?new Date(traffic.cache.expires_at).toLocaleString():'未提供'}
 ]:[{name:'当前状态',value:traffic?.error?.message||'尚未接入流量 API，可使用 AITDK 免费浏览器扩展查询。'},{name:'如何免费开始',value:'AITDK 扩展可直接查看流量；自动批量展示需要管理员开通 TabAPI 免费试用额度并在服务器配置。'}];
 openDialog('Monthly Visits',r.domain,fields,'访问量是第三方估算，可能与网站后台及 AITDK 扩展不同。没有数据不代表 0；数值旁的月份才是实际统计期。',[['AITDK 免费扩展','https://aitdk.com/extension'],['API 免费额度与价格','https://tabapi.com/pricing']]);
}
function trafficCell(r) {
 const cell=el('td',undefined,'traffic-cell');
 if(r.error){cell.append(el('span','—'));return cell;}
 const traffic=r.traffic;const data=traffic?.data;
 const button=el('button',undefined,'traffic-button');button.type='button';button.setAttribute('aria-haspopup','dialog');
 if(traffic?.status==='ok'&&data){button.append(el('strong',formatVisits(data.monthly_visits),'visits-value'));button.append(el('small',`${data.month} · 估算`));button.append(el('small',`更新 ${shortTime(traffic.checked_at)}`,'updated-at'));button.append(el('small',r.traffic_cache_cleared_at?'缓存已清除 · 待更新':traffic.cache?.hit?'缓存 · TabAPI ↗':'本次获取 · TabAPI ↗'));button.setAttribute('aria-label',`查看 ${r.domain} 的月访问量详情`);}
 else {const label=({not_configured:'未接入',no_data:'暂无数据',limit_reached:'额度保护',error:'查询失败'})[traffic?.status]||'未接入';button.append(el('strong','—','visits-value'));button.append(el('small',label));button.append(el('small','查看说明 ↗'));button.setAttribute('aria-label',`查看 ${r.domain} 的流量接入说明`);}
 button.onclick=()=>openTraffic(r);cell.append(button);return cell;
}
function addDetail(r,index) {
 const box=el('section',undefined,'detail');box.id=`detail-${index}`;box.hidden=true;
 box.append(el('h3',r.domain||r.input));const dl=el('dl');
 const field=(name,value,link=false)=>{dl.append(el('dt',name));const dd=el('dd');if(link&&value)dd.append(safeLink(value));else dd.textContent=Array.isArray(value)?(value.length?value.join('\n'):'未提供'):(value??'未提供');dl.append(dd);};
 if(r.error) field('输入错误',errorText(r.error));
 else {
 const reg=r.registration.data,seo=r.seo.data,meta=seo?.meta;
 field('注册查询',r.registration.error?errorText(r.registration.error):reg?.note||'查询成功');field('注册主体域名',reg?.domain);field('注册时间（原始）',reg?.created_at);field('RDAP 来源',reg?.source,true);field('域名状态',reg?.status);
 field('页面查询',r.seo.error?errorText(r.seo.error):'已返回响应');field('最终页面',seo?.url,true);field('HTTP / Content-Type',seo?`${seo.http_status} / ${seo.content_type}`:null);
 for(const [name,key] of [['Title','title'],['Description','description'],['Keywords','keywords'],['canonical','canonical'],['Meta robots','robots'],['Googlebot','googlebot'],['语言','lang'],['H1（最多 10 项）','h1']])field(name,meta?.[key],key==='canonical');
 field('X-Robots-Tag',seo?.x_robots_tag);field('重定向链',seo?.redirects?.map(v=>`${v.status} ${v.url}`));field('提示',seo?.warnings?.length?seo.warnings:'无额外提示');
 if(seo?.robots_txt){const rob=seo.robots_txt;field('robots.txt 地址',rob.url,true);field('robots.txt 状态',rob.error?errorText(rob.error):String(rob.http_status));if(rob.preview){const dt=el('dt','robots.txt 内容');const dd=el('dd');dd.append(el('pre',rob.preview));if(rob.truncated)dd.append(el('small','预览已截断为 16,000 字符。'));dl.append(dt,dd);}}
 field('月访问量估算',r.traffic?.data?.monthly_visits ?? r.traffic?.error?.message ?? '未接入');field('流量数据月份',r.traffic?.data?.month);field('流量来源',r.traffic?.provider);
 field('页面数据更新时间',new Date(r.checked_at).toLocaleString());field('缓存清除时间',r.cache_cleared_at?new Date(r.cache_cleared_at).toLocaleString():null);field('缓存',`${r.cache.hit?'命中缓存':'本次获取'}；过期时间 ${new Date(r.cache.expires_at).toLocaleString()}`);
 }
 box.append(dl);$('details').append(box);return box;
}
function appendResult(r) { const index=state.results.length;state.results.push(r);renderResult(r,index);syncCacheButtons(); }
function renderResult(r,index) {
 document.getElementById(`detail-${index}`)?.remove();
 const oldRow=document.getElementById(`result-${index}`);
 const row=el('tr');row.id=`result-${index}`;const d=el('td');
 if(r.history?.url){const report=el('a',undefined,'domain-report-link');report.href=r.history.url;report.append(el('strong',r.domain));d.append(report);const links=el('div',undefined,'domain-links');const view=el('a','独立结果 ↗');view.href=r.history.url;links.append(view);const share=el('button','分享本次','text-button share-result');share.type='button';share.onclick=()=>shareResult(r,share);links.append(share);d.append(links);}
 else{d.append(el('strong',r.domain||r.input));if(r.history?.error)d.append(el('small','历史保存失败，可重新查询。'));}const seo=r.seo?.data,reg=r.registration?.data;
 if(seo)d.append(el('small',seo.url,'ellipsis'));row.append(d);
 const date=el('td');date.append(el('span',reg?.created_at?reg.created_at.slice(0,10):'—'));if(r.registration?.error)date.append(el('small',r.registration.error.message));else if(reg&&!reg.created_at)date.append(el('small','RDAP 未提供注册事件'));row.append(date);
 const http=el('td');http.append(el('span',seo?String(seo.http_status):'—',`badge ${!seo?'warn':seo.http_status>=400?'error':''}`));row.append(http);
 const text=el('td',undefined,'seo-cell');
 text.append(metadataPreview(r,'title','Title','未获取到标题'));
 text.append(metadataPreview(r,'description','Description',r.seo?.error?.message||r.error?.message||'未提供 Description'));
 row.append(text);
 const keywords=el('td',undefined,'keywords-cell');keywords.append(metadataPreview(r,'keywords','Keywords'));row.append(keywords);
 row.append(trafficCell(r));
 const status=el('td');const complete=!r.error&&!r.registration?.error&&!r.seo?.error;const warnings=seo?.warnings?.length||seo?.robots_txt?.error||seo?.robots_txt?.http_status>=400||!reg?.created_at;status.append(el('span',r.error?'输入有误':complete?(warnings?'已返回 · 有提示':r.traffic?.status==='ok'?'已完成':'SEO 已完成'):'部分数据不可用',`badge ${r.error?'error':!complete||warnings?'warn':''}`));
 if(r.cache?.hit)status.append(el('small','来自文件缓存'));const detail=addDetail(r,index);const button=el('button','展开详情 ↗','row-detail');button.setAttribute('aria-expanded','false');button.setAttribute('aria-controls',detail.id);button.onclick=()=>{detail.hidden=!detail.hidden;button.setAttribute('aria-expanded',String(!detail.hidden));button.textContent=detail.hidden?'展开详情 ↗':'收起详情 ↑';if(!detail.hidden)detail.scrollIntoView({behavior:'smooth',block:'nearest'});};status.append(button);
 if(!r.error){
  status.append(el('small',`页面更新 ${shortTime(r.checked_at)}`,'updated-at'));
  if(r.cache_cleared_at)status.append(el('small','缓存已清除 · 内容待更新','cache-cleared'));
  const actions=el('div',undefined,'cache-actions');
  for(const [action,label] of [['refresh','重新获取'],['clear_cache','清除缓存']]){const control=el('button',label,'cache-action');control.type='button';control.disabled=state.running;control.onclick=()=>rowAction(index,action);actions.append(control);}status.append(actions);
 }
 row.append(status);if(oldRow)oldRow.replaceWith(row);else $('rows').append(row);$('empty').hidden=true;$('result-count').textContent=String(state.results.length).padStart(2,'0');$('export').disabled=false;
}
async function shareResult(r,button){
 const url=new URL(r.history.snapshot_url||r.history.url,location.origin).href;
 try{await navigator.clipboard.writeText(url);button.textContent='已复制 ✓';setTimeout(()=>button.textContent='分享本次',2000);}
 catch{openDialog('分享这份报告',r.domain,[{name:'复制以下固定历史快照链接',value:url}],'此链接对应本次保存的数据。浏览与分享不会重新请求上游接口。');}
}
function shortTime(value) {
 const date=new Date(value);return Number.isNaN(date.getTime())?'未知':new Intl.DateTimeFormat('zh-CN',{month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(date);
}
function syncCacheButtons() {
 const valid=state.results.some(r=>r.domain&&!r.error);
 $('clear-caches').disabled=state.running||!valid;
 for(const control of document.querySelectorAll('.cache-action'))control.disabled=state.running;
}
async function cacheRequest(domains,action) {
 const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),90000);
 try{
  const response=await fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({domains,action}),signal:controller.signal});
  const data=await response.json();
  if(!response.ok||data.error)throw new Error(data.error?.message||`HTTP ${response.status}`);
  return data.results;
 }finally{clearTimeout(timer);}
}
function markCleared(cleared) {
 for(let index=0;index<state.results.length;index++){
  const r=state.results[index];if(r.error)continue;
  if(r.domain===cleared.domain)r.cache_cleared_at=cleared.cleared_at;
  const root=r.traffic?.data?.domain||r.registration?.data?.domain;
  if(root===cleared.traffic_domain)r.traffic_cache_cleared_at=cleared.cleared_at;
  renderResult(r,index);
 }
}
function actionBusy(busy){state.running=busy;$('analyze').disabled=busy;$('domains').disabled=busy;$('examples').disabled=busy;syncCacheButtons();}
async function rowAction(index,action){
 if(state.running)return;
 const r=state.results[index];actionBusy(true);
 $('progress').textContent=`${action==='refresh'?'正在重新获取最新数据':'正在清除缓存'} · ${r.domain}`;
 try{
  const [updated]=await cacheRequest([r.domain],action);
  if(updated.error)throw new Error(updated.error.message);
  if(action==='refresh'){state.results[index]=updated;renderResult(updated,index);$('progress').textContent=`${r.domain} 已重新查询。各数据的更新时间与错误状态已分别更新。`;}
  else{markCleared(updated);$('progress').textContent=`${r.domain} 的文件缓存已清除。页面保留上次结果；点击“重新获取”获取最新数据。`;}
 }catch(e){$('progress').textContent=`操作未完成：${e.name==='AbortError'?'请求超时，请稍后重试。':e.message}`;}
 finally{actionBusy(false);}
}
$('clear-caches').onclick=async()=>{
 if(state.running)return;
 const domains=[...new Set(state.results.filter(r=>r.domain&&!r.error).map(r=>r.domain))];if(!domains.length)return;
 actionBusy(true);let count=0;
 try{
  for(let i=0;i<domains.length;i+=10){$('progress').textContent=`正在清空本次结果的文件缓存 · ${count} / ${domains.length}`;const results=await cacheRequest(domains.slice(i,i+10),'clear_cache');for(const r of results){if(r.error)throw new Error(r.error.message);markCleared(r);count++;}}
  $('progress').textContent=`已清除 ${count} 个域名的缓存，保留上次结果和更新时间。重新查询或点击“重新获取”即可更新。流量调用额度不会重置。`;
 }catch(e){$('progress').textContent=`已清除 ${count} 个域名；后续操作未完成：${e.message}`;}
 finally{actionBusy(false);}
};
$('stop').onclick=()=>{state.stop=true;$('stop').disabled=true;$('progress').textContent='将在当前域名查询结束后停止；已完成结果会保留。';};
$('analyze').onclick=async()=>{
 const inputs=split();if(!inputs.length){$('progress').textContent='请至少输入一个域名。';$('domains').focus();return;}
 state.running=true;state.stop=false;state.results=[];syncCacheButtons();$('rows').replaceChildren();$('details').replaceChildren();$('empty').hidden=true;$('result-count').textContent='00';$('export').disabled=true;$('analyze').disabled=true;$('domains').disabled=true;$('examples').disabled=true;$('stop').hidden=false;$('stop').disabled=false;
 let completed=0;
 try {
 for(let i=0;i<inputs.length;i++){const input=inputs[i];if(state.stop)break;$('progress').textContent=`正在分析 ${completed+1} / ${inputs.length} · ${input}（网络不通时可能需要约一分钟）`;state.controller=new AbortController();const timer=setTimeout(()=>state.controller.abort(),90000);
 try{const response=await fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({domains:[input]}),signal:state.controller.signal});let data;try{data=await response.json();}catch{throw new Error('服务返回的不是 JSON，请检查 PHP 运行状态。');}if(response.status===429){const seconds=data.error?.code==='busy'?5:Number(response.headers.get('Retry-After'))||60;for(let left=seconds;left>0&&!state.stop;left--){$('progress').textContent=`服务繁忙或达到速率上限，${left} 秒后继续 · 已完成 ${completed} / ${inputs.length}`;await new Promise(resolve=>setTimeout(resolve,1000));}i--;continue;}if(!response.ok||data.error){appendResult({input,error:data.error||{code:'http_error',message:`接口 HTTP ${response.status}`}});completed++;continue;}for(const r of data.results)appendResult(r);completed++;}
 catch(e){appendResult({input,error:{code:e.name==='AbortError'?'client_timeout':'request_failed',message:e.name==='AbortError'?'接口请求超过 90 秒。':e.message}});completed++;}finally{clearTimeout(timer);}
 }
 }finally{state.running=false;syncCacheButtons();state.controller=null;$('analyze').disabled=false;$('domains').disabled=false;$('examples').disabled=false;$('stop').hidden=true;$('progress').textContent=`${state.stop?'队列已停止':'分析结束'} · 已处理 ${completed} / ${inputs.length} 个域名。注册、页面和流量数据可能分别成功或失败。`;}
};
$('export').onclick=()=>{const blob=new Blob([JSON.stringify({exported_at:new Date().toISOString(),results:state.results},null,2)],{type:'application/json'});const url=URL.createObjectURL(blob);const a=el('a');a.href=url;a.download='domain-lens-results.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
count();

const initialDomain=new URLSearchParams(location.search).get('domain');
if(initialDomain&&initialDomain.length<=1000){$('domains').value=initialDomain;count();}
