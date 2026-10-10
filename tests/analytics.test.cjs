const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const source=readFileSync(__dirname+'/../assets/js/analytics.js','utf8');
function boot(granted=true, storage=new Map(), article='carrier-guide') {
 const listeners={}; const nodes=[]; const elements=[];
 function el(){const n={children:[],hidden:false,style:{},attrs:{},textContent:'',setAttribute(k,v){this.attrs[k]=v},appendChild(n){this.children.push(n);nodes.push(n)},addEventListener(k,v){this[k]=v}};elements.push(n);return n;}
 const local=new Map(granted?[['imeihub.analytics.consent.v1','granted']]:[]);
 const wrap=m=>({getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,v),removeItem:k=>m.delete(k)});
 const win={location:new URL('https://imeihub.net/article/'+article+'?imei=SECRET'),sessionStorage:wrap(storage),localStorage:wrap(local)};
 const doc={currentScript:{getAttribute:()=> 'G-TEST123'},referrer:'https://www.google.com/search?q=secret',head:el(),body:el(),createElement:el,
 querySelector:q=>q==='[data-article-slug]'&&article?{getAttribute:()=>article}:null,
 addEventListener:(k,v)=>listeners[k]=v};
 vm.runInNewContext(source,{window:win,document:doc,URL,Date,Number,Object,String});
 return {win,doc,listeners,elements,nodes,storage,events:()=> (win.dataLayer||[]).filter(x=>x[0]==='event')};
}
const order='01ARZ3NDEKTSV4RRFFQ69G5FAV';
const result={ok:true,status:'success',public_id:order,service_code:'BLACKLIST',cost:'1.250',imei:'SECRET',details:{email:'SECRET'}};
let b=boot();let a=b.win.imeihubAnalytics;
assert.equal(JSON.stringify(b.events().map(x=>x[1])), JSON.stringify(['page_view','article_view']));
a.purchase({...result,cost:0},a.context());
a.purchase({...result,status:'processing'},a.context());
a.purchase({...result,ok:false},a.context());
a.purchase(result); // old history never counted
assert.equal(b.events().length,2);
a.purchase(result,a.context());a.purchase(result,a.context());
let p=b.events().filter(x=>x[1]==='purchase');assert.equal(p.length,1);
assert.equal(p[0][2].value,1.25);assert.equal(p[0][2].currency,'USD');
assert.equal(p[0][2].article_slug,'carrier-guide');
assert(!JSON.stringify(b.win.dataLayer).includes('SECRET'));
// Pending completion after navigating to Orders keeps the original article.
b=boot();a=b.win.imeihubAnalytics;a.pending(order,a.context());
b=boot(true,b.storage,'');a=b.win.imeihubAnalytics;
a.purchase({...result,status:'SUCCESS'});a.purchase(result);
assert.equal(b.events().filter(x=>x[1]==='purchase').length,1);
assert.equal(b.events().find(x=>x[1]==='purchase')[2].article_slug,'carrier-guide');
// Reload/review never emits a second purchase.
b=boot(true,b.storage,'');b.win.imeihubAnalytics.purchase(result);
assert.equal(b.events().filter(x=>x[1]==='purchase').length,0);
// Consent denied: no tag requests, analytics storage or events.
b=boot(false);a=b.win.imeihubAnalytics;a.pending(order,{});a.purchase(result,{});
assert.equal(b.events().length,0);assert.equal(b.doc.head.children.length,0);assert.equal(b.storage.size,0);
// Explicit opt-in, decline and re-enable.
b.elements.find(x=>x.textContent==='Allow analytics').click();
assert.equal(b.doc.head.children.length,1);
b.elements.find(x=>x.textContent==='No thanks').click();
const count=b.events().length;a.purchase(result,{});assert.equal(b.events().length,count);
b.elements.find(x=>x.textContent==='Allow analytics').click();
assert.equal(b.doc.head.children.length,1);
// Article service click categorization; external links are ignored.
b=boot();const link={href:'https://imeihub.net/service.php?slug=blacklist',closest:()=>null};
b.listeners.click({target:{closest:()=>link}});
assert.equal(b.events().at(-1)[1],'service_cta_click');
assert.equal(b.events().at(-1)[2].service_slug,'blacklist');
link.href='https://other.example/service.php?slug=blacklist';const n=b.events().length;
b.listeners.click({target:{closest:()=>link}});assert.equal(b.events().length,n);
console.log('PASS: successful paid, free/failure exclusions, pending/history, duplicate, consent, CTA, payload privacy');
