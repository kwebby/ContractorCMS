/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

document.querySelectorAll('[data-lead-form]').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const result = form.querySelector('[role="status"]');
    button.disabled = true;
    result.textContent = 'Sending your request…';
    const values = Object.fromEntries(new FormData(form));
    values.marketing_consent = Boolean(values.marketing_consent);
    try {
      const response = await fetch(form.action, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
        body: JSON.stringify(values),
      });
      const payload = await response.json();
      if (!response.ok) {
        throw new Error(Object.values(payload.errors || {}).flat().join(' ') || payload.message || 'Your request could not be sent. Please try again.');
      }
      form.reset();
      result.textContent = 'Thanks — your request has reached our team. We’ll be in touch to discuss the next step.';
    } catch (error) {
      result.textContent = error.message || 'Connection unavailable. Please try again.';
    } finally {
      button.disabled = false;
    }
  });
});

document.querySelectorAll('[data-tool-form]').forEach((form) => {
  const resultSection = document.querySelector('[data-tool-result]');
  const reportForm = document.querySelector('[data-report-form]');
  let runId = null;
  let requestId = null;
  let lastDetails = null;
  async function post(path, values) {
    const response = await fetch(path, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(values)});
    const payload = await response.json();
    if(!response.ok) throw new Error(Object.values(payload.errors || {}).flat().join(' ') || payload.message || 'Please try again later.');
    return payload;
  }
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const details = new FormData(form).get('details');
    if (details !== lastDetails || !requestId) requestId = crypto.randomUUID();
    lastDetails = details;
    const button = form.querySelector('button');
    const status = form.querySelector('[role="status"]');
    button.disabled = true;
    status.textContent = 'Preparing your brief…';
    try {
      const payload = await post('/api/v1/tools/'+form.dataset.tool+'/generate', {details,request_id:requestId});
      const result = payload.data.result;
      runId = payload.data.id;
      resultSection.querySelector('[data-summary]').textContent = result.summary;
      const sections = resultSection.querySelector('[data-sections]');
      sections.replaceChildren();
      Object.entries({known_facts:'What you told us',questions:'Questions to discuss',next_steps:'Planning steps',unknowns:'Still to clarify'}).forEach(([key,label]) => {
        if (!result[key].length) return;
        const heading = document.createElement('h2'); heading.textContent = label;
        const list = document.createElement('ul');
        result[key].forEach((text) => {const item=document.createElement('li');item.textContent=text;list.append(item)});
        sections.append(heading,list);
      });
      resultSection.hidden = false;
      status.textContent = 'Your brief is ready below.';
    } catch (error) {
      status.textContent = error.message;
      if (!error.message.includes('already being prepared')) requestId = null;
    } finally {button.disabled=false}
  });
  reportForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!runId) return;
    const values = Object.fromEntries(new FormData(reportForm));
    const button = reportForm.querySelector('button');
    const status = reportForm.querySelector('[role="status"]');
    values.marketing_consent = Boolean(values.marketing_consent);
    values.contact_requested = Boolean(values.contact_requested);
    button.disabled = true;
    try {
      const payload = await post('/api/v1/tool-reports/'+runId+'/email',values);
      status.textContent = payload.message;
    } catch (error) {status.textContent=error.message} finally {button.disabled=false}
  });
});
