var form = document.createElement("form");
var uploadName = document.createElement("div");
uploadName.hidden = true;
var file;

/**
 * Loads file detail using REST API
 * and parsing to the form
 * 
 * @param {string} name - Filename 
 */
function parseFile(name)
{
    var div = document.getElementById("table");
    var modal = document.getElementById('dataupload_modal');
    var span = document.getElementById("dataupload_close");
    form.innerHTML = '';
    form.appendChild(uploadName);
    
    file = ajax_request('uploader/getFile', {fileName: name});

    if(!file)
    {
        add_message('Cannot load datafile. Please, contact server administrator.', 'danger');
        return;
    }
    
    modal.style.display = "block";
    span.onclick = function() {
        modal.style.display = "none";
        div.children[0].remove();
    }; 
   
    var methods = returnMethods();
    var membranes = returnMembranes();
    var refs = returnRefs();
    
    var size = (file.length);    
    
    var table = document.createElement("table");

    form.setAttribute("method", "post"); 
    form.setAttribute("enctype","multipart/form-data");
    
    table.setAttribute("class", "dataParser");
    
    var count = file[0].length;
    var tr = document.createElement("tr");
    tr.setAttribute("class", "types");
    var types = ["Don't use", "Name", "Primary_reference", "Q", "X_min", "X_min_acc", "G_pen", "G_pen_acc", "G_wat", "G_wat_acc", "LogK", "LogK_acc", "LogP", "LogPerm", "LogPerm_acc"
        ,"theta", "theta_acc", "abs_wl", "abs_wl_acc", "fluo_wl", "fluo_wl_acc", "QY", "QY_acc", "lt", "lt_acc", "MW", "SMILES", "DrugBank_ID", "PubChem_ID", "PDB_ID", "Area", "Volume"];
    
    for(var i=0; i<count; i++){
        var td = document.createElement("td");
        var select = document.createElement("select");
        select.setAttribute("class", "form-control attr-chooser");
        select.setAttribute("required", "true");
        
        for(var j=0; j<types.length; j++){
            var option = document.createElement("option");
            if(j == 0)
                option.value = "";
            else
                option.value = types[j];
            
            option.innerHTML = types[j];
            select.appendChild(option);
        }
        td.appendChild(select);
        tr.appendChild(td);
    }
    table.appendChild(tr);
    
    
    for(i=0; i<size; i++){
        tr = document.createElement("tr");
        var input = document.createElement("input"); 
        
        if(i==0)
            tr.setAttribute("class", "head");
        for(j=0; j<count; j++){
            var td = document.createElement("td");
            var input = document.createElement("input");
            input.setAttribute("class", "form-control");
            input.setAttribute("name", "row_" + i + "[]");
            if(i == 0 || j != 0){
                input.setAttribute("readonly", "true");
                if (i==0)
                    input.setAttribute("style", "cursor: pointer;");
            }
            
            input.value = file[i][j];

            td.appendChild(input);
            tr.appendChild(td);
        }
        table.appendChild(tr);
    }
    
    var input = document.createElement("input");
    input.setAttribute("value", "save_dataset");
    input.setAttribute("name", "postType");
    input.setAttribute("hidden", "true");
    form.appendChild(input);
    form.appendChild(table);
    
    var label = document.createElement("label");
    label.innerHTML = "Temperature [°C]";
    
    input = document.createElement("input");
    input.setAttribute("value", "25");
    input.setAttribute("name", "temp");
    input.setAttribute("class", "form-control, parserInput");
    input.setAttribute("required", "true");
    
    var button = document.createElement("button");
    button.setAttribute("type", "button");
    button.setAttribute("class", "btn btn-sm btn-success pull-right");
    button.setAttribute("style", "margin: 5px 0px;");
    button.setAttribute("onclick", "submit_datafile()");
    button.innerHTML = "Save";
   
    var rowCount = document.createElement("input"); rowCount.setAttribute("hidden", "true"); rowCount.value = size; rowCount.setAttribute("name", "rowCount");
    rowCount.setAttribute("id", "rowCount");
    var colCount = document.createElement("input"); colCount.setAttribute("hidden", "true"); colCount.value = count; colCount.setAttribute("name", "colCount");
    
    form.appendChild(rowCount);
    form.appendChild(colCount);
    
    form.appendChild(label);
    form.appendChild(input);
    form.appendChild(refs);
    form.appendChild(membranes);
    form.appendChild(methods);
    form.appendChild(button);
    div.appendChild(form);
}

/**
 * Submits form
 */
function submit_datafile()
{
    // Delete old inputs
    $('.attr-input').each(function(key, input){
        $(input).remove();
    });

    $('.attr-chooser').each(function(k, element){
        var input = document.createElement('input');
        input.setAttribute('name', 'attr[]');
        input.setAttribute('hidden', 'true');
        input.classList.add('attr-input');
        input.value = $(element).val();

        form.appendChild(input);
    });

    $(form).submit();
}

/**
 * Gets SELECT of membranes
 */
function returnMembranes()
{    
    var result = get_all_membranes();
    var size = result.length;
    
    var select = document.createElement("select");
    select.setAttribute("class", "form-control");
    select.setAttribute("required", "true");
    select.setAttribute("name", "membrane");
    
    var option = document.createElement("option");
    option.value = '';
    option.innerHTML = "Choose membrane";
    select.appendChild(option);
    
    for(var i = 0; i<size; i++){
        var option = document.createElement("option");
        option.value = result[i].id;
        option.innerHTML = result[i].name;
        select.appendChild(option);    
    }
    return select;
}

/**
 * Gets SELECT of methods
 */
function returnMethods()
{
    var result = get_all_methods();
    var size = result.length;
    
    var select = document.createElement("select");
    select.setAttribute("class", "form-control");
    select.setAttribute("required", "true");
    select.setAttribute("name", "method");
    
    var option = document.createElement("option");
    option.value = '';
    option.innerHTML = "Choose method";
    select.appendChild(option);
    
    for(var i = 0; i<size; i++){
        var option = document.createElement("option");
        option.value = result[i].id;
        option.innerHTML = result[i].name;
        select.appendChild(option);    
    }
    return select;
}


function returnRefs()
{
    var result = get_all_publications();
    var size = result.length;
    
    var select = document.createElement("select");
    select.setAttribute("class", "form-control");
    select.setAttribute("required", "true");
    select.setAttribute("name", "reference");
    
    var option = document.createElement("option");
    option.value = 0;
    option.innerHTML = "Choose secondary reference";
    select.appendChild(option);
    
    for(var i = 0; i<size; i++){
        var option = document.createElement("option");
        option.value = result[i].id;
        option.innerHTML = result[i].citation;
        select.appendChild(option);    
    }
    return select;
}