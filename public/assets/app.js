const $ = id => document.getElementById(id);
const state = {results: [], running: false, stop: false, controller: null};
const split = () => [...new Set($('domains').value.split(/[\n\r,，]+/u).map(s=>s.trim()).filter(Boolean))];
function count() { $('count').textContent = `${split().length} 个域名`; }
$('domains').addEventListener('input',count);
$('examples').onclick=()=>{$('domains').value='51chigua.com\n51chiguai.com';count();};
function el(tag,text,cls) { const e=document.createElement(tag); if(text!==undefined)e.textContent=text; if(cls)e.className=cls; return e; }
function safeLink(url) { try { const u=new URL(url); if(!['http:','https:'].includes(u.protocol)) return el('span',url); const a=el('a',url); a.href=u.href; a.target='_blank'; a.rel='noopener noreferrer'; return a; } catch { return el('span',url); } }
function errorText(error) { return error ? `${error.message} [${error.code}]` : '未提供'; }
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
 field('检查时间',new Date(r.checked_at).toLocaleString());field('缓存',`${r.cache.hit?'命中缓存':'本次获取'}；过期时间 ${new Date(r.cache.expires_at).toLocaleString()}`);
 }
 box.append(dl);$('details').append(box);return box;
}
function appendResult(r) {
 const index=state.results.length;state.results.push(r);const row=el('tr');const d=el('td');d.append(el('strong',r.domain||r.input));const seo=r.seo?.data,reg=r.registration?.data;
 if(seo)d.append(el('small',seo.url,'ellipsis'));row.append(d);
 const date=el('td');date.append(el('span',reg?.created_at?reg.created_at.slice(0,10):'—'));if(r.registration?.error)date.append(el('small',r.registration.error.message));else if(reg&&!reg.created_at)date.append(el('small','RDAP 未提供注册事件'));row.append(date);
 const http=el('td');http.append(el('span',seo?String(seo.http_status):'—',`badge ${!seo?'warn':seo.http_status>=400?'error':''}`));row.append(http);
 const text=el('td');text.append(el('div',seo?.meta?.title||'未获取到标题','ellipsis'));text.append(el('small',seo?.meta?.description||r.seo?.error?.message||r.error?.message||'未提供 Description','ellipsis'));row.append(text);
 const status=el('td');const complete=!r.error&&!r.registration?.error&&!r.seo?.error;const warnings=seo?.warnings?.length||seo?.robots_txt?.error||seo?.robots_txt?.http_status>=400||!reg?.created_at;status.append(el('span',r.error?'输入有误':complete?(warnings?'已返回 · 有提示':'已完成'):'部分数据不可用',`badge ${r.error?'error':!complete||warnings?'warn':''}`));
 if(r.cache?.hit)status.append(el('small','来自文件缓存'));const detail=addDetail(r,index);const button=el('button','展开详情 ↗','row-detail');button.setAttribute('aria-expanded','false');button.setAttribute('aria-controls',detail.id);button.onclick=()=>{detail.hidden=!detail.hidden;button.setAttribute('aria-expanded',String(!detail.hidden));button.textContent=detail.hidden?'展开详情 ↗':'收起详情 ↑';if(!detail.hidden)detail.scrollIntoView({behavior:'smooth',block:'nearest'});};status.append(button);row.append(status);$('rows').append(row);$('empty').hidden=true;$('result-count').textContent=String(state.results.length).padStart(2,'0');$('export').disabled=false;
}
$('stop').onclick=()=>{state.stop=true;$('stop').disabled=true;$('progress').textContent='将在当前域名查询结束后停止；已完成结果会保留。';};
$('analyze').onclick=async()=>{
 const inputs=split();if(!inputs.length){$('progress').textContent='请至少输入一个域名。';$('domains').focus();return;}
 state.running=true;state.stop=false;state.results=[];$('rows').replaceChildren();$('details').replaceChildren();$('empty').hidden=true;$('result-count').textContent='00';$('export').disabled=true;$('analyze').disabled=true;$('domains').disabled=true;$('examples').disabled=true;$('stop').hidden=false;$('stop').disabled=false;
 let completed=0;
 try {
 for(let i=0;i<inputs.length;i++){const input=inputs[i];if(state.stop)break;$('progress').textContent=`正在分析 ${completed+1} / ${inputs.length} · ${input}（网络不通时可能需要约一分钟）`;state.controller=new AbortController();const timer=setTimeout(()=>state.controller.abort(),90000);
 try{const response=await fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({domains:[input]}),signal:state.controller.signal});let data;try{data=await response.json();}catch{throw new Error('服务返回的不是 JSON，请检查 PHP 运行状态。');}if(response.status===429){const seconds=data.error?.code==='busy'?5:Number(response.headers.get('Retry-After'))||60;for(let left=seconds;left>0&&!state.stop;left--){$('progress').textContent=`服务繁忙或达到速率上限，${left} 秒后继续 · 已完成 ${completed} / ${inputs.length}`;await new Promise(resolve=>setTimeout(resolve,1000));}i--;continue;}if(!response.ok||data.error){appendResult({input,error:data.error||{code:'http_error',message:`接口 HTTP ${response.status}`}});completed++;continue;}for(const r of data.results)appendResult(r);completed++;}
 catch(e){appendResult({input,error:{code:e.name==='AbortError'?'client_timeout':'request_failed',message:e.name==='AbortError'?'接口请求超过 90 秒。':e.message}});completed++;}finally{clearTimeout(timer);}
 }
 }finally{state.running=false;state.controller=null;$('analyze').disabled=false;$('domains').disabled=false;$('examples').disabled=false;$('stop').hidden=true;$('progress').textContent=`${state.stop?'队列已停止':'分析结束'} · 已处理 ${completed} / ${inputs.length} 个域名。注册时间与页面信息可能分别成功或失败。`;}
};
$('export').onclick=()=>{const blob=new Blob([JSON.stringify({exported_at:new Date().toISOString(),results:state.results},null,2)],{type:'application/json'});const url=URL.createObjectURL(blob);const a=el('a');a.href=url;a.download='domain-lens-results.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
count();
