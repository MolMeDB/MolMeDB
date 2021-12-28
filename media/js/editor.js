
$('.m_type').click(function()
{
    var id = $(this).data('spec').id;
    var type = $(this).data('spec').type;
    var node = this;
    var parent = node.parentNode;
    var countChild = parent.childElementCount;
    var childNodes = parent.getElementsByTagName("li");
    var i = 0;
    
    while (i < countChild)
    {
        childNodes[i].setAttribute("class", "");
        i++;
    }

    node.classList.add("active");

    switch(type)
    {
        case "membrane":
            editMembrane(id);
            break;
        case "method":
            editMethod(id);
            break;
    }
});
    
function editMembrane(id)
{
    var data = ajax_request("membranes/detail", {id: id});
    
    if(data === false)
    {
        return;
    }

    var form = document.getElementById("form_editor");
    var target = form.getAttribute("action");
    form.setAttribute("action", target + id);
    var delete_btn = document.getElementById("btn_delete");
    $(delete_btn).remove();
    
    //Showing textfields
    var parentDiv = document.getElementById("description-par");
    var refParent = document.getElementById("reference-par");

    // Clear actual view
    parentDiv.innerHTML = "";
    refParent.innerHTML = "";

    // Make new structure
    var desc_div = document.createElement("div");
    var textfield = document.createElement("textarea");
    var ref_div = document.createElement("div");
    var ref_textfield = document.createElement("textarea");
    textfield.setAttribute("name", "description");
    ref_textfield.setAttribute("name", "reference");  
    textfield.setAttribute("id", "description");
    ref_textfield.setAttribute("id", "reference");  
    
    // Make delete button
    var btn_delete = document.createElement("button");
    btn_delete.className = "btn btn-sm btn-danger pull-left";
    btn_delete.setAttribute("onclick", "redirect('delete/membrane/" + id + "')");
    btn_delete.setAttribute("type", "button");
    btn_delete.setAttribute("id", "btn_delete");
    btn_delete.innerHTML = "Delete membrane";
    form.appendChild(btn_delete);
            
    document.getElementById("memName").innerHTML = data.name;
    document.getElementById("name").value = data.name;
    document.getElementById("CAM").value = data.CAM;
    document.getElementById("keywords").value = data.keywords;
    textfield.value = data.description;
    ref_textfield.value = data.reference;

    // Append new structure
    desc_div.appendChild(textfield);
    parentDiv.appendChild(desc_div);
    ref_div.appendChild(ref_textfield);
    refParent.appendChild(ref_div);
    
    CKEDITOR.replace( 'description' );
    CKEDITOR.replace( 'reference' );
}

/**
 * Loads method editor form
 * 
 * @param {integer} id Method ID 
 */
function editMethod(id)
{
    // Get data
    var data = ajax_request("methods/get", { id: id });

    if (data === false) 
    {
        return;
    }

    var form = document.getElementById("form_editor");
    var target = form.getAttribute("action");
    form.setAttribute("action", target + id);
    var delete_btn = document.getElementById("btn_delete");
    $(delete_btn).remove();

    //Showing textfields
    var parentDiv = document.getElementById("description-par");
    var refParent = document.getElementById("reference-par");

    // Clear actual view
    parentDiv.innerHTML = "";
    refParent.innerHTML = "";

    // Make new structure
    var desc_div = document.createElement("div");
    var textfield = document.createElement("textarea");
    var ref_div = document.createElement("div");
    var ref_textfield = document.createElement("textarea");
    textfield.setAttribute("name", "description");
    ref_textfield.setAttribute("name", "reference");
    textfield.setAttribute("id", "description");
    ref_textfield.setAttribute("id", "reference");

    // Make delete button
    var btn_delete = document.createElement("button");
    btn_delete.className = "btn btn-sm btn-danger pull-left";
    btn_delete.setAttribute("onclick", "redirect('delete/method/" + id + "')");
    btn_delete.setAttribute("type", "button");
    btn_delete.setAttribute("id", "btn_delete");
    btn_delete.innerHTML = "Delete membrane";
    form.appendChild(btn_delete);

    document.getElementById("methName").innerHTML = data.name;
    document.getElementById("name").value = data.name;
    document.getElementById("CAM").value = data.CAM;
    document.getElementById("keywords").value = data.keywords;
    textfield.value = data.description;
    ref_textfield.value = data.reference;

    // Append new structure
    desc_div.appendChild(textfield);
    parentDiv.appendChild(desc_div);
    ref_div.appendChild(ref_textfield);
    refParent.appendChild(ref_div);

    CKEDITOR.replace('description');
    CKEDITOR.replace('reference');
}