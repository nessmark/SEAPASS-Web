// Read-only real-provider probe; outputs statuses only, never tokens or audio.
import {writeFile} from 'node:fs/promises';
const base='http://127.0.0.1:8000';
async function api(path,data,token){
 const response=await fetch(base+path,{method:data?'POST':'GET',headers:{Accept:'application/json',...(data?{'Content-Type':'application/json'}:{}),...(token?{Authorization:'Bearer '+token}:{})},...(data?{body:JSON.stringify(data)}:{})});
 const body=await response.json();if(!response.ok)throw new Error('Endpoint '+path+' HTTP '+response.status);return body;
}
const login=await api('/api/login',{email:'marilou.bautista@example.com',password:'password'});
const token=(login.data||login).token||login.token;if(!token)throw new Error('No passenger token');
const answer=await api('/api/assistant/chat',{message:'Use the fare tool and state only the current regular fare from Surigao to San Jose. Do not change anything.',context:{path:'trips',label:'Trips'},attachments:[]},token);
const result={at:new Date().toISOString(),chat:{responded:!!answer.message,tools_used:answer.tools_used,contains_current_regular_fare:/100/.test(answer.message)}};
if(!result.chat.responded||!result.chat.contains_current_regular_fare)throw new Error('Provider chat fare answer did not match fixture');
const session=await api('/api/assistant/session',{context:{path:'trips',label:'Trips'}},token);
result.live={ephemeral_minted:!!session.token,setup:false,audio:false,closed:false};
await new Promise((resolve,reject)=>{
 const ws=new WebSocket(session.ws_url+'?access_token='+encodeURIComponent(session.token));
 const timer=setTimeout(()=>{ws.close();reject(new Error('Live audio timeout'));},45000);
 ws.onopen=()=>ws.send(JSON.stringify({setup:{model:'models/'+session.model,generationConfig:{responseModalities:['AUDIO']},systemInstruction:{parts:[{text:'Say ready briefly.'}]}}}));
 ws.onmessage=async event=>{
  try{
   const data=JSON.parse(typeof event.data==='string'?event.data:await event.data.text());
   if(data.error)throw new Error('Provider live response error');
   if(data.setupComplete){result.live.setup=true;ws.send(JSON.stringify({clientContent:{turns:[{role:'user',parts:[{text:'Say ready.'}]}],turnComplete:true}}));}
   if(data.serverContent?.modelTurn?.parts?.some(p=>p.inlineData?.data)){result.live.audio=true;clearTimeout(timer);ws.close(1000);}
  }catch(error){clearTimeout(timer);ws.close();reject(error);}
 };
 ws.onerror=()=>{clearTimeout(timer);reject(new Error('Live WebSocket error'));};
 ws.onclose=event=>{result.live.closed=event.code===1000;if(result.live.setup&&result.live.audio){clearTimeout(timer);resolve();}else{clearTimeout(timer);reject(new Error('Live closed before audio, code '+event.code));}};
});
await writeFile(new URL('./assistant-provider-result.json',import.meta.url),JSON.stringify(result,null,2)+'\n');
console.log(JSON.stringify(result));
