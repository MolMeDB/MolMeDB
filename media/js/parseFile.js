var form = document.createElement("form");
var uploadName = document.createElement("div");
uploadName.hidden = true;
var div = document.getElementById("table");
var modal = document.getElementById('dataupload_modal');
var span = document.getElementById("dataupload_close");
var file_preview;
var last_id;

/**
 * Loads file detail using REST API
 * and parsing to the form
 * 
 * @param {string} id - File ID 
 * @param {string} type - Filetype - interactions/transporters 
 */
function parseFile(id, type)
{
    last_id = id;
    div.innerHTML = form.innerHTML = '';
    form.appendChild(uploadName);
    
    file_preview = ajax_request('file/preview/' + id);

    if(!file_preview)
    {
        add_message('Cannot load datafile. Please, contact server administrator.', 'danger');
        return;
    }
    
    if(type == '20')
    {
        parse_interaction_file();
        return;
    }
    else if(type == '21')
    {
        parse_transporters_file();
        return;
    }
    else
    {
        add_message("Wrong file type. Please, contact server administrator.", 'danger');
        return;
    }
}

/**
 * Parse file with transporters and makes form
 * 
 * 
 */
function parse_transporters_file(delimiter = ";", clear=false)
{
    if(clear)
    {
        div.innerHTML = form.innerHTML = ''; 
    }
    
    file = file_preview;
    modal.style.display = "block";
    span.onclick = function() {
        modal.style.display = "none";
        div.children[0].remove();
    }; 
   
    var refs = returnRefs();
    
    var total_rows = file.length;
    
    if(!total_rows)
    {
        // TODO
        return;
    }

    // Add delimiter settings
    $('<div>Delimiter: </div>').append(
    $('<select required name="delimiter" id="delimiter"></select>').append(
        [
            ... $('<option ' + (delimiter == ';' ? 'selected' : '') + ' value=";">;</option>'),
            $('<option ' + (delimiter == '\t' ? 'selected' : '') + ' value="\t">Tab (\\t)</option>'),
            $('<option ' + (delimiter == ' ' ? 'selected' : '') + ' value=" ">Space( )</option>'),
            $('<option ' + (delimiter == ',' ? 'selected' : '') + ' value=",">Comma (,)</option>'),
        ]
    )).appendTo($(form));

    // Process file content
    var rows = [];

    for(row in file)
    {
        rows.push(file[row].split(delimiter));
    }
    
    var table = document.createElement("table");

    form.setAttribute("method", "post"); 
    form.setAttribute("enctype","multipart/form-data");
    
    table.setAttribute("class", "dataParser");
    table.setAttribute("style", "margin-bottom: 20px;");
    
    var total_columns = rows[0].length;
    var tr = document.createElement("tr");
    tr.setAttribute("class", "types");
    var types = ["Don't use", "Name", "Target", "Note", "Primary_reference", 
                "Uniprot_id", "Type", "Km", "Km_acc", "Ki", "Ki_acc", "IC50", "IC50_acc", "EC50", "EC50_acc", 'SMILES', "DrugBank_ID", "PubChem_ID", "PDB_ID",
                "MW"];
    
    for(var i=0; i<total_columns; i++){
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
    
    for(i=0; i<total_rows; i++){
        tr = document.createElement("tr");
        var input = document.createElement("input"); 
        
        if(i==0)
            tr.setAttribute("class", "head");
        for(j=0; j<total_columns; j++){
            var td = document.createElement("td");
            var input = document.createElement("input");
            input.setAttribute("class", "form-control");
            if(i == 0 || j != 0){
                input.setAttribute("readonly", "true");
                if (i==0)
                    input.setAttribute("style", "cursor: pointer;");
            }
            
            input.value = rows[i][j];

            td.appendChild(input);
            tr.appendChild(td);
        }
        table.appendChild(tr);
    }

    
    var input = document.createElement("input");
    input.setAttribute("value", "save_transporters");
    input.setAttribute("name", "postType");
    input.setAttribute("hidden", "true");
    form.appendChild(input);

    $(form).append($('<div></div>').append(table));
    
    var button = document.createElement("button");
    button.setAttribute("type", "button");
    button.setAttribute("class", "btn btn-sm btn-success pull-right");
    button.setAttribute("style", "margin: 5px 0px;");
    button.setAttribute("onclick", "submit_datafile()");
    button.innerHTML = "Save";
   
    var rowCount = document.createElement("input"); rowCount.setAttribute("hidden", "true"); rowCount.value = total_rows; rowCount.setAttribute("name", "rowCount");
    rowCount.setAttribute("id", "rowCount");
    var colCount = document.createElement("input"); colCount.setAttribute("hidden", "true"); colCount.value = total_columns; colCount.setAttribute("name", "colCount");
    
    form.appendChild(rowCount);
    form.appendChild(colCount);
    
    form.appendChild(refs);
    form.appendChild(button);
    div.appendChild(form);

    $('#delimiter').on('change', function()
    {
        parse_transporters_file($('#delimiter').val(), true);
    });
}

/**
 * Parse file with transporters and makes form
 * 
 * 
 */
function parse_interaction_file(delimiter = ";", clear=false)
{
    if(clear)
    {
        div.innerHTML = form.innerHTML = ''; 
    }

    file = file_preview;
    modal.style.display = "block";
    span.onclick = function() {
        modal.style.display = "none";
        div.children[0].remove();
    }; 
   
    var methods = returnMethods();
    var membranes = returnMembranes();
    var refs = returnRefs();

    var total_rows = file.length;
    
    if(!total_rows)
    {
        // TODO
        return;
    }

    // Add delimiter settings
    $('<div>Delimiter: </div>').append(
    $('<select required name="delimiter" id="delimiter"></select>').append(
        [
            ... $('<option ' + (delimiter == ';' ? 'selected' : '') + ' value=";">;</option>'),
            $('<option ' + (delimiter == '\t' ? 'selected' : '') + ' value="\t">Tab (\\t)</option>'),
            $('<option ' + (delimiter == ' ' ? 'selected' : '') + ' value=" ">Space( )</option>'),
            $('<option ' + (delimiter == ',' ? 'selected' : '') + ' value=",">Comma (,)</option>'),
        ]
    )).appendTo($(form));

    // Process file content
    var rows = [];

    for(row in file)
    {
        rows.push(file[row].split(delimiter));
    }

    var table = document.createElement("table");

    form.setAttribute("method", "post"); 
    form.setAttribute("enctype","multipart/form-data");
    
    table.setAttribute("class", "dataParser");
    
    var total_columns = rows[0].length;
    var tr = document.createElement("tr");
    tr.setAttribute("class", "types");
    var types = ["Don't use", "Name", "Primary_reference", "Note", "Q", "X_min", "X_min_acc", "G_pen", "G_pen_acc", "G_wat", "G_wat_acc", "LogK", "LogK_acc", "LogPerm", "LogPerm_acc"
        ,"theta", "theta_acc", "abs_wl", "abs_wl_acc", "fluo_wl", "fluo_wl_acc", "QY", "QY_acc", "lt", "lt_acc", "MW", "SMILES", "DrugBank_ID", "PubChem_ID", "PDB_ID", "Chembl_id", "Chebi_id"];
    
    for(var i=0; i<total_columns; i++){
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
    
    
    for(i=0; i<total_rows; i++){
        tr = document.createElement("tr");
        var input = document.createElement("input"); 
        
        if(i==0)
            tr.setAttribute("class", "head");
        for(j=0; j<total_columns; j++){
            var td = document.createElement("td");
            var input = document.createElement("input");
            input.setAttribute("class", "form-control");
            if(i == 0 || j != 0){
                input.setAttribute("readonly", "true");
                if (i==0)
                    input.setAttribute("style", "cursor: pointer;");
            }
            
            input.value = rows[i][j];

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

    $(form).append($('<div></div>').append(table));
    
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
   
    var rowCount = document.createElement("input"); rowCount.setAttribute("hidden", "true"); rowCount.value = total_rows; rowCount.setAttribute("name", "rowCount");
    rowCount.setAttribute("id", "rowCount");
    var colCount = document.createElement("input"); colCount.setAttribute("hidden", "true"); colCount.value = total_columns; colCount.setAttribute("name", "colCount");
    
    form.appendChild(rowCount);
    form.appendChild(colCount);
    
    form.appendChild(label);
    form.appendChild(input);
    form.appendChild(refs);
    form.appendChild(membranes);
    form.appendChild(methods);
    form.appendChild(button);
    div.appendChild(form);

    $('#delimiter').on('change', function()
    {
        parse_interaction_file($('#delimiter').val(), true);
    });
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

    $(form).append($('<input hidden name="file_id" value="' + last_id + '">'));
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