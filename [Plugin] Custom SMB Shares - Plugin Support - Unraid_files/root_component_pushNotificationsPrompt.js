;(function(){"use strict";class iPushNotificationsPrompt extends HTMLElement{constructor(){super();}
connectedCallback(){if(!("Notification"in window))return;this.addEventListener("click",this);if((this.hasAttribute("data-persistent"))||(Notification.permission==="default")){this.updateUI(Notification.permission);this.hidden=false;}}
handleEvent(e){return this[`${e.type}Event`]&&this[`${e.type}Event`](e);}
clickEvent(e){const el=e.target.closest('[data-click]');if(!el)return;const method=el.getAttribute("data-click");if(method&&typeof this[method]==="function"){return this[method](e);}else{console.warn(`No method named "${method}" found on`,this);}}
hideMessage(e){this.hidden=true;}
async requestPermission(){try{const permission=await Notification.requestPermission();if(permission==="granted"){$(document).trigger('permissionGranted.notifications');}
else
{$(document).trigger('permissionDenied.notifications');}
document.querySelectorAll("i-push-notifications-prompt").forEach(el=>{el.updateUI(permission);});}catch(error){console.error('Permission request failed:',error);document.querySelectorAll("i-push-notifications-prompt").forEach(el=>{el.updateUI('error');});}}
updateUI(permission){this.setAttribute("data-permission",permission);const contentEl=this.querySelector('[data-role="content"]');if(!contentEl)return;const template=this.querySelector(`template[data-value="${permission}"]`);if(!template){console.warn(`Cannot update message. There is no template[data-value="${permission}"] element`);return;}
const clone=template.content.cloneNode(true);contentEl.innerHTML='';contentEl.appendChild(clone);}}
ips.ui.registerWebComponent("pushNotificationsPrompt",iPushNotificationsPrompt);Debug.log(`Submitted the web component constructor, iPushNotificationsPrompt, for ${"pushNotificationsPrompt"}`);})();;