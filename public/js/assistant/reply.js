// Small Markdown subset built from text nodes. Provider output is never HTML.
export function renderReply(element, text) {
  element.replaceChildren();
  const inline = (target, value) => {
    for (const part of value.split(/(\*\*[^*\n]+\*\*)/g)) {
      if (part.startsWith('**') && part.endsWith('**')) {
        const strong = document.createElement('strong');
        strong.textContent = part.slice(2, -2);
        target.append(strong);
      } else target.append(document.createTextNode(part));
    }
  };
  let paragraph = [], list = null;
  const flush = () => {
    if (!paragraph.length) return;
    const p = document.createElement('p');
    inline(p, paragraph.join('\n'));
    element.append(p);
    paragraph = [];
  };
  for (const line of String(text || '').split('\n')) {
    const bullet = line.match(/^\s*(?:[-*]|(\d+)\.)\s+(.+)$/);
    if (bullet) {
      flush();
      const tag = bullet[1] ? 'OL' : 'UL';
      if (!list || list.tagName !== tag) {
        list = document.createElement(tag);
        if (bullet[1]) list.start = Number(bullet[1]);
        element.append(list);
      }
      const item = document.createElement('li');
      inline(item, bullet[2]);
      list.append(item);
      continue;
    }
    list = null;
    if (!line.trim()) { flush(); continue; }
    const heading = line.match(/^#{1,3}\s+(.+)$/);
    if (heading) {
      flush();
      const p = document.createElement('p'), strong = document.createElement('strong');
      inline(strong, heading[1]);
      p.append(strong);
      element.append(p);
    } else paragraph.push(line);
  }
  flush();
}
