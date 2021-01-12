var acc = document.getElementsByClassName("accordion");
var i;

for (i = 0; i < acc.length; i++) {
  acc[i].onclick = function() {
    this.classList.toggle("active");
    var panel = this.nextElementSibling;
    if (panel.style.maxHeight){
      panel.style.maxHeight = null;
    } else {
      panel.style.maxHeight = (panel.scrollHeight + 80) + "px";
    } 
  }
}

var checkboxes = document.getElementsByClassName("checkbox-browse-mem");
var checkboxes2 = document.getElementsByClassName("checkbox-browse-met");
var allMem = document.getElementById("sel-all-mem");
var allMet = document.getElementById("sel-all-met");

$('.browse-advance-list-item').each(function(key,obj){
    obj.onclick = function() {
        var inp = obj.firstElementChild.firstElementChild;
        
        obj.classList.toggle('item-active');
        inp.checked = inp.checked == 'true' || inp.checked ? false : true;
        
        inp.onchange();
    }
})

for (i = 0; i < checkboxes.length; i++) {
  checkboxes[i].onchange = function() {
    for(var j = 0; j<checkboxes.length; j++){
        if(checkboxes[j].checked)
            continue;
        else{
            allMem.checked = false;
            allMem.parentNode.parentNode.classList.remove("item-active");
            return;
        }
    }
    allMem.checked = true;
    allMem.parentNode.parentNode.classList.add("item-active");
  }
}

for (i = 0; i < checkboxes2.length; i++) {
  checkboxes2[i].onchange = function() {
    for(var j = 0; j<checkboxes2.length; j++){
        if(checkboxes2[j].checked)
            continue;
        else{
            allMet.checked = false;
            allMet.parentNode.parentNode.classList.remove("item-active");
            return;
        }
    }
    allMet.checked = true;
    allMet.parentNode.parentNode.classList.add("item-active");
  }
}

allMem.onchange = function() {
    if(allMem.checked){
        for (var i = 0; i < checkboxes.length; i++){
            checkboxes[i].checked = true;
            checkboxes[i].parentNode.parentNode.classList.add("item-active");
        }
    }
    else
        for (var i = 0; i < checkboxes.length; i++){
            checkboxes[i].checked = false;
            checkboxes[i].parentNode.parentNode.classList.remove("item-active");
        }
}

allMet.onchange = function() {
    if(allMet.checked){
        for (var i = 0; i < checkboxes2.length; i++){
            checkboxes2[i].checked = true;
            checkboxes2[i].parentNode.parentNode.classList.add("item-active");
        }
    }
    else
        for (var i = 0; i < checkboxes2.length; i++){
            checkboxes2[i].checked = false;
            checkboxes2[i].parentNode.parentNode.classList.remove("item-active");
        }
}