import {renderReply} from './assistant/reply.js?v=20261109-parity';
import {pageGuide} from './assistant/guides.js?v=20261109-parity';
import {downloadReportPdf} from './assistant/report.js?v=20261109-parity';
import {createMicCapture, createPlaybackQueue, AUDIO_INPUT_MIME_TYPE} from './assistant/audio.js?v=20261109-parity';
import {createLiveConnection, createOutbox, friendlyError, historyToSeed, LIVE_COPY} from './assistant/live.js?v=20261109-parity';

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
 const $=id=>document.getElementById(id), log=$('sp-assistant-messages'), notice=$('sp-assistant-notice'), input=$('sp-assistant-input');
 const storageKey='seapass.assistant.v1.'+panel.dataset.user;
 let chats=[],current=null,busy=false,attachments=[],live=null,liveState='idle',voiceWanted=false,mic=null,micBlocked=false,micNoticeShown=false,playback=null,providerReady=null,toolChain=Promise.resolve(),userPart='',modelPart='',liveBubble=null,voiceUserBubble=null;
 try{chats=JSON.parse(localStorage.getItem(storageKey)||'[]').filter(c=>Array.isArray(c.messages)).slice(0,20);}catch{}
 const save=()=>{try{localStorage.setItem(storageKey,JSON.stringify(chats.slice(0,20)));}catch{notice.textContent='Chat history could not be saved on this device.';}};
 const say=(text)=>{notice.textContent=text||'';};
 const state=text=>$('sp-assistant-state').textContent=text;
 const IDLE_LINE='Trips, tickets and a little guidance',MIC_DENIED='Microphone access was denied. You can still type to the assistant.';
 const VOICE_LINES={idle:'Connecting live voice…',connecting:'Connecting live voice…',ready:'Live voice connected',reconnecting:'Reconnecting…',fallback:'Text mode',offline:'Offline'};
 const statusLine=()=>busy?'Thinking…':voiceWanted?(VOICE_LINES[liveState]||IDLE_LINE):navigator.onLine===false?'Offline':IDLE_LINE;
 state(IDLE_LINE);
 async function api(path,body,signal){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),90000),relay=()=>controller.abort();signal?.addEventListener('abort',relay);
  try{
   const r=await fetch(panel.dataset.endpoint+'/'+path,{method:body?'POST':'GET',credentials:'same-origin',signal:controller.signal,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':panel.dataset.csrf,...(embeddedConfig?{'Authorization':'Bearer '+embeddedConfig.token}:{})},...(body?{body:JSON.stringify(body)}:{})});
   const data=await r.json().catch(()=>({}));if(!r.ok){const error=new Error(friendlyError({status:r.status,message:data.message}));error.status=r.status;throw error;}return data;
  }catch(e){if(e.name==='AbortError'){if(signal?.aborted)throw e;throw new Error(LIVE_COPY.tooLong);}if(e.status)throw e;throw new Error(friendlyError(e));}finally{clearTimeout(timer);signal?.removeEventListener('abort',relay);}
 }
 function newChat(){stopVoice();outbox.clear();current={id:globalThis.crypto?.randomUUID?.()||String(Date.now()),title:'New conversation',messages:[]};chats.unshift(current);chats=chats.slice(0,20);log.replaceChildren();$('sp-assistant-history').hidden=true;save();welcome();}
 function welcome(){if(!current.messages.length){const el=document.createElement('p');el.className='sp-welcome';el.textContent='Ask about trips, fares or bookings, or let me show you this page.';log.append(el);}}
 function bubble(role,text,record=true){
  log.querySelector('.sp-welcome')?.remove(); const el=document.createElement('article');el.className='sp-message';el.dataset.role=role;
  const label=document.createElement('small');label.textContent=role==='user'?'You':'SeaPass';el.append(label);
  const content=document.createElement('div');content.className='sp-message-text';content.textContent=text;if(role==='assistant')renderReply(content,text);
  if(role==='assistant' && text.length>1400){const brief=document.createElement('p');brief.textContent=text.split('\n').filter(Boolean).slice(0,3).join('\n');el.append(brief);const detail=document.createElement('details'),summary=document.createElement('summary');summary.textContent='Read full report';detail.append(summary,content);el.append(detail);}else el.append(content);
  if(role==='assistant'&&text){const actions=document.createElement('div');actions.className='sp-message-tools';const copy=document.createElement('button');copy.type='button';copy.textContent='Copy';copy.onclick=()=>navigator.clipboard.writeText(text).then(()=>say('Copied.')).catch(()=>say('Copy is unavailable in this browser.'));actions.append(copy);
   if(text.length>700){const pdf=document.createElement('button');pdf.type='button';pdf.textContent='Download PDF';pdf.onclick=()=>downloadReportPdf(text).catch(e=>say(e.message));actions.append(pdf);}el.append(actions);}
  log.append(el);log.scrollTop=log.scrollHeight;
  if(record){current.messages.push({role,text});current.messages=current.messages.slice(-80);if(current.title==='New conversation'&&role==='user')current.title=text.slice(0,60);save();}return content;
 }
 function history(){const box=$('sp-assistant-history');box.replaceChildren();box.hidden=!box.hidden;for(const chat of chats){const row=document.createElement('div'),open=document.createElement('button'),del=document.createElement('button');open.textContent=chat.title;open.onclick=()=>{stopVoice();outbox.clear();current=chat;log.replaceChildren();chat.messages.forEach(m=>bubble(m.role,m.text,false));box.hidden=true;};del.textContent='Delete';del.onclick=()=>{chats=chats.filter(c=>c.id!==chat.id);save();if(current?.id===chat.id)newChat();box.hidden=true;};row.append(open,del);box.append(row);}}
 function targets(){
  const candidates=[...document.querySelectorAll('main h1,main h2,main button,main input,main select,main a[href]'),...document.querySelectorAll('nav a[href],aside a[href]')].filter(el=>!panel.contains(el)&&el.getClientRects().length);
  const seen=new Set();return candidates.filter(el=>{const label=(el.getAttribute('aria-label')||el.textContent||el.getAttribute('placeholder')||'').trim();if(!label||label.length>170||seen.has(label))return false;seen.add(label);return true;}).slice(0,24).map((el,i)=>{if(!el.dataset.spGuide)el.dataset.spGuide='target-'+i;const label=(el.getAttribute('aria-label')||el.textContent||el.getAttribute('placeholder')||'').trim().slice(0,150);let message='This control opens '+label.toLowerCase()+'.';if(el.matches('h1'))message='You are on '+label+'. '+(el.nextElementSibling?.matches('p')?el.nextElementSibling.textContent.trim():'This walkthrough stays on the current page.');if(el.matches('h2'))message='This section contains '+label.toLowerCase()+'. Review its details before making changes.';if(el.matches('input,select'))message='Use '+label.toLowerCase()+' to set this value. Changes are applied only after you use the page save control.';if(el.matches('button'))message='Use '+label.toLowerCase()+' to apply or open this action. Review the values first.';return {id:el.dataset.spGuide,label,message,el};});
 }
 function context(){if(embeddedConfig)return embeddedConfig.context||{};const main=document.querySelector('main')||document.body;return{path:location.pathname,label:document.querySelector('main h1,h1')?.textContent?.trim()||document.title,text:(main.innerText||'').slice(0,6000),targets:targets().map(({id,label})=>({id,label}))};}
 let guideItems=[],guideIndex=0;
 function closeGuide(){document.querySelectorAll('.sp-guide-target').forEach(e=>e.classList.remove('sp-guide-target'));$('sp-guide').hidden=true;panel.classList.remove('sp-guiding');}
 function showStep(){closeGuide();const item=guideItems[guideIndex];if(!item)return;item.el.classList.add('sp-guide-target');item.el.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'center'});$('sp-guide-title').textContent=item.label;$('sp-guide').querySelector('p').textContent=item.message||`Step ${guideIndex+1} of ${guideItems.length}. This is ${item.label.toLowerCase()}.`;$('sp-guide').hidden=false;panel.classList.add('sp-guiding');$('sp-guide').querySelector('[data-guide=next]').hidden=guideIndex>=guideItems.length-1;}
 function guide(target,message){if(embeddedConfig){hostEvent({type:'guide',target,message});return true;}const available=targets();guideItems=target?available.filter(t=>t.id===target):pageGuide(location.pathname).flatMap(([selector,label,explanation])=>{const el=document.querySelector(selector);return el&&el.getClientRects().length?[{el,label,message:explanation}]:[];});guideIndex=0;if(!guideItems.length){say('No guide targets are visible on this page.');return false;}guideItems=guideItems.map(t=>({...t,message:message||t.message}));showStep();return true;}
 $('sp-guide').onclick=e=>{if(e.target.dataset.guide==='close')closeGuide();if(e.target.dataset.guide==='next'){guideIndex++;showStep();}};
 async function effect(result){
  if(result.confirmation){const c=result.confirmation,card=document.createElement('div');card.className='sp-confirm';const p=document.createElement('p');p.textContent='Confirm '+c.tool.replaceAll('_',' ')+'?\n'+Object.entries(c.args).map(([k,v])=>k.replaceAll('_',' ')+': '+v).join('\n');card.append(p);for(const [approved,label]of[[true,'Confirm change'],[false,'Cancel']]){const b=document.createElement('button');b.type='button';b.textContent=label;b.onclick=async()=>{card.querySelectorAll('button').forEach(x=>x.disabled=true);try{const r=await api('tool',{confirmation_token:c.token,approved});p.textContent=r.status==='confirmed'?'Change saved.':'Change cancelled.';current.messages.push({role:'assistant',text:p.textContent});save();}catch(e){p.textContent=e.message;}};card.append(b);}log.append(card);log.scrollTop=log.scrollHeight;}
  const a=result.client_action;if(!a)return;
  if(a.type==='navigate'&&embeddedConfig){hostEvent({type:'navigate',page:a.page,path:a.path,args:a.args||{}});return;}
  if((a.type==='highlight_element'||a.type==='start_tour')&&embeddedConfig){hostEvent({type:'guide',action:a});return;}
  if(a.type==='navigate'){const link=document.createElement('button');link.type='button';link.textContent='Open '+a.page;link.onclick=()=>{if(a.path.startsWith('/admin/')){save();location.assign(a.path);}};log.append(link);}
  if(a.type==='highlight_element')guide(a.args.target,a.args.message);
  if(a.type==='start_tour')guide();
 }
 function setBusy(value){busy=value;panel.querySelectorAll('nav button,#sp-assistant-history button').forEach(b=>b.disabled=value);if(value)$('sp-assistant-history').hidden=true;$('sp-assistant-send').disabled=value;state(statusLine());}
 // Typing ends live voice, except in Text mode, where the background probe keeps
 // waiting for a turn boundary to bring voice back. Offline, the message waits.
 async function send(text){if(busy||!text.trim())return;if(voiceWanted&&liveState!=='fallback')stopVoice();const previous=current.messages.slice(-16);bubble('user',text);input.value='';const files=attachments;attachments=[];renderFiles();const item={text,previous,files,chat:current.id};if(navigator.onLine===false){say(outbox.push(item)?'You are offline. Your message will send when you are back online.':'You are offline. Try again when you are back online.');return;}await deliver(item);}
 async function deliver({text,previous,files,chat}){if(chat!==current?.id)return;for(let i=0;busy&&i<400;i++)await new Promise(r=>setTimeout(r,250));if(busy)return;setBusy(true);say('');try{const r=await api('chat',{message:text,messages:previous,context:context(),attachments:files});bubble('assistant',r.message);for(const item of r.effects||[])await effect(item);}catch(e){say(e.message);const retry=document.createElement('button');retry.type='button';retry.textContent='Retry';retry.onclick=()=>{retry.remove();send(text);};log.append(retry);}finally{setBusy(false);}}
 const outbox=createOutbox({deliver});
 $('sp-assistant-form').onsubmit=e=>{e.preventDefault();send(input.value);};input.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();$('sp-assistant-form').requestSubmit();}};
 function renderFiles(){$('sp-assistant-files').replaceChildren();for(const [i,file]of attachments.entries()){const b=document.createElement('button');b.type='button';b.textContent=file.name+' ×';b.onclick=()=>{attachments.splice(i,1);renderFiles();};$('sp-assistant-files').append(b);}}
 $('sp-assistant-file').onchange=async e=>{for(const file of e.target.files){if(attachments.length>=2){say('Attach up to two files per message.');break;}if(file.size+attachments.reduce((sum,a)=>sum+Math.floor(a.data.length*3/4),0)>4194304){say('Attachments must total less than 4 MB.');continue;}const data=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=reject;reader.readAsDataURL(file);});attachments.push({name:file.name,mime_type:file.type||'text/plain',data});}e.target.value='';renderFiles();};
 // Live voice. The connection state machine (assistant/live.js) owns reconnects,
 // goAway handover, Text mode with its background probe, the per-turn watchdog
 // and offline handling; this part only maps its events onto the panel.
 function renderVoice(){const b=panel.querySelector('[data-sp=voice]');b.textContent=voiceWanted?'Stop voice':'Voice';b.setAttribute('aria-pressed',String(voiceWanted));state(statusLine());}
 function finishLiveTurn(ok){const heard=userPart.trim(),said=ok&&modelPart.trim();if(heard){current.messages.push({role:'user',text:userPart});if(current.title==='New conversation')current.title=userPart.slice(0,60);}if(said)current.messages.push({role:'assistant',text:modelPart});if(heard||said){current.messages=current.messages.slice(-80);save();}userPart='';modelPart='';liveBubble=null;voiceUserBubble=null;}
 function stopVoice(){voiceWanted=false;liveState='idle';const conn=live;live=null;conn?.disconnect();mic?.stop();mic=null;playback?.close();playback=null;liveBubble=null;voiceUserBubble=null;userPart='';modelPart='';renderVoice();}
 async function micPermission(){try{return (await navigator.permissions.query({name:'microphone'})).state;}catch{return 'unknown';}}
 // Mic problems are said once per tap on Voice; text chat keeps working.
 function micFailed(error){mic=null;micBlocked=true;stopVoice();if(!micNoticeShown){micNoticeShown=true;say(error?.message||MIC_DENIED);}}
 async function ensureMic(conn){if(mic)return true;if(micBlocked||live!==conn)return false;const capture=createMicCapture({onChunk:data=>conn.sendAudio(data),onError:micFailed});mic=capture;await capture.start();if(mic!==capture||live!==conn){capture.stop();return false;}if(!capture.active){mic=null;return false;}return true;}
 async function runLiveTool(conn,{key,name,args,signal}){if(signal?.aborted)return;let result;try{result=await api('tool',{name,args,context:context()},signal);if(signal?.aborted)return;await effect(result);}catch(e){if(signal?.aborted)return;result={error:e.message};}conn.respondTool(key,result);}
 function wireLive(conn){
  const mine=fn=>payload=>{if(live===conn)fn(payload);};
  conn.on('state',mine(({state:next})=>{liveState=next;if(next!=='ready'){playback?.flush();liveBubble=null;}if(next==='fallback'||next==='offline'||next==='unavailable'){mic?.stop();mic=null;}if(next==='unavailable')queueMicrotask(()=>{if(live===conn)stopVoice();});renderVoice();}));
  conn.on('notice',mine(({message})=>say(message)));
  conn.on('ready',mine(async({recovered,replaced})=>{if(replaced){say('');return;}if(!await ensureMic(conn)||live!==conn)return;say(recovered?LIVE_COPY.back:LIVE_COPY.listening);}));
  conn.on('audio',mine(({data})=>{try{playback?.enqueue(data);}catch{}}));
  conn.on('interrupted',mine(()=>{playback?.flush();liveBubble=null;}));
  conn.on('inputTranscript',mine(({text})=>{userPart+=text;if(!voiceUserBubble)voiceUserBubble=bubble('user','',false);voiceUserBubble.textContent=userPart;}));
  conn.on('outputTranscript',mine(({text})=>{modelPart+=text;if(!liveBubble)liveBubble=bubble('assistant','',false);liveBubble.textContent=modelPart;log.scrollTop=log.scrollHeight;}));
  conn.on('turnComplete',mine(({ok})=>finishLiveTurn(ok)));
  conn.on('toolCall',mine(call=>{toolChain=toolChain.then(()=>runLiveTool(conn,call)).catch(()=>{});}));
 }
 async function startVoice(){
  if(voiceWanted){stopVoice();say('');return;}if(busy)return;
  if(navigator.onLine===false){say(LIVE_COPY.offline);return;}
  if(providerReady===false){say('The AI provider is not connected yet. Page guidance still works.');return;}
  micNoticeShown=false;
  if(micBlocked){if(await micPermission()==='denied'){say(MIC_DENIED);return;}micBlocked=false;}
  voiceWanted=true;liveState='connecting';renderVoice();say(LIVE_COPY.connecting);
  try{playback=createPlaybackQueue();await playback.resume();}catch(e){stopVoice();say(friendlyError(e,'Voice is not supported in this browser. You can still type.'));return;}
  const conn=createLiveConnection({mintSession:()=>api('session',{context:context()}),seedTurns:()=>historyToSeed(current?.messages),canSwitchBack:()=>!busy&&voiceWanted&&!panel.hidden,audioMimeType:AUDIO_INPUT_MIME_TYPE});
  live=conn;wireLive(conn);
  // The mic is asked for first, so a denied mic never costs a session token.
  if(!await ensureMic(conn)||live!==conn)return;
  conn.connect();
 }
 function open(value){if(embeddedConfig&&!value){stopVoice();hostEvent({type:'close'});return;}panel.hidden=!value;$('sp-assistant-launch').setAttribute('aria-expanded',String(value));if(value)input.focus();else{stopVoice();closeGuide();$('sp-assistant-launch').focus();}}
 panel.addEventListener('click',e=>{const action=e.target.closest('[data-sp]')?.dataset.sp;if(action==='close')open(false);if(action==='new'&&!busy)newChat();if(action==='history'&&!busy)history();if(action==='tour')guide();if(action==='voice')startVoice();});$('sp-assistant-launch').onclick=()=>open(panel.hidden);document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeGuide();open(false);}});window.addEventListener('pagehide',()=>{stopVoice();save();});
 current=chats[0];if(!current)newChat();else{current.messages.forEach(m=>bubble(m.role,m.text,false));welcome();}api('status').then(s=>{providerReady=!!s.configured;if(!s.configured)say('The AI provider is not connected yet. Page guidance still works.');}).catch(e=>say(e.message));
 window.addEventListener('offline',()=>{if(!voiceWanted)say(LIVE_COPY.offline);state(statusLine());});window.addEventListener('online',()=>{if(!voiceWanted&&notice.textContent===LIVE_COPY.offline)say('');state(statusLine());});
}
