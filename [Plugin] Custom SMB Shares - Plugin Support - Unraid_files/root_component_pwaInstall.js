;(function(){"use strict";class IPwaInstall extends HTMLElement{constructor(){super();this.deferredAndroidPrompt=null;this.dismissed=ips.utils.cookie.get('pwaInstallBanner');}
handleEvent(e){return this[e.type+"Event"]&&this[e.type+"Event"](e);}
connectedCallback(){this.addEventListener("click",this);window.addEventListener('appinstalled',this);window.addEventListener('beforeinstallprompt',this);}
clickEvent(e){if(e.target.closest("#iPwaInstall__dismiss")){ips.utils.cookie.set('pwaInstallBanner',"dismissed",true);this.hideBanner();}}
beforeinstallpromptEvent(e){if(this.dismissed)return;e.preventDefault();this.deferredAndroidPrompt=e;}
appinstalledEvent(e){ips.utils.cookie.set('pwaInstallBanner',"installed",true);this.hideBanner();}
hideBanner(){this.hidden=true;}}
ips.ui.registerWebComponent("pwaInstall",IPwaInstall);Debug.log(`Submitted the web component constructor, IPwaInstall, for ${"pwaInstall"}`);})();;