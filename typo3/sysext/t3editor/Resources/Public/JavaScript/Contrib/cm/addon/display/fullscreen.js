!function(e){"object"==typeof exports&&"object"==typeof module?e(require("../../lib/codemirror")):"function"==typeof define&&define.amd?define(["../../lib/codemirror"],e):e(CodeMirror)}(function(e){"use strict";e.defineOption("fullScreen",!1,function(t,o,r){var l,i;(r==e.Init&&(r=!1),!r!=!o)&&(o?(i=(l=t).getWrapperElement(),l.state.fullScreenRestore={scrollTop:window.pageYOffset,scrollLeft:window.pageXOffset,width:i.style.width,height:i.style.height},i.style.width="",i.style.height="auto",i.className+=" CodeMirror-fullscreen",document.documentElement.style.overflow="hidden",l.refresh()):function(e){var t=e.getWrapperElement();t.className=t.className.replace(/\s*CodeMirror-fullscreen\b/,""),document.documentElement.style.overflow="";var o=e.state.fullScreenRestore;t.style.width=o.width,t.style.height=o.height,window.scrollTo(o.scrollLeft,o.scrollTop),e.refresh()}(t))})});