;(function($,_,undefined){"use strict";ips.controller.register('core.front.helpful.helpful',{ajaxObj:null,initialize:function(){this.on('click','[data-action="helpful"]',this.markHelpful);},async markHelpful(e){e.preventDefault();let clicked=$(e.currentTarget);let showHelpfulButton=document.querySelector('[data-role="helpfulCount"]');let mostHelpfulBox=document.querySelector('[data-role="mostHelpful"]');clicked.addClass('i-opacity_3');const response=await ips.fetch(clicked.attr('href'));if(!response.error){clicked.closest("li").replaceWith(response.button);if(response.helpfulReplies===0){}
if(showHelpfulButton&&response.countLanguage){$(showHelpfulButton).html(response.countLanguage);}
if(mostHelpfulBox){if(response.mostHelpfulHtml){$(mostHelpfulBox).removeClass('ipsHide');$(mostHelpfulBox).html(response.mostHelpful);}
else
{$(mostHelpfulBox).addClass('ipsHide');}}}
clicked.removeClass('i-opacity_3');}});}(jQuery,_));;