(function(){
  'use strict';
  const links = Array.from(document.querySelectorAll('.rar-side a[href^="#"]'));
  const sections = links.map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
  function highlight(id) { links.forEach(a => {
    const active = a.getAttribute('href') === '#' + id;
    a.classList.toggle('active', active);
    if (active) { a.setAttribute('aria-current','location'); } else { a.removeAttribute('aria-current'); }
  }); }
  if ('IntersectionObserver' in window && sections.length) {
    const observer = new IntersectionObserver(entries => {
      const candidates = entries.filter(entry => entry.isIntersecting).sort((a,b)=>a.boundingClientRect.top-b.boundingClientRect.top);
      if(candidates.length) highlight(candidates[0].target.id);
    }, {rootMargin:'-70px 0px -65% 0px',threshold:0});
    sections.forEach(section => observer.observe(section));
  }
  if(sections.length) highlight(sections[0].id);
  const copyButton = document.getElementById('rar-copy-prompt');
  if(copyButton) copyButton.addEventListener('click', async () => {
    const field = document.getElementById('rar-ai-prompt');
    const status = document.getElementById('rar-copy-status');
    try {
      if(navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(field.value);
      else {field.select();if(!document.execCommand('copy'))throw new Error('Clipboard unavailable');}
      status.textContent = 'Copied';
    } catch (err) { status.textContent = 'Please select and copy the text manually.'; }
  });
})();