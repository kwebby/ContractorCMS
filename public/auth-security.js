/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

const setup=document.querySelector('#mfa-setup');const output=document.querySelector('#mfa-message');
async function send(path,data){const r=await fetch(path,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(data)});const j=await r.json();if(!r.ok)throw new Error(j.message||'Unable to complete this action.');return j;}
setup?.addEventListener('submit',async e=>{e.preventDefault();try{const j=await send('/api/v1/security/mfa/setup',Object.fromEntries(new FormData(setup)));document.querySelector('#mfa-secret').hidden=false;document.querySelector('#secret-value').textContent=j.secret;output.textContent='Enter a code from your authenticator to finish.';}catch(e){output.textContent=e.message;}});
document.querySelector('#mfa-confirm')?.addEventListener('submit',async e=>{e.preventDefault();try{await send('/api/v1/security/mfa/confirm',Object.fromEntries(new FormData(e.target)));location.assign('/app');}catch(e){output.textContent=e.message;}});
