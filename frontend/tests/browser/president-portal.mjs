// Production React, intercepted synthetic Laravel/SQLite fixture responses. NOT live Laravel integration.
// Generate fixture with PresidentPortalTest + PRESIDENT_BROWSER_FIXTURE, then run against local preview/Chrome.
import assert from 'node:assert/strict'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
const origin='http://127.0.0.1:4178', debug='http://127.0.0.1:9243'
const responses=JSON.parse(await readFile(process.env.PRESIDENT_BROWSER_FIXTURE,'utf8'))
const output=process.env.PRESIDENT_BROWSER_OUTPUT
assert.ok(output); await mkdir(output,{recursive:true})
const target=await (await fetch(debug+'/json/new?about:blank',{method:'PUT'})).json()
const ws=new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject})
let serial=0, simulateError=false, simulateEmpty=false
const pending=new Map(), failures=[], requests=[]
function send(method,params={}) { return new Promise((resolve,reject)=>{const id=++serial,timer=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout'))},15000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params}))}) }
const identity={ user_id:20,roles:['university_president'],permissions:['president_portal.access',...['dashboard','colleges','students','exams','staff','leadership','followup','reports'].map(s=>`president_portal.${s}.view`)],access_scopes:[{type:'university',id:91}] }
ws.onmessage=async event=>{
  const m=JSON.parse(event.data)
  if(m.id){const p=pending.get(m.id);if(!p)return;pending.delete(m.id);clearTimeout(p.timer);m.error?p.reject(Error(JSON.stringify(m.error))):p.resolve(m.result);return}
  if(m.method==='Runtime.exceptionThrown') failures.push(m.params.exceptionDetails)
  if(m.method!=='Fetch.requestPaused')return
  const {requestId,request}=m.params,url=new URL(request.url)
  try {
    if(!url.pathname.startsWith('/api/')){await send(url.origin===origin?'Fetch.continueRequest':'Fetch.failRequest',{requestId,...(url.origin===origin?{}:{errorReason:'BlockedByClient'})});return}
    assert.ok(['GET','OPTIONS'].includes(request.method));requests.push(url.pathname)
    const key=url.pathname.replace('/api/v1/president/','')
    let body=url.pathname==='/api/user'?{success:true,data:identity}:structuredClone(responses[key]),status=200
    if(request.method==='OPTIONS')body={}
    assert.ok(body,'Missing synthetic API response: '+url.pathname)
    if(key==='colleges' && request.method==='GET'){
      body.data=body.data.filter(r=>(!url.searchParams.has('active')||r.is_active===(url.searchParams.get('active')==='1'))&&(!url.searchParams.has('college_id')||String(r.college_id)===url.searchParams.get('college_id')))
      body.meta.total=body.data.length
    }
    if(key==='students'&&simulateEmpty) body={data:[],meta:{total:0,current_page:1,last_page:1}}
    if(key==='students'&&simulateError){status=500;body={message:'خطأ اختبار اصطناعي'}}
    await send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:origin},{name:'Access-Control-Allow-Headers',value:'authorization,content-type,accept'}],body:Buffer.from(JSON.stringify(body)).toString('base64')})
  }catch(e){failures.push(e.message);await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'}).catch(()=>{})}
}
async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value}
const pause=ms=>new Promise(r=>setTimeout(r,ms))
async function wait(expression){for(let i=0;i<100;i++){if(await evaluate(`Boolean(${expression})`))return;await pause(100)}throw Error('UI timeout: '+expression)}
async function capture(name){await evaluate('document.fonts.ready.then(()=>true)');await pause(400);const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await writeFile(join(output,name+'.png'),Buffer.from(shot.data,'base64'))}
try {
  await send('Runtime.enable');await send('Page.enable');await send('Network.enable');await send('Network.setCacheDisabled',{cacheDisabled:true});await send('Fetch.enable',{patterns:[{urlPattern:'*'}]})
  await send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('token','synthetic-test-token');localStorage.setItem('auth.identity',${JSON.stringify(JSON.stringify(identity))});`})
  // Identity key is the application's existing key, not a new auth mechanism.
  const authSource=await readFile(new URL('../../src/features/auth/auth.js',import.meta.url),'utf8')
  const storageKey=authSource.match(/const IDENTITY_KEY = '([^']+)'/)[1]
  await send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem(${JSON.stringify(storageKey)},${JSON.stringify(JSON.stringify(identity))});`})
  for(const [size,width,height] of [['desktop',1440,1000],['mobile',390,844]]){
    await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:size==='mobile'})
    for(const path of ['', 'colleges','students','exams','faculty','leadership','followup','reports','students/1','deans/employee-1','leadership/92','exams/1','followup/grades']){
      await send('Page.navigate',{url:origin+'/president'+(path?'/'+path:'')})
      await wait("document.querySelector('h2') && !document.querySelector('[role=status]')")
      // Wait for the response-driven content, not just the persistent header.
      await wait("document.querySelector('section,table,[role=alert]')")
      assert.equal(await evaluate("document.querySelector('[role=alert]')?.textContent || ''"),'',path)
      assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth+2'),path+' viewport overflow')
      if(path==='deans/employee-1')await wait("document.querySelector('main')?.textContent.includes('2025-08-31') && document.querySelector('main')?.textContent.includes('تعارض')")
      if(path==='students/1')await wait("document.querySelector('main')?.textContent.includes('النتائج الرسمية')")
      if(['','colleges','deans/employee-1','followup'].includes(path))await capture(size+'-'+(path.replaceAll('/','-')||'home'))
    }
  }
  simulateEmpty=true;await send('Page.navigate',{url:origin+'/president/students'});await wait("document.querySelector('main')?.textContent.includes('لا توجد بيانات مطابقة')");simulateEmpty=false
  simulateError=true;await send('Page.navigate',{url:origin+'/president/students'});await wait("document.querySelector('[role=alert]')");simulateError=false
  await evaluate("[...document.querySelectorAll('button')].find(b=>b.textContent.includes('إعادة المحاولة')).click()")
  await wait("document.querySelector('tbody tr') && !document.querySelector('[role=alert]')")
  assert.deepEqual(failures,[])
  console.log(JSON.stringify({passed:true,pages:26,requests:requests.length,checks:['desktop/mobile','eight sections and details','ended dean evidence','official student results','empty/error/retry','no writes'],api:'intercepted synthetic Laravel fixture, not live integration',output}))
}catch(error){console.error(JSON.stringify({failures,requests}));throw error}finally{await send('Target.closeTarget',{targetId:target.id}).catch(()=>{});ws.close()}
