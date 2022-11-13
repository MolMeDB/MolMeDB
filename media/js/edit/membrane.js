
// function category_detail(level, id, button, detail = false)
// {
//     var btns = document.getElementById("cat-level-" + level).children;
//     var values = [];
//     var values_len;
//     var new_lvl = level+1;
//     var new_div = document.getElementById("cat-level-" + new_lvl);
//     var idSubcat = 0;
    
//     if(level == "2"){
//         var btns_lvl_1 = document.getElementById("cat-level-1").children;
      
//         for(var i = 0; i < btns_lvl_1.length; i++){
//             if(btns_lvl_1[i].classList.contains('cat-active')){
//                 idSubcat = btns_lvl_1[i].children[0].value;
//                 break;
//             }
//         }
//     }
//     else
//         document.getElementById("cat-level-3").innerHTML = '';
    
//     var data = ajax_request("membranes/getCategories", {
//         idCategory: id,
//         idSubcategory: idSubcat,
//         level: level
//     });

//     if(data === false)
//     {
//         values_len = 0;
//     }

//     values_len = data.length;
    
//     new_div.innerHTML = '';
    
//     for(var i =0; i < values_len; i++)
//     {
//         var input = document.createElement('input');
//         input.hidden = true;
//         input.setAttribute("value", data[i].id);
//         var div = document.createElement("div");
//         div.classList.toggle("category-item");
//         if(level === 1){
//             div.setAttribute('onclick', 'category_detail(2,\'' + data[i].id + '\', this, true)');
//         }
        
//         if(detail){
//              div.setAttribute('onclick', 'show_membrane(\'' + data[i].id + '\', this)');
//         }
        
//         div.appendChild(input);
//         div.innerHTML += "<div>" + data[i].name + "</div>";
        
//         new_div.appendChild(div);
//     }
    
//     if(values_len < 1){
//         var div = document.createElement("div");
//         div.classList.toggle("category-item");
//         div.innerHTML = "<b>*No membranes*</b>";
//         new_div.appendChild(div);
//     }
    
//     for(var i =0; i < btns.length; i++){
//         if(btns[i].classList.contains("cat-active"))
//             btns[i].classList.toggle('cat-active');
//     }
    
//     button.classList.toggle('cat-active');
    
// }

function show_membrane(idBtn, btn)
{
    document.getElementById('tab' + idBtn + '-tab').click();
    var mems = document.getElementById("cat-level-3").children;
    for(var i = 0; i < mems.length; i++)
        if(mems[i].classList.contains("cat-active"))
            mems[i].classList.toggle("cat-active");
    btn.classList.toggle("cat-active");
}


// function setActive_category(id)
// {
//     id = id.replace("-tab", "").replace("tab", "");
//     var cat_1 = document.getElementById("cat-level-1").children;
//     var cat_2 = document.getElementById("cat-level-2");
//     var cat_3 = document.getElementById("cat-level-3").children;
    
//     var membraneCategory = document.getElementById('membraneCategory_' + id);

//     //Is already set or loaded?
//     var div_3 = cat_3;
//     if(div_3.length != 0){
//         var t = 0;
//         for(var i = 0; i <div_3.length; i++){
//             if(div_3[i].classList.contains("cat-active"))
//                 div_3[i].classList.toggle("cat-active");
//             if(div_3[i].children[0].value === id){
//                 div_3[i].classList.toggle("cat-active");
//                 t = 1;
//             }
//         }
//         if (t === 1)
//             return;
//     }
    
//     //
    
//     cat_2.innerHTML = "";
    
//     var subcats = [];
//     var active = [];
//     var subcats_len = 0;
    
    
//     var data = ajax_request('membranes/getCategory', {idMembrane: id});

//     active = data.membrane_categories[0];
//     subcats = data.all_subcategories;

//     subcats_len = subcats.length;

//     var category_active_text = "";
//     var subcategory_active_text = "";
   
//     for(var i = 0; i <cat_1.length; i++){
//         if(cat_1[i].classList.contains("cat-active"))
//             cat_1[i].classList.toggle('cat-active');
        
//         if(cat_1[i].children[0].value == active.cat_id)
//         {
//             cat_1[i].classList.toggle('cat-active');
//         }
//     }
    
//     for(var i =0; i < subcats_len; i++){
//         var input = document.createElement('input');
//         input.hidden = true;
//         input.setAttribute("value", subcats[i].id);
//         var div = document.createElement("div");
//         div.classList.toggle("category-item");
        
//         div.setAttribute('onclick', 'category_detail(2,\'' + subcats[i].id + '\', this, true)');
        
        
//         div.appendChild(input);
//         div.innerHTML += "<div>" + subcats[i].name + "</div>";
        
        
        
//         cat_2.appendChild(div);
        
//         if(subcats[i].id === active.subcat_id){
//             category_detail(2, subcats[i].id, div, true);
//             subcategory_active_text = subcats[i].name;
//         }
//     }
    
//     for(var i = 0; i <cat_3.length; i++){
//         if(cat_3[i].children[0].value == id)
//             cat_3[i].classList.toggle('cat-active');
//     }
    
    
//     var cat_text_el = document.createElement('div');
//     var subcat_text_el = document.createElement('div');
//     var chevron = document.createElement('div');
    
//     cat_text_el.classList.toggle('categoryName');
//     subcat_text_el.classList.toggle('categoryName');
//     chevron.classList.toggle('categoryArrow');
    
//     cat_text_el.innerHTML = category_active_text;
//     subcat_text_el.innerHTML = subcategory_active_text;
//     chevron.innerHTML  = '<span class="glyphicon glyphicon-chevron-right"></span>';
    
//     membraneCategory.innerHTML = '';
    
//     membraneCategory.appendChild(cat_text_el);
//     membraneCategory.appendChild(chevron);
//     membraneCategory.appendChild(subcat_text_el);    
// }