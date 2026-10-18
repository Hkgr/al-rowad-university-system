// Real production React -> real local Laravel -> isolated synthetic SQLite. No API response interception.
// Export DB/tokens using PortalReportsTest + PORTAL_REPORT_BROWSER_DIR, never a production dump.
import assert from 'node:assert/strict'
import {readFile,mkdir,writeFile} from 'node:fs/promises'
import {join} from 'node:path'
import {REPORT_PATHS,reportEndpoint} from '../../src/features/portal-reports/reports.js'
const origin='http://localhost:5173',api='http://127.0.0.1:8099',debug='http://127.0.0.1:9244'
const directory=process.env.PORTAL_REPORT_BROWSER_DIR
assert.ok(directory,'Supply the test-only exported directory')
const actors=JSON.parse(await readFile(join(directory,'identities.json'),'utf8')),output=join(directory,'screenshots')
await mkdir(output,{recursive:true})
const target=await(await fetch(debug+'/json/new?about:blank',{method:'PUT'})).json()
const ws=new WebSocket(target.webSocketDebuggerUrl)
await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject})
let serial=0,bootstrap,injectFailure=false,attempts=0,pages=0
const pending=new Map(),failures=[],responses=[]
// Bind each API response to the request that produced it. Chrome may report the CORS
// preflight and the GET under different ids, or reuse one id, so methods are queued per id
// and per URL from Fetch.requestPaused (which always carries the method) before continue.
const methodsById=new Map(),queuedByUrl=new Map(),injectedIds=new Set()
const expectedForbidden=new Set()
function pushMethod(id,method){if(!id)return;const list=methodsById.get(id)||[];list.push(method);methodsById.set(id,list)}
function takeMethod(id){const list=methodsById.get(id);if(!list?.length)return null;return list.shift()}
function send(method,params={}){return new Promise((resolve,reject)=>{const id=++serial,timer=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout'))},20000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params}))})}
ws.onmessage=async event=>{
  const m=JSON.parse(event.data)
  if(m.id){const p=pending.get(m.id);if(!p)return;pending.delete(m.id);clearTimeout(p.timer);m.error?p.reject(Error(JSON.stringify(m.error))):p.resolve(m.result);return}
  if(m.method==='Runtime.exceptionThrown')failures.push(m.params.exceptionDetails.text)
  if(m.method==='Network.responseReceived'&&m.params.response.url.startsWith(api)){
    const url=m.params.response.url
    let method=takeMethod(m.params.requestId)
    if(!method){const queue=queuedByUrl.get(url)||[];method=queue.shift()?.method||null}
    else {const queue=queuedByUrl.get(url)||[];const index=queue.findIndex(item=>item.networkId===m.params.requestId);if(index>=0)queue.splice(index,1)}
    const pathname=new URL(url).pathname
    responses.push({url,status:m.params.response.status,method,injected:injectedIds.has(m.params.requestId),expectedForbidden:expectedForbidden.has(pathname)})
  }
  if(m.method!=='Fetch.requestPaused')return
  const {requestId,request,networkId}=m.params,url=new URL(request.url)
  try{
    if(url.origin!==origin&&url.origin!==api){await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});return}
    if(url.origin===api){
      assert.ok(['GET','OPTIONS'].includes(request.method),'Report UI must not write '+request.method+' '+url.pathname)
      const queue=queuedByUrl.get(request.url)||[]
      queue.push({method:request.method,networkId:networkId||null,fetchId:requestId})
      queuedByUrl.set(request.url,queue)
      pushMethod(networkId||requestId,request.method)
      if(networkId&&networkId!==requestId)pushMethod(requestId,request.method)
      if(injectFailure&&url.pathname.endsWith('/dean/students')){attempts++;injectedIds.add(requestId);if(networkId)injectedIds.add(networkId);await send('Fetch.failRequest',{requestId,errorReason:'ConnectionFailed'});return}
    }
    await send('Fetch.continueRequest',{requestId})
  }catch(error){failures.push(error.message);await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'}).catch(()=>{})}
}
async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value}
const pause=ms=>new Promise(r=>setTimeout(r,ms))
async function wait(expression){for(let i=0;i<120;i++){if(await evaluate(`Boolean(${expression})`))return;await pause(100)}throw Error('UI timeout '+expression+'; '+await evaluate('document.body.innerText.slice(-1600)'))}
async function identity(actor){if(bootstrap)await send('Page.removeScriptToEvaluateOnNewDocument',{identifier:bootstrap});bootstrap=(await send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.clear();localStorage.setItem('token',${JSON.stringify(actor.token)});localStorage.setItem('user',${JSON.stringify(JSON.stringify(actor.identity))});`})).identifier}
async function load(path){await send('Page.navigate',{url:origin+path});await wait("document.querySelector('h2')?.textContent.includes('التقارير') && !document.querySelector('[role=status]')");await wait("document.querySelector('main')?.textContent.includes('وقت الحساب') || document.querySelector('main')?.textContent.includes('غير متاح:') || document.querySelector('[role=alert]')");pages++}
async function capture(name){await evaluate('document.fonts.ready.then(()=>true)');await pause(400);await writeFile(join(output,name+'.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'))}
function acceptable(response){
  if(response.injected)return true
  if(response.method==='OPTIONS')return response.status===204
  if(response.method==='GET'&&response.status===200)return true
  if(response.method==='GET'&&response.status===403&&response.expectedForbidden)return true
  return false
}
try{
  await send('Runtime.enable');await send('Page.enable');await send('Network.enable');await send('Network.setCacheDisabled',{cacheDisabled:true});await send('Fetch.enable',{patterns:[{urlPattern:'*'}]})
  for(const [size,width,height]of[['desktop',1440,1000],['mobile',390,844]]){
    await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:size==='mobile'})
    for(const [portal,actor] of Object.entries(actors)){
      await identity(actor)
      const definitions=await fetch(api+'/api'+reportEndpoint(portal),{headers:{Authorization:'Bearer '+actor.token,Accept:'application/json'}})
      assert.equal(definitions.status,200,portal)
      for(const report of (await definitions.json()).data.reports){
        await load(REPORT_PATHS[portal]+'?report='+report.id)
        assert.equal(await evaluate("document.querySelector('[role=alert]')?.textContent||''"),'',portal+'/'+report.id)
        assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),portal+' viewport overflow')
        if(['dean','student','president','technical'].includes(portal)&&['students','academic','trends','activity'].includes(report.id))await capture(size+'-'+portal+'-'+report.id)
      }
    }
  }
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false})
  await identity(actors.dean);await load('/dean/reports?report=students&category=missing_synthetic_status')
  await wait("document.querySelector('main')?.textContent.includes('لا توجد سجلات ضمن المرشحات')")
  await capture('desktop-dean-empty')
  await load('/dean/reports?report=students')
  await evaluate("(()=>{const b=[...document.querySelectorAll('#report-groups button')];if(b.length<1)throw Error('no group');b[0].click()})()")
  await wait("document.querySelector('main')?.textContent.includes('المجموعة:')&&!document.querySelector('[role=alert]')")
  await evaluate("(()=>{const b=[...document.querySelectorAll('button')].filter(b=>b.textContent==='مسح المرشحات');if(b.length!==1)throw Error('ambiguous clear');b[0].click()})()")
  await wait("!document.querySelector('main')?.textContent.includes('المجموعة:')&&document.querySelector('#report-rows tbody tr')")
  injectFailure=true;await load('/dean/reports?report=students');await wait("document.querySelector('[role=alert]')")
  assert.match(await evaluate("document.querySelector('[role=alert]').textContent"),/تعذّر الاتصال بالخادم/)
  await capture('desktop-dean-network-error')
  assert.ok(attempts>0);injectFailure=false
  await evaluate("(()=>{const b=[...document.querySelectorAll('button')].filter(b=>b.textContent.includes('إعادة المحاولة'));if(b.length!==1)throw Error('ambiguous retry');b[0].click()})()")
  await wait("document.querySelector('#report-rows tbody tr')&&!document.querySelector('[role=alert]')")
  await capture('desktop-dean-retry')
  expectedForbidden.add('/api/v1/portal-reports/dean/accounts')
  await load('/dean/reports?report=accounts')
  const forbidden=await evaluate("document.querySelector('[role=alert]')?.textContent||''")
  assert.match(forbidden,/لا يملك حسابك صلاحية هذا التقرير/)
  assert.doesNotMatch(forbidden,/الوزارة/)
  await capture('desktop-dean-forbidden')
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true})
  await load('/dean/reports?report=students&category=missing_synthetic_status')
  await wait("document.querySelector('main')?.textContent.includes('لا توجد سجلات ضمن المرشحات')")
  await capture('mobile-dean-empty')
  await load('/dean/reports?report=accounts')
  await capture('mobile-dean-forbidden')
  assert.deepEqual(failures,[])
  const bad=responses.filter(response=>!acceptable(response))
  assert.deepEqual(bad,[],JSON.stringify(bad))
  const options204=responses.filter(response=>response.method==='OPTIONS'&&response.status===204).length
  const gets200=responses.filter(response=>response.method==='GET'&&response.status===200).length
  assert.ok(options204>0,'CORS preflight responses were not bound as OPTIONS 204')
  assert.ok(gets200>0,'No successful GET responses were bound')
  assert.equal(responses.filter(response=>!response.method&&!response.injected).length,0)
  console.log(JSON.stringify({passed:true,pages,apiResponses:responses.length,options204,gets200,api:'live Laravel with isolated synthetic SQLite; no mocked API responses',checks:['desktop/mobile','all authorized reports in eleven portal contexts','no viewport overflow','empty','group filter and clear','network failure/retry','direct unauthorized report 403','correlated OPTIONS 204 and GET 200','no mutation requests'],output}))
}finally{await send('Target.closeTarget',{targetId:target.id}).catch(()=>{});ws.close()}
