;(function(){"use strict";class Tooltip extends HTMLElement{constructor(){super();this.anchor=null;this.tooltipContent="";this.supportsAnchor=CSS.supports("(top: anchor(bottom)) and (not (-webkit-hyphens:auto))");this.addEventListener("beforetoggle",this);}
connectedCallback(){if(window.matchMedia("(hover: hover) and (pointer: fine)").matches){document.addEventListener("mouseover",this);document.addEventListener("focusin",this);}}
handleEvent(e){if(this[`${e.type}Event`]){this[`${e.type}Event`](e);}}
mouseoverEvent(e){this.potentiallyShowTooltip(e);}
focusinEvent(e){this.potentiallyShowTooltip(e);}
potentiallyShowTooltip(e){if(this._raf)cancelAnimationFrame(this._raf);this._raf=requestAnimationFrame(()=>{this._raf=null;const anchor=e.target.closest("[data-ipstooltip]");if(!anchor||this.anchor===anchor)return;this.anchor=anchor;this.getTooltipContent();if(!this.tooltipContent)return;this.showPopover({source:this.anchor});});}
mouseleaveEvent(e){this.hidePopover();}
focusoutEvent(e){this.hidePopover();}
scrollEvent(e){this.hidePopover();}
beforetoggleEvent(e){if(e.newState==="open"){this.anchor.addEventListener("focusout",this);this.anchor.addEventListener("mouseleave",this);document.addEventListener("scroll",this,{passive:true});this.innerHTML=this.tooltipContent;const ajaxContent=this.anchor.getAttribute("data-ipstooltip-ajax");if(ajaxContent){const currentAnchor=this.anchor;ips.getAjax()(ajaxContent).done(response=>{if(currentAnchor===this.anchor){this.anchor.setAttribute("data-ipstooltip",response);this.innerHTML=response;this.anchor.removeAttribute("data-ipstooltip-ajax");}});}
this.anchor.setAttribute("aria-describedby",this.id);if(!this.supportsAnchor){this.anchorPolyfill();}
this.anchorObserver=new MutationObserver(()=>{if(!document.body.contains(this.anchor)){this.hidePopover();}});this.anchorObserver.observe(document.body,{childList:true,subtree:true});}else{if(this.anchorObserver){this.anchorObserver.disconnect();this.anchorObserver=null;}
if(this.anchor){this.anchor.removeAttribute("aria-describedby");this.anchor.removeEventListener("focusout",this);this.anchor.removeEventListener("mouseleave",this);}
this.anchor=null;this.tooltipContent=null;document.removeEventListener("scroll",this,{passive:true});}}
getTooltipContent(){let tooltipContent;this.tooltipContent=null;if(this.anchor.title){this.anchor.dataset.ipstooltip=this.anchor.title;this.anchor.removeAttribute("title");}
tooltipContent=this.anchor.dataset.ipstooltip||this.anchor.ariaLabel;if(tooltipContent){this.tooltipContent=tooltipContent;return;}
tooltipContent=this.anchor.dataset.ipstooltipLabel;if(tooltipContent){this.anchor.dataset.ipstooltip=tooltipContent;this.anchor.removeAttribute("data-ipstooltip-label");this.tooltipContent=tooltipContent;return;}
tooltipContent=this.anchor.dataset.ipstooltipSource;if(tooltipContent){const tooltipContentEl=document.getElementById(tooltipContent);if(tooltipContentEl){this.tooltipContent=tooltipContentEl.innerHTML;return;}}
tooltipContent=this.anchor.querySelector("i-tooltip-content");if(tooltipContent){this.tooltipContent=tooltipContent.innerHTML;return;}}
anchorPolyfill(){const rect=this.anchor.getBoundingClientRect();this.style.setProperty("--top",rect.top+"px");this.style.setProperty("--left",rect.left+rect.width/2+"px");this.style.setProperty("--height",rect.height+"px");}}
ips.ui.registerWebComponent("tooltip",Tooltip);Debug.log(`Submitted the web component constructor, Tooltip, for ${"tooltip"}`);})();;