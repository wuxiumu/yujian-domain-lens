for(const button of document.querySelectorAll('[data-share-url]')){
 button.addEventListener('click',async()=>{
  const text=button.textContent;
  try{await navigator.clipboard.writeText(button.dataset.shareUrl);button.textContent='链接已复制 ✓';setTimeout(()=>button.textContent=text,2200);}
  catch{window.prompt('复制此链接即可分享报告：',button.dataset.shareUrl);}
 });
}
