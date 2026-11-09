import {renderReply} from './assistant/reply.js?v=20261110-live';
import {pageGuide} from './assistant/guides.js?v=20261110-live';
import {downloadReportPdf} from './assistant/report.js?v=20261110-live';
import {createMicCapture, createPlaybackQueue, AUDIO_INPUT_MIME_TYPE} from './assistant/audio.js?v=20261110-live';
import {createLiveConnection, createOutbox, friendlyError, historyToSeed, LIVE_COPY} from './assistant/live.js?v=20261110-live';
import {mountConsoleGuide, readRegistry} from './assistant/guide-overlay.js?v=20261110-live';
import {pendingLabel, stepList, stepsSummary, TURN_COPY} from './assistant/turn.js?v=20261110-live';

const panel=document.getElementById('sp-assistant');
let embeddedConfig=null;
function hostEvent(event){
 const payload=JSON.stringify({source:'seapass-assistant',...event});
 if(globalThis.SeaPassHost?.postMessage)globalThis.SeaPassHost.postMessage(payload);
 else if(embeddedConfig?.parentOrigin)window.parent.postMessage(JSON.parse(payload),embeddedConfig.parentOrigin);
}
if(panel?.dataset.embed==='true'){
 let booted=false;
 window.SeaPassAssistantBootstrap=config=>{
  if(booted||!config?.token||!config?.identity)return;
  booted=true;embeddedConfig=config;document.body.classList.toggle('light-mode',config.theme==='light');panel.dataset.user=config.identity;init();
 };
 window.addEventListener('message',event=>{
  if(event.source!==window.parent||event.data?.type!=='seapass-bootstrap')return;
  // First bootstrap comes from the hosting app and binds all subsequent replies to its origin.
  window.SeaPassAssistantBootstrap({...event.data,parentOrigin:event.origin});
 });
 if(globalThis.SeaPassHost?.postMessage)hostEvent({type:'ready'});
 else window.parent.postMessage({source:'seapass-assistant',type:'ready'},'*');
}else if(panel)init();
function init(){
 const $=id=>document.getElementById(id), log=$('sp-assistant-messages'), notice=$('sp-assistant-notice'), input=$('sp-assistant-input'), sendButton=$('sp-assistant-send');
 const storageKey='seapass.assistant.v1.'+panel.dataset.user;
 // Model routing (Jay, 2026-11-10): typed and spoken messages both go over Gemini Live. The text endpoint is the
 // backup for Text mode (quota or outage), attachments, and when Live cannot be reached in time.
 const LIVE_WAIT_MS=6000,RELEASE_MS=60000,LIVE_RETRY_MS=300000,SETTLE_MS=1200;
 let chats=[],current=null,busy=false,attachments=[],live=null,liveState='idle',liveBlockedUntil=0,releaseTimer=null,voiceWanted=false,mic=null,micBlocked=false,micNoticeShown=false,playback=null,providerReady=null,toolChain=Promise.resolve(),userPart='',modelPart='',liveBubble=null,voiceUserBubble=null,speakTurn=false,droppingLive=false,settleTimer=null,turn=null,pendingEl=null;
 try{chats=JSON.parse(localStorage.getItem(storageKey)||'[]').filter(c=>Array.isArray(c.messages)).slice(0,20);}catch{}
 const save=()=>{try{localStorage.setItem(storageKey,JSON.stringify(chats.slice(0,20)));}catch{notice.textContent='Chat history could not be saved on this device.';}};
 const say=(text)=>{notice.textContent=text||'';};
 const state=text=>$('sp-assistant-state').textContent=text;
 const IDLE_LINE='Trips, tickets and a little guidance',MIC_DENIED='Microphone access was denied. You can still type to the assistant.';
 const VOICE_LINES={idle:'Connecting live voice…',connecting:'Connecting live voice…',ready:'Live voice connected',reconnecting:'Reconnecting…',fallback:'Text mode',offline:'Offline'};
 const statusLine=()=>voiceWanted?(VOICE_LINES[liveState]||IDLE_LINE):navigator.onLine===false?'Offline':liveState==='fallback'?'Text mode':busy?'Working on your question':IDLE_LINE;
 state(IDLE_LINE);
 async function api(path,body,signal){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),90000),relay=()=>controller.abort();signal?.addEventListener('abort',relay);
  try{
   const r=await fetch(panel.dataset.endpoint+'/'+path,{method:body?'POST':'GET',credentials:'same-origin',signal:controller.signal,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':panel.dataset.csrf,...(embeddedConfig?{'Authorization':'Bearer '+embeddedConfig.token}:{})},...(body?{body:JSON.stringify(body)}:{})});
   const data=await r.json().catch(()=>({}));if(!r.ok){const error=new Error(friendlyError({status:r.status,message:data.message}));error.status=r.status;throw error;}return data;
  }catch(e){if(e.name==='AbortError'){if(signal?.aborted)throw e;throw new Error(LIVE_COPY.tooLong);}if(e.status)throw e;throw new Error(friendlyError(e));}finally{clearTimeout(timer);signal?.removeEventListener('abort',relay);}
 }
 function newChat(){cancelTurn(false);releaseLive();outbox.clear();current={id:globalThis.crypto?.randomUUID?.()||String(Date.now()),title:'New conversation',messages:[]};chats.unshift(current);chats=chats.slice(0,20);log.replaceChildren();$('sp-assistant-history').hidden=true;save();welcome();}
 function welcome(){if(!current.messages.length){const el=document.createElement('div');el.className='sp-welcome';const p=document.createElement('p');p.textContent='Ask about trips, fares or bookings, or let me show you this page.';el.append(p);if(consoleGuide?.hasPreset('getting_started')){const b=document.createElement('button');b.type='button';b.className='sp-welcome-tour';b.textContent='Show me around';b.onclick=()=>consoleGuide.startPreset('getting_started',{gesture:true}).catch(e=>say(e.message));el.append(b);}log.append(el);}}
 // The steps a reply really took, collapsed under one short line.
 function stepsRow(names){const steps=stepList(names);if(!steps.length)return null;const d=document.createElement('details');d.className='sp-steps';const s=document.createElement('summary');s.textContent=stepsSummary(names);const list=document.createElement('ol');for(const step of steps){const li=document.createElement('li');li.textContent=step.count>1?step.label+' ('+step.count+' times)':step.label;list.append(li);}d.append(s,list);return d;}
 function bubble(role,text,record=true,steps=[]){
  log.querySelector('.sp-welcome')?.remove(); const el=document.createElement('article');el.className='sp-message';el.dataset.role=role;
  const label=document.createElement('small');label.textContent=role==='user'?'You':'SeaPass';el.append(label);
  if(role==='assistant'){const row=stepsRow(steps);if(row)el.append(row);}
  const content=document.createElement('div');content.className='sp-message-text';content.textContent=text;if(role==='assistant')renderReply(content,text);
  if(role==='assistant' && text.length>1400){const brief=document.createElement('p');brief.textContent=text.split('\n').filter(Boolean).slice(0,3).join('\n');el.append(brief);const detail=document.createElement('details'),summary=document.createElement('summary');summary.textContent='Read full report';detail.append(summary,content);el.append(detail);}else el.append(content);
  if(role==='assistant'&&text){const actions=document.createElement('div');actions.className='sp-message-tools';const copy=document.createElement('button');copy.type='button';copy.textContent='Copy';copy.onclick=()=>navigator.clipboard.writeText(text).then(()=>say('Copied.')).catch(()=>say('Copy is unavailable in this browser.'));actions.append(copy);
   if(text.length>700){const pdf=document.createElement('button');pdf.type='button';pdf.textContent='Download PDF';pdf.onclick=()=>downloadReportPdf(text).catch(e=>say(e.message));actions.append(pdf);}el.append(actions);}
  log.insertBefore(el,pendingEl&&pendingEl.parentNode===log?pendingEl:null);log.scrollTop=log.scrollHeight;
  if(record){const entry={role,text};if(role==='assistant'&&steps?.length)entry.steps=steps.slice(0,20);current.messages.push(entry);current.messages=current.messages.slice(-80);if(current.title==='New conversation'&&role==='user')current.title=text.slice(0,60);save();}return content;
 }
 // One status row where the reply will land: what the assistant is doing right now.
 function showPending(){const text=turn?pendingLabel({phase:turn.phase,tool:turn.tool,connection:turn.path==='live'?liveState:'ready'}):'';if(!text){pendingEl?.remove();pendingEl=null;return;}if(!pendingEl){pendingEl=document.createElement('div');pendingEl.className='sp-pending';pendingEl.setAttribute('role','status');pendingEl.setAttribute('aria-live','polite');const mark=document.createElement('span');mark.className='sp-pending-mark';mark.setAttribute('aria-hidden','true');const words=document.createElement('span');words.className='sp-pending-text';pendingEl.append(mark,words);}pendingEl.querySelector('.sp-pending-text').textContent=text;log.append(pendingEl);log.scrollTop=log.scrollHeight;}
 function failure(message,retryText){const box=document.createElement('div');box.className='sp-failure';box.setAttribute('role','alert');const p=document.createElement('p');p.textContent=message;const b=document.createElement('button');b.type='button';b.textContent='Retry';b.onclick=()=>{box.remove();send(retryText,{retry:true});};box.append(p,b);log.append(box);log.scrollTop=log.scrollHeight;}
 function history(){const box=$('sp-assistant-history');box.replaceChildren();box.hidden=!box.hidden;for(const chat of chats){const row=document.createElement('div'),open=document.createElement('button'),del=document.createElement('button');open.textContent=chat.title;open.onclick=()=>{cancelTurn(false);releaseLive();outbox.clear();current=chat;log.replaceChildren();chat.messages.forEach(m=>bubble(m.role,m.text,false,m.steps));box.hidden=true;};del.textContent='Delete';del.onclick=()=>{chats=chats.filter(c=>c.id!==chat.id);save();if(current?.id===chat.id)newChat();box.hidden=true;};row.append(open,del);box.append(row);}}
 function targets(){
  const candidates=[...document.querySelectorAll('main h1,main h2,main button,main input,main select,main a[href]'),...document.querySelectorAll('nav a[href],aside a[href]')].filter(el=>!panel.contains(el)&&el.getClientRects().length);
  const seen=new Set();return candidates.filter(el=>{const label=(el.getAttribute('aria-label')||el.textContent||el.getAttribute('placeholder')||'').trim();if(!label||label.length>170||seen.has(label))return false;seen.add(label);return true;}).slice(0,24).map((el,i)=>{if(!el.dataset.spGuide)el.dataset.spGuide='target-'+i;const label=(el.getAttribute('aria-label')||el.textContent||el.getAttribute('placeholder')||'').trim().slice(0,150);let message='This control opens '+label.toLowerCase()+'.';if(el.matches('h1'))message='You are on '+label+'. '+(el.nextElementSibling?.matches('p')?el.nextElementSibling.textContent.trim():'This walkthrough stays on the current page.');if(el.matches('h2'))message='This section contains '+label.toLowerCase()+'. Review its details before making changes.';if(el.matches('input,select'))message='Use '+label.toLowerCase()+' to set this value. Changes are applied only after you use the page save control.';if(el.matches('button'))message='Use '+label.toLowerCase()+' to apply or open this action. Review the values first.';return {id:el.dataset.spGuide,label,message,el};});
 }
 function context(){if(embeddedConfig)return embeddedConfig.context||{};const main=document.querySelector('main')||document.body;return{path:location.pathname,label:document.querySelector('main h1,h1')?.textContent?.trim()||document.title,text:(main.innerText||'').slice(0,6000),targets:targets().map(({id,label})=>({id,label}))};}
 // Console guide (public/js/assistant/guide-*.js): read-only tours that also work without the AI connection.
 const consoleGuide=embeddedConfig?null:mountConsoleGuide({registryData:readRegistry(),identity:panel.dataset.user,panel,launcher:$('sp-assistant-launch'),say,legacyPageSteps:()=>pageGuide(location.pathname).filter(([selector])=>document.querySelector(selector)?.getClientRects().length).map(([selector,title,body])=>({selector,title,body}))});
 function closeGuide(){consoleGuide?.exit();}
 function guide(target,message){if(embeddedConfig){hostEvent({type:'guide',target,message});return;}(target?consoleGuide.highlight({target,message,label:targets().find(t=>t.id===target)?.label}):consoleGuide.currentPage({gesture:true})).catch(e=>say(e.message));}
 async function effect(result){
  if(result.confirmation){const c=result.confirmation,card=document.createElement('div');card.className='sp-confirm';const p=document.createElement('p');p.textContent='Confirm '+c.tool.replaceAll('_',' ')+'?\n'+Object.entries(c.args).map(([k,v])=>k.replaceAll('_',' ')+': '+v).join('\n');card.append(p);for(const [approved,label]of[[true,'Confirm change'],[false,'Cancel']]){const b=document.createElement('button');b.type='button';b.textContent=label;b.onclick=async()=>{card.querySelectorAll('button').forEach(x=>x.disabled=true);try{const r=await api('tool',{confirmation_token:c.token,approved});p.textContent=r.status==='confirmed'?'Change saved.':'Change cancelled.';current.messages.push({role:'assistant',text:p.textContent});save();}catch(e){p.textContent=e.message;}};card.append(b);}log.insertBefore(card,pendingEl&&pendingEl.parentNode===log?pendingEl:null);log.scrollTop=log.scrollHeight;}
  const a=result.client_action;if(!a)return;
  if(a.type==='navigate'&&embeddedConfig){hostEvent({type:'navigate',page:a.page,path:a.path,args:a.args||{}});return;}
  if((a.type==='highlight_element'||a.type==='start_tour')&&embeddedConfig){hostEvent({type:'guide',action:a});return;}
  if(a.type==='navigate'){const link=document.createElement('button');link.type='button';link.textContent='Open '+a.page;link.onclick=()=>{if(a.path.startsWith('/admin/')){save();location.assign(a.path);}};log.append(link);}
  // Settles once the first step is shown, missing or on another page (about 4s at most). The tour then runs on its own.
  // result.guide rides back on a Live tool response: shown, started, not_found, elsewhere or cancelled.
  const run=async p=>{try{result.guide=await p;}catch(e){say(e.message);result.guide={status:'error',message:e.message};}};
  if(a.type==='highlight_element'){if(a.tour)await run(consoleGuide.showTour(a.tour));else await run(consoleGuide.highlight({target:a.args.target,message:a.args.message,label:targets().find(t=>t.id===a.args.target)?.label}));}
  if(a.type==='start_tour')await run(consoleGuide.showTour(a.tour||{preset:'current_page'}));
 }
 // While a reply is being made the Send button becomes Stop.
 function setBusy(value){busy=value;panel.querySelectorAll('nav button,#sp-assistant-history button').forEach(b=>b.disabled=value);if(value)$('sp-assistant-history').hidden=true;sendButton.textContent=value?'Stop':'Send';sendButton.dataset.mode=value?'stop':'send';sendButton.setAttribute('aria-label',value?'Stop this reply':'Send message');state(statusLine());}
 function startTurn(path,text){turn={path,text,phase:'waiting',tool:null,steps:[],running:0,controller:path==='text'?new AbortController():null,cancelled:false};setBusy(true);showPending();return turn;}
 function endTurn(t){if(turn!==t)return;turn=null;showPending();setBusy(false);}
 // Stop: the text request is aborted; a Live reply still arriving for this turn is dropped.
 function cancelTurn(show=true){const t=turn;if(!t)return;clearTimeout(settleTimer);settleTimer=null;t.cancelled=true;t.controller?.abort();if(t.path==='live'){droppingLive=!!live?.turnOpen;liveBubble?.closest('article')?.remove();liveBubble=null;modelPart='';playback?.flush();}endTurn(t);if(show)say(TURN_COPY.stopped);}
 async function send(text,{retry=false}={}){if(busy||!text.trim())return;if(voiceWanted)stopVoice();if(navigator.onLine===false&&outbox.size>=5){say('You are offline. Try again when you are back online.');return;}const previous=current.messages.slice(-16);if(!retry||current.messages.at(-1)?.text!==text)bubble('user',text);input.value='';const files=attachments;attachments=[];renderFiles();const item={text,previous,files,chat:current.id};if(navigator.onLine===false){say(outbox.push(item)?'You are offline. Your message will send when you are back online.':'You are offline. Try again when you are back online.');return;}await deliver(item);}
 async function deliver(item){if(item.chat!==current?.id)return;for(let i=0;busy&&i<400;i++)await new Promise(r=>setTimeout(r,250));if(busy)return;say('');
  // Live first; attachments and Text mode use the text endpoint.
  if(!item.files.length){const {conn,cancelled}=await liveFor();if(cancelled)return;if(conn&&deliverLive(conn,item))return;}
  await deliverText(item);
 }
 async function deliverText({text,previous,files}){const t=startTurn('text',text);try{const r=await api('chat',{message:text,messages:previous,context:context(),attachments:files},t.controller.signal);if(t.cancelled)return;bubble('assistant',r.message,true,r.tools_used||[]);for(const item of r.effects||[])await effect(item);}catch(e){if(t.cancelled)return;failure(e.message,text);}finally{endTurn(t);}}
 function deliverLive(conn,{text}){if(droppingLive&&!conn.turnOpen)droppingLive=false;const t=startTurn('live',text);speakTurn=false;modelPart='';liveBubble=null;if(!conn.sendText(text)){endTurn(t);return false;}return true;}
 // Waits briefly for Live to be ready (it opens when you start typing); otherwise the text endpoint answers.
 async function liveFor(){const conn=ensureLive();if(!conn)return {conn:null};if(conn.state==='ready')return {conn};if(!['idle','connecting','reconnecting'].includes(conn.state))return {conn:null};
  const t=startTurn('live','');t.phase='waiting';showPending();
  const ok=await new Promise(resolve=>{let done=false;const finish=v=>{if(done)return;done=true;clearTimeout(timer);stopReady();stopState();resolve(v);};const timer=setTimeout(()=>finish(false),LIVE_WAIT_MS);const stopReady=conn.on('ready',()=>finish(true));const stopState=conn.on('state',({state:s})=>{showPending();if(['fallback','offline','unavailable','closed'].includes(s))finish(false);});});
  const cancelled=t.cancelled;endTurn(t);if(cancelled)return {conn:null,cancelled:true};return {conn:ok&&live===conn&&conn.state==='ready'?conn:null};
 }
 const outbox=createOutbox({deliver});
 $('sp-assistant-form').onsubmit=e=>{e.preventDefault();if(busy){cancelTurn();return;}send(input.value);};input.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();if(!busy)$('sp-assistant-form').requestSubmit();}};
 // Start opening Live as soon as the user starts typing, so it is ready by the time they press Send.
 input.addEventListener('input',()=>{if(input.value.trim())ensureLive();},{passive:true});
 function renderFiles(){$('sp-assistant-files').replaceChildren();for(const [i,file]of attachments.entries()){const b=document.createElement('button');b.type='button';b.textContent=file.name+' ×';b.onclick=()=>{attachments.splice(i,1);renderFiles();};$('sp-assistant-files').append(b);}}
 $('sp-assistant-file').onchange=async e=>{for(const file of e.target.files){if(attachments.length>=2){say('Attach up to two files per message.');break;}if(file.size+attachments.reduce((sum,a)=>sum+Math.floor(a.data.length*3/4),0)>4194304){say('Attachments must total less than 4 MB.');continue;}const data=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=reject;reader.readAsDataURL(file);});attachments.push({name:file.name,mime_type:file.type||'text/plain',data});}e.target.value='';renderFiles();};
 // Live. The connection state machine (assistant/live.js) owns reconnects, goAway handover, Text mode with its
 // background probe, the per-turn watchdog and offline handling; this part maps its events onto the panel.
 // Quota rule (verified on DIELCO 2026-09-26): every open session counts, so a session opens only while the panel
 // is in use and is released a minute after it closes.
 function ensureLive(){
  if(live)return live;
  if(providerReady===false||navigator.onLine===false||Date.now()<liveBlockedUntil||(!embeddedConfig&&panel.hidden))return null;
  clearTimeout(releaseTimer);releaseTimer=null;
  const conn=createLiveConnection({mintSession:()=>api('session',{context:context()}),seedTurns:()=>historyToSeed(current?.messages),canSwitchBack:()=>!busy&&(embeddedConfig||!panel.hidden),audioMimeType:AUDIO_INPUT_MIME_TYPE});
  live=conn;liveState='connecting';wireLive(conn);conn.connect();return conn;
 }
 function releaseLive(){clearTimeout(releaseTimer);releaseTimer=null;stopVoice();const conn=live;live=null;liveState='idle';conn?.disconnect();toolChain=Promise.resolve();state(statusLine());}
 function scheduleRelease(){clearTimeout(releaseTimer);releaseTimer=setTimeout(()=>{releaseTimer=null;if(busy){scheduleRelease();return;}releaseLive();},RELEASE_MS);}
 function renderVoice(){const b=panel.querySelector('[data-sp=voice]');b.textContent=voiceWanted?'Stop voice':'Voice';b.setAttribute('aria-pressed',String(voiceWanted));state(statusLine());}
 function finishLiveTurn(ok,error){
  const heard=userPart.trim(),said=modelPart.trim(),t=turn&&turn.path==='live'?turn:null,steps=t?t.steps:[];
  if(heard){current.messages.push({role:'user',text:userPart});if(current.title==='New conversation')current.title=userPart.slice(0,60);}
  if(said&&ok&&!t?.cancelled){const old=liveBubble?.closest('article');pendingEl?.remove();pendingEl=null;const content=bubble('assistant',said,false,steps);if(old){old.replaceWith(content.closest('article'));}const entry={role:'assistant',text:said};if(steps.length)entry.steps=steps.slice(0,20);current.messages.push(entry);}
  if(heard||said){current.messages=current.messages.slice(-80);save();}
  userPart='';modelPart='';liveBubble=null;voiceUserBubble=null;
  if(t){const cancelled=t.cancelled;endTurn(t);if(!ok&&!cancelled&&!said)failure(error||LIVE_COPY.tooLong,t.text);}
 }
 // A completed Live turn is finished a moment later: transcription can trail turnComplete, and a turn that
 // called a tool continues after the tool answers. A tool still running keeps the turn open.
 function settle(){clearTimeout(settleTimer);settleTimer=setTimeout(()=>{settleTimer=null;if(turn?.path==='live'&&turn.running>0){settle();return;}finishLiveTurn(true);},SETTLE_MS);}
 function stopVoice(){if(!voiceWanted&&!mic&&!playback)return;voiceWanted=false;mic?.stop();mic=null;playback?.close();playback=null;speakTurn=false;renderVoice();}
 async function micPermission(){try{return (await navigator.permissions.query({name:'microphone'})).state;}catch{return 'unknown';}}
 // Mic problems are said once per tap on Voice; text chat keeps working.
 function micFailed(error){if(!voiceWanted)return;mic=null;micBlocked=true;stopVoice();if(!micNoticeShown){micNoticeShown=true;say(error?.message||MIC_DENIED);}}
 async function ensureMic(conn){if(mic)return true;if(micBlocked||live!==conn||!voiceWanted)return false;const capture=createMicCapture({onChunk:data=>conn.sendAudio(data),onError:micFailed});mic=capture;await capture.start();if(mic!==capture||live!==conn){capture.stop();return false;}if(!capture.active){mic=null;return false;}return true;}
 async function runLiveTool(conn,{key,name,args,signal}){
  const t=turn&&turn.path==='live'?turn:null;
  if(t&&!t.cancelled){t.steps.push(name);t.running+=1;t.phase='tool';t.tool=name;showPending();}
  if(signal?.aborted)return;let result;
  try{if(t?.cancelled){result={status:'cancelled',message:'The user stopped this request.'};}else{result=await api('tool',{name,args,context:context()},signal);if(signal?.aborted)return;await effect(result);}}catch(e){if(signal?.aborted)return;result={error:e.message};}
  if(t)t.running=Math.max(0,t.running-1);
  if(t&&turn===t&&!t.cancelled){t.phase=modelPart?'replying':'waiting';t.tool=null;showPending();}
  conn.respondTool(key,result);
 }
 function wireLive(conn){
  const mine=fn=>payload=>{if(live===conn)fn(payload);};
  conn.on('state',mine(({state:next})=>{liveState=next;if(next!=='ready'){playback?.flush();}if(next==='fallback'||next==='offline'||next==='unavailable'){mic?.stop();mic=null;if(voiceWanted&&next!=='offline')stopVoice();}if(next==='unavailable'){liveBlockedUntil=Date.now()+LIVE_RETRY_MS;queueMicrotask(()=>{if(live===conn)releaseLive();});}if(turn?.path==='live')showPending();renderVoice();}));
  conn.on('notice',mine(({message,quiet})=>{if(!quiet&&(voiceWanted||turn))say(message);}));
  conn.on('ready',mine(async({recovered,replaced})=>{if(replaced||!voiceWanted)return;if(!await ensureMic(conn)||live!==conn||conn.state!=='ready')return;say(recovered?LIVE_COPY.back:LIVE_COPY.listening);}));
  // Replies are spoken only after the user spoke; a typed question gets a text reply.
  conn.on('audio',mine(({data})=>{if(droppingLive||!speakTurn||!voiceWanted)return;try{playback?.enqueue(data);}catch{}}));
  conn.on('interrupted',mine(()=>{playback?.flush();if(droppingLive){droppingLive=false;liveBubble?.closest('article')?.remove();liveBubble=null;modelPart='';}}));
  conn.on('inputTranscript',mine(({text})=>{speakTurn=true;if(!turn)startTurn('live','');userPart+=text;if(!voiceUserBubble)voiceUserBubble=bubble('user','',false);voiceUserBubble.textContent=userPart;}));
  conn.on('outputTranscript',mine(({text})=>{if(droppingLive||turn?.cancelled)return;if(turn&&turn.phase!=='replying'){turn.phase='replying';showPending();}modelPart+=text;if(!liveBubble)liveBubble=bubble('assistant','',false);liveBubble.textContent=modelPart;log.scrollTop=log.scrollHeight;if(settleTimer)settle();}));
  conn.on('turnComplete',mine(({ok,error,timeout})=>{if(droppingLive&&!timeout){droppingLive=false;liveBubble?.closest('article')?.remove();liveBubble=null;modelPart='';userPart='';return;}droppingLive=false;if(!ok){clearTimeout(settleTimer);settleTimer=null;finishLiveTurn(false,error);return;}settle();}));
  conn.on('toolCall',mine(call=>{toolChain=toolChain.then(()=>runLiveTool(conn,call)).catch(()=>{});}));
 }
 async function startVoice(){
  if(voiceWanted){stopVoice();say('');return;}if(busy)return;
  if(navigator.onLine===false){say(LIVE_COPY.offline);return;}
  if(providerReady===false){say('The AI provider is not connected yet. Page guidance still works.');return;}
  micNoticeShown=false;
  if(micBlocked){if(await micPermission()==='denied'){say(MIC_DENIED);return;}micBlocked=false;}
  voiceWanted=true;renderVoice();say(LIVE_COPY.connecting);
  const queue=createPlaybackQueue();playback=queue;
  try{await queue.resume();}catch(e){if(playback===queue){stopVoice();say(friendlyError(e,'Voice is not supported in this browser. You can still type.'));}return;}
  // Stop voice may have been tapped while audio was starting.
  if(!voiceWanted||playback!==queue)return;
  liveBlockedUntil=0;const conn=ensureLive();
  if(!conn){stopVoice();say('Voice is not available right now. You can still type.');return;}
  // The mic is asked for before anything else waits on the connection, so a denied mic is said at once.
  if(!await ensureMic(conn)||live!==conn)return;
  if(conn.state==='ready')say(LIVE_COPY.listening);
 }
 function open(value){if(embeddedConfig&&!value){releaseLive();hostEvent({type:'close'});return;}panel.hidden=!value;$('sp-assistant-launch').setAttribute('aria-expanded',String(value));if(value){clearTimeout(releaseTimer);releaseTimer=null;input.focus();}else{stopVoice();closeGuide();scheduleRelease();$('sp-assistant-launch').focus();}}
 panel.addEventListener('click',e=>{const action=e.target.closest('[data-sp]')?.dataset.sp;if(action==='close')open(false);if(action==='new'&&!busy)newChat();if(action==='history'&&!busy)history();if(action==='tour')guide();if(action==='voice')startVoice();});$('sp-assistant-launch').onclick=()=>open(panel.hidden);document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeGuide();if(!panel.hidden)open(false);}});window.addEventListener('pagehide',()=>{releaseLive();save();});
 current=chats[0];if(!current)newChat();else{current.messages.forEach(m=>bubble(m.role,m.text,false,m.steps));welcome();}api('status').then(s=>{providerReady=!!s.configured;if(!s.configured)say('The AI provider is not connected yet. Page guidance still works.');}).catch(e=>say(e.message));
 window.addEventListener('offline',()=>{if(!voiceWanted)say(LIVE_COPY.offline);state(statusLine());});window.addEventListener('online',()=>{if(!voiceWanted&&notice.textContent===LIVE_COPY.offline)say('');state(statusLine());});
 consoleGuide?.resume();
}
