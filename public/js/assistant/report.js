// Plain text only: model output is never interpreted as HTML or PDF commands.
export const LONG_REPLY_LENGTH = 900;

export function reportPreview(text) {
  const paragraphs = String(text || '').trim().split(/\n\s*\n/);
  if (/^#{0,3}\s*strategic report\s*$/i.test(paragraphs[0]?.trim())) paragraphs.shift();
  const brief = paragraphs[0] || String(text || '').trim();
  return brief.length > 360 ? `${brief.slice(0, 357).trimEnd()}...` : brief;
}

export async function createReportPdf(text, { fontBase64, generatedAt = new Date() } = {}) {
  const content = String(text || '').trim();
  if (!content) throw new Error('There is no report to download yet.');
  if (content.length > 100000) throw new Error('This report is too large. Ask for a shorter version.');
  if (!fontBase64) throw new Error('The report font could not be loaded. Try downloading again.');
  const { jsPDF } = await loadPdfLibrary();
  const doc = new jsPDF({ unit: 'mm', format: 'a4', compress: true });
  doc.addFileToVFS('report-sans.ttf', fontBase64);
  doc.addFont('report-sans.ttf', 'ReportSans', 'normal');
  doc.setFont('ReportSans');
  doc.setProperties({ title: 'SeaPass Assistant report', author: 'SeaPass Assistant' });
  const left = 20, width = 170, bottom = 275;
  let y = 22;
  function line(value, size = 10.5, leading = 5.5) {
    doc.setFontSize(size);
    for (const row of doc.splitTextToSize(value, width)) {
      if (y + leading > bottom) { doc.addPage(); y = 24; }
      doc.text(row, left, y);
      y += leading;
    }
  }
  doc.setTextColor(25);
  line('SeaPass', 18, 9);
  doc.setTextColor(90);
  line(`Assistant report, exported ${generatedAt.toISOString().slice(0, 16).replace('T', ' ')} UTC`, 9, 5);
  y += 7;
  doc.setTextColor(30);
  for (const raw of content.split('\n')) {
    if (!raw.trim()) { y += 3; continue; }
    const heading = /^#{1,3}\s/.test(raw) || /^(Strategic report|Executive brief|Current position|Priority risks|Recommended actions|Next 24 hours|Measures and limitations):?$/i.test(raw.trim());
    // Remove basic Markdown decoration, retaining all words and plain-text markup.
    const value = raw.replace(/^#{1,3}\s+/, '').replace(/\*\*(.*?)\*\*/g, '$1').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '');
    if (heading) { if (y + 20 > bottom) { doc.addPage(); y = 24; } y += 3; }
    line(value, heading ? 13 : 10.5, heading ? 7 : 5.5);
  }
  const pages = doc.getNumberOfPages();
  for (let page = 1; page <= pages; page++) {
    doc.setPage(page);
    doc.setFontSize(8);
    doc.setTextColor(100);
    doc.text(`SeaPass Assistant | ${page} / ${pages}`, left, 286);
  }
  return doc;
}

let fontPromise;
export async function downloadReportPdf(text) {
  if (!fontPromise) {
    fontPromise = fetch('/fonts/report-sans.ttf').then(async response => {
      if (!response.ok) throw new Error('The report font could not be loaded. Try again when connected.');
      const bytes = new Uint8Array(await response.arrayBuffer());
      let binary = '';
      for (let offset = 0; offset < bytes.length; offset += 8192) binary += String.fromCharCode(...bytes.subarray(offset, offset + 8192));
      return btoa(binary);
    }).catch(error => { fontPromise = null; throw error; });
  }
  const generatedAt = new Date();
  const doc = await createReportPdf(text, { fontBase64: await fontPromise, generatedAt });
  const blob = doc.output('blob');
  if(globalThis.SeaPassHost?.postMessage){
    const bytes=new Uint8Array(await blob.arrayBuffer());let binary='';
    for(let offset=0;offset<bytes.length;offset+=8192)binary+=String.fromCharCode(...bytes.subarray(offset,offset+8192));
    globalThis.SeaPassHost.postMessage(JSON.stringify({source:'seapass-assistant',type:'save_pdf',name:`SeaPass-report-${generatedAt.toISOString().slice(0,10)}.pdf`,data:btoa(binary)}));return;
  }
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `SeaPass-report-${generatedAt.toISOString().slice(0, 10)}.pdf`;
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}

let pdfPromise;
function loadPdfLibrary() {
 if(globalThis.jspdf?.jsPDF)return Promise.resolve(globalThis.jspdf);
 if(!pdfPromise)pdfPromise=new Promise((resolve,reject)=>{
  const script=document.createElement('script');script.src='/js/assistant/vendor/jspdf.umd.min.js';
  script.onload=()=>globalThis.jspdf?.jsPDF?resolve(globalThis.jspdf):reject(new Error('PDF export could not load.'));
  script.onerror=()=>{pdfPromise=null;reject(new Error('PDF export could not load. Try again when connected.'));};
  document.head.append(script);
 });return pdfPromise;
}
