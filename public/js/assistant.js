import {renderReply} from './assistant/reply.js?v=20261020-completion';
import {pageGuide} from './assistant/guides.js?v=20261020-completion';
import {downloadReportPdf} from './assistant/report.js?v=20261020-completion';
import {createMicCapture, createPlaybackQueue, AUDIO_INPUT_MIME_TYPE} from './assistant/audio.js?v=20261020-completion';

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
 let chats=[],current=null,busy=false,attachments=[],socket=null,mic=null,playback=null,voice=false,voiceTimer=null,userPart='',modelPart='',liveBubble=null,voiceUserBubble=null;
 try{chats=JSON.parse(localStorage.getItem(storageKey)||'[]').filter(c=>Array.isArray(c.messages)).slice(0,20);}catch{}
 const save=()=>{try{localStorage.setItem(storageKey,JSON.stringify(chats.slice(0,20)));}catch{notice.textContent='Chat history could not be saved on this device.';}};
 const say=(text)=>{notice.textContent=text||'';};
 const state=text=>$('sp-assistant-state').textContent=text;
 state('Trips, tickets and a little guidance');
 async function api(path,body){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),90000);
  try{
   const r=await fetch(panel.dataset.endpoint+'/'+path,{method:body?'POST':'GET',credentials:'same-origin',signal:controller.signal,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':panel.dataset.csrf,...(embeddedConfig?{'Authorization':'Bearer '+embeddedConfig.token}:{})},...(body?{body:JSON.stringify(body)}:{})});
   const data=await r.json().catch(()=>({}));if(!r.ok)throw new Error(data.message||'The assistant could not connect. Try again.');return data;
  }catch(e){if(e.name==='AbortError')throw new Error('The assistant took too long. Try again.');throw e;}finally{clearTimeout(timer);}
 }
 function newChat(){stopVoice();current={id:globalThis.crypto?.randomUUID?.()||String(Date.now()),title:'New conversation',messages:[]};chats.unshift(current);chats=chats.slice(0,20);log.replaceChildren();$('sp-assistant-history').hidden=true;save();welcome();}
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
 function history(){const box=$('sp-assistant-history');box.replaceChildren();box.hidden=!box.hidden;for(const chat of chats){const row=document.createElement('div'),open=document.createElement('button'),del=document.createElement('button');open.textContent=chat.title;open.onclick=()=>{stopVoice();current=chat;log.replaceChildren();chat.messages.forEach(m=>bubble(m.role,m.text,false));box.hidden=true;};del.textContent='Delete';del.onclick=()=>{chats=chats.filter(c=>c.id!==chat.id);save();if(current?.id===chat.id)newChat();box.hidden=true;};row.append(open,del);box.append(row);}}
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
 function setBusy(value){busy=value;panel.querySelectorAll('nav button,#sp-assistant-history button').forEach(b=>b.disabled=value);if(value)$('sp-assistant-history').hidden=true;$('sp-assistant-send').disabled=value;state(value?'Thinking…':voice?'Live voice connected':'Trips, tickets and a little guidance');}
 async function send(text){if(busy||!text.trim())return;if(voice)stopVoice();const previous=current.messages.slice(-16);bubble('user',text);input.value='';setBusy(true);say('');const files=attachments;attachments=[];renderFiles();try{const r=await api('chat',{message:text,messages:previous,context:context(),attachments:files});bubble('assistant',r.message);for(const item of r.effects||[])await effect(item);}catch(e){say(e.message);const retry=document.createElement('button');retry.type='button';retry.textContent='Retry';retry.onclick=()=>{retry.remove();send(text);};log.append(retry);}finally{setBusy(false);}}
 $('sp-assistant-form').onsubmit=e=>{e.preventDefault();send(input.value);};input.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();$('sp-assistant-form').requestSubmit();}};
 function renderFiles(){$('sp-assistant-files').replaceChildren();for(const [i,file]of attachments.entries()){const b=document.createElement('button');b.type='button';b.textContent=file.name+' ×';b.onclick=()=>{attachments.splice(i,1);renderFiles();};$('sp-assistant-files').append(b);}}
 $('sp-assistant-file').onchange=async e=>{for(const file of e.target.files){if(attachments.length>=2){say('Attach up to two files per message.');break;}if(file.size+attachments.reduce((sum,a)=>sum+Math.floor(a.data.length*3/4),0)>4194304){say('Attachments must total less than 4 MB.');continue;}const data=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=reject;reader.readAsDataURL(file);});attachments.push({name:file.name,mime_type:file.type||'text/plain',data});}e.target.value='';renderFiles();};
 function stopVoice(){voice=false;clearTimeout(voiceTimer);mic?.stop();mic=null;playback?.close();playback=null;const old=socket;socket=null;if(old){old.onclose=null;old.close();}panel.querySelector('[data-sp=voice]').textContent='Voice';panel.querySelector('[data-sp=voice]').setAttribute('aria-pressed','false');state('Trips, tickets and a little guidance');liveBubble=null;voiceUserBubble=null;userPart='';modelPart='';}
 async function startVoice(){if(voice){stopVoice();return;}if(busy)return;say('Connecting live voice…');try{const s=await api('session',{context:context()});playback=createPlaybackQueue();await playback.resume();const ws=new WebSocket(s.ws_url+'?access_token='+encodeURIComponent(s.token));socket=ws;voiceTimer=setTimeout(()=>{stopVoice();say('Live voice did not connect. You can still send a message.');},15000);ws.onopen=()=>ws.send(JSON.stringify({setup:{model:'models/'+s.model,generationConfig:{responseModalities:['AUDIO'],speechConfig:{voiceConfig:{prebuiltVoiceConfig:{voiceName:'Kore'}}}},systemInstruction:{parts:[{text:s.system_instruction}]},tools:[{functionDeclarations:s.tools}],inputAudioTranscription:{},outputAudioTranscription:{}}}));
  ws.onmessage=async event=>{let message;try{message=JSON.parse(typeof event.data==='string'?event.data:await event.data.text());}catch{return;}
   if(message.setupComplete){clearTimeout(voiceTimer);voice=true;state('Live voice connected');say('Listening. Tap Stop voice when finished.');panel.querySelector('[data-sp=voice]').textContent='Stop voice';panel.querySelector('[data-sp=voice]').setAttribute('aria-pressed','true');mic=createMicCapture({onChunk:data=>{if(ws.readyState===1)ws.send(JSON.stringify({realtimeInput:{audio:{data,mimeType:AUDIO_INPUT_MIME_TYPE}}}));},onError:e=>{stopVoice();say(e.message);}});await mic.start();}
   if(message.serverContent){const c=message.serverContent;if(c.interrupted){playback?.flush();liveBubble=null;}for(const part of c.modelTurn?.parts||[]){if(part.inlineData?.data)playback?.enqueue(part.inlineData.data);}if(c.inputTranscription?.text){userPart+=c.inputTranscription.text;if(!voiceUserBubble)voiceUserBubble=bubble('user','',false);voiceUserBubble.textContent=userPart;}if(c.outputTranscription?.text){modelPart+=c.outputTranscription.text;if(!liveBubble)liveBubble=bubble('assistant','',false);liveBubble.textContent=modelPart;log.scrollTop=log.scrollHeight;}if(c.turnComplete){if(userPart.trim()){current.messages.push({role:'user',text:userPart});if(current.title==='New conversation')current.title=userPart.slice(0,60);}if(modelPart.trim()){current.messages.push({role:'assistant',text:modelPart});save();}userPart='';modelPart='';liveBubble=null;voiceUserBubble=null;}}
   if(message.toolCall){for(const call of message.toolCall.functionCalls||[]){let result;try{result=await api('tool',{name:call.name,args:call.args||{},context:context()});await effect(result);}catch(e){result={error:e.message};}if(ws.readyState===1)ws.send(JSON.stringify({toolResponse:{functionResponses:[{id:call.id,name:call.name,response:result}]}}));}}
   if(message.goAway){stopVoice();say('Voice session ended. Tap Voice to reconnect.');}
  };
  ws.onerror=()=>{stopVoice();say('Live voice could not connect. You can still use text chat.');};ws.onclose=()=>{stopVoice();say('Voice disconnected. You can still use text chat.');};
 }catch(e){stopVoice();say(e.message);}}
 function open(value){if(embeddedConfig&&!value){stopVoice();hostEvent({type:'close'});return;}panel.hidden=!value;$('sp-assistant-launch').setAttribute('aria-expanded',String(value));if(value)input.focus();else{stopVoice();closeGuide();$('sp-assistant-launch').focus();}}
 panel.addEventListener('click',e=>{const action=e.target.closest('[data-sp]')?.dataset.sp;if(action==='close')open(false);if(action==='new'&&!busy)newChat();if(action==='history'&&!busy)history();if(action==='tour')guide();if(action==='voice')startVoice();});$('sp-assistant-launch').onclick=()=>open(panel.hidden);document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeGuide();open(false);}});window.addEventListener('pagehide',()=>{stopVoice();save();});
 current=chats[0];if(!current)newChat();else{current.messages.forEach(m=>bubble(m.role,m.text,false));welcome();}api('status').then(s=>{if(!s.configured)say('The AI provider is not connected yet. Page guidance still works.');}).catch(e=>say(e.message));
}
