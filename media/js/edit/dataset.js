// Get the modal
var modal = document.getElementById('myModal');

// Get the <span> element that closes the modal
var span = document.getElementById("cl-modal");

// When the user clicks on <span> (x), close the modal
span.onclick = function() {
    modal.style.display = "none";
}

var export_data;

// When the user clicks anywhere outside of the modal, close it
window.onclick = function(event) {
    if (event.target == modal) {
        modal.style.display = "none";
    }
}
        
        
function toggle_rights(id_dataset, id_user, group = false){
    var params = {
        id_entity: id_user,
        group: group,
        spec_type: 'edit_rights'
    };
    
    return redirect('edit/dataset/' + id_dataset, params, 'POST');
}



function edit_basic_info(edit_btn)
{
    edit_btn.style.visibility = 'hidden';
    var old_table = document.getElementById("basic_info");
    old_table.style.display = 'none';
    var new_table = document.createElement("table");
    var tbody = document.createElement("tbody");
    var div = document.getElementById("table-basic-info-div");
    var c = old_table.rows;
    var count = c.length - 1;
    var membranes = get_all_membranes();
    var methods = get_all_methods();
    var publications = get_all_publications();
    var submit_button = document.createElement("button");
    
    var form = document.createElement("form");
    form.setAttribute("method", "post");
    var input = document.createElement("input");
    input.name="spec_type"; input.setAttribute("value","edit_basic"); input.hidden = true;
    form.appendChild(input);
    form.appendChild(document.getElementById("id_dataset"));
    
    for(var i = 0; i < count; i++)
    {
        var tr = document.createElement("tr");
        var child = c[i].children;
        
        if(i == 0)
        {
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                if(j == 1){
                    var input = document.createElement("input");
                    input.name = "dataset_name";
                    input.value = (child[j].innerHTML).trim();
                    input.className = "form-control";
                    td.appendChild(input);
                }
                else
                    td.innerHTML = (child[j].innerHTML).trim();
                tr.appendChild(td);
            }
        }
        
        else if (i < 5){
            var child = c[i].children;
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                td.innerHTML = (child[j].innerHTML).trim();
                tr.appendChild(td);
            }
        }
        
        else if (i == 5){
            var child = c[i].children;
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                if(j == 0){
                    td.innerHTML = (child[j].innerHTML).trim();
                    tr.appendChild(td);
                }
                else{
                    var select = document.createElement("select");
                    select.className="form-control";
                    select.name = "dataset_membrane";
                    for(var k=-1; k < membranes.length; k++)
                    {
                        var option = document.createElement("option");
                        
                        if(k === -1)
                        { //First option with actual value
                            var id = document.getElementById("id_membrane").value;
                            var current = ajax_request('membranes/get', {id: id});

                            if(!current)
                            {
                                continue;
                            }

                            var option_del = document.createElement("option");

                            option.value = id;
                            option.innerHTML = current.name;
                            option_del.value = '';
                            option_del.disabled = true;
                            option_del.innerHTML = "---------------";
                            
                            select.appendChild(option);
                            select.appendChild(option_del);
                            continue;
                        }
                        
                        var option = document.createElement("option");
                        option.value = membranes[k].id; //ID Membrane
                        option.innerHTML = membranes[k].name; //Name membrane
                        select.appendChild(option);
                    }
                    td.appendChild(select);
                    tr.appendChild(td);
                } 
                    
            }
        }
        
        else if (i == 6){
            var child = c[i].children;
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                if(j == 0){
                    td.innerHTML = (child[j].innerHTML).trim();
                    tr.appendChild(td);
                }
                else{
                    var select = document.createElement("select");
                    select.className="form-control";
                    select.name = "dataset_method";
                    
                    for(var k=-1; k < methods.length; k++){
                        var option = document.createElement("option");
                        
                        if(k === -1){ //First option with actual value
                            var id = document.getElementById("id_method").value;
                            var current = ajax_request('methods/get', {id: id});

                            if(!current)
                            {
                                continue;
                            }

                            var option_del = document.createElement("option");
                            option.value = id;
                            option.innerHTML = current.name;
                            option_del.value = '';
                            option_del.disabled = true;
                            option_del.innerHTML = "---------------";
                            
                            select.appendChild(option);
                            select.appendChild(option_del);
                            continue;
                        }
                        
                        option.value = methods[k].id; //ID method
                        option.innerHTML = methods[k].name; //Name method
                        select.appendChild(option);
                    }
                    td.appendChild(select);
                    tr.appendChild(td);
                } 
                    
            }
        }
        
        else if (i == 7) {
            var child = c[i].children;
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                if(j == 0){
                    td.innerHTML = (child[j].innerHTML).trim();
                    tr.appendChild(td);
                }
                else{
                    var select = document.createElement("select");
                    select.className="form-control";
                    select.name = "dataset_publication"
                    
                    for(var k=-1; k < publications.length; k++){
                        var option = document.createElement("option");
                        
                        if(k === -1)
                        { //First option with actual value
                            var id = document.getElementById("idPublication").value;
                            var current = ajax_request('publications/get', {id: id});

                            if(!current)
                            {
                                continue;
                            }

                            var option_del = document.createElement("option");
                            option.value = id;
                            option.innerHTML = current.citation;
                            option_del.value = '';
                            option_del.disabled = true;
                            option_del.innerHTML = "----------------------------------------------";
                            
                            select.appendChild(option);
                            select.appendChild(option_del);
                            continue;
                        }
                        
                        option.value = publications[k].id; //ID publication
                        option.innerHTML = publications[k].citation; //Name publication
                        select.appendChild(option);
                    }
                    td.appendChild(select);
                    tr.appendChild(td);
                } 
                    
            }
        }
        
        else{
            var child = c[i].children;
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                
                if(j == 0){
                    td.innerHTML = (child[j].innerHTML).trim();
                    tr.appendChild(td);
                    continue;
                }
                
                var select = document.createElement("select");
                select.className="form-control";
                select.name = "visibility"
                
                var visibility = document.getElementById("visibility_num").value;
                var values = [visibility, "", 1, 2];
                var text = [visibility === '1' ? "VISIBLE" : "INVISIBLE", "--------", "VISIBLE", "INVISIBLE"];
                
                for(var k=0; k < 4; k++){
                    var option = document.createElement("option");
                    
                    option.value = values[k];
                    option.innerHTML = text[k];
                    if(k == 1)
                        option.disabled = "true";
                    
                    select.appendChild(option);
                }
                td.appendChild(select);
                tr.appendChild(td);
            }
        }
        
        tbody.appendChild(tr);
    }
    
    submit_button.className = "btn btn-sm btn-success pull-right";
    submit_button.innerHTML = "Save";
    submit_button.type = "submit";
    
    new_table.appendChild(tbody);
    new_table.className = 'table';
    form.appendChild(new_table);
    form.appendChild(submit_button);
    
    div.appendChild(form);
}

function edit_basic_transporter_info(edit_btn)
{
    edit_btn.style.visibility = 'hidden';
    var old_table = document.getElementById("basic_info");
    old_table.style.display = 'none';
    var new_table = document.createElement("table");
    var tbody = document.createElement("tbody");
    var div = document.getElementById("table-basic-info-div");
    var c = old_table.rows;
    var count = c.length - 1;
    var publications = get_all_publications();
    var submit_button = document.createElement("button");
    
    var form = document.createElement("form");
    form.setAttribute("method", "post");
    var input = document.createElement("input");
    input.name="spec_type"; input.setAttribute("value","edit_basic"); input.hidden = true;
    form.appendChild(input);
    form.appendChild(document.getElementById("id_dataset"));
    
    for(var i = 0; i < count; i++)
    {
        var tr = document.createElement("tr");
        var child = c[i].children;
        
        if(i == 0)
        {
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                if(j == 1){
                    var input = document.createElement("input");
                    input.name = "dataset_name";
                    input.value = (child[j].innerHTML).trim();
                    input.className = "form-control";
                    td.appendChild(input);
                }
                else
                    td.innerHTML = (child[j].innerHTML).trim();
                tr.appendChild(td);
            }
        }
        
        else if (i < 5){
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                td.innerHTML = (child[j].innerHTML).trim();
                tr.appendChild(td);
            }
        }
        
        
        else if (i == 5) {
            var child = c[i].children;
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                if(j == 0){
                    td.innerHTML = (child[j].innerHTML).trim();
                    tr.appendChild(td);
                }
                else{
                    var select = document.createElement("select");
                    select.className="form-control";
                    select.name = "dataset_publication"
                    
                    for(var k=-1; k < publications.length; k++){
                        var option = document.createElement("option");
                        
                        if(k === -1)
                        { //First option with actual value
                            var id = document.getElementById("idPublication").value;
                            var current = ajax_request('publications/get', {id: id});

                            if(!current)
                            {
                                continue;
                            }

                            var option_del = document.createElement("option");
                            option.value = id;
                            option.innerHTML = current.citation;
                            option_del.value = '';
                            option_del.disabled = true;
                            option_del.innerHTML = "----------------------------------------------";
                            
                            select.appendChild(option);
                            select.appendChild(option_del);
                            continue;
                        }
                        
                        option.value = publications[k].id; //ID publication
                        option.innerHTML = publications[k].citation; //Name publication
                        select.appendChild(option);
                    }
                    td.appendChild(select);
                    tr.appendChild(td);
                } 
                    
            }
        }
        
        else{
            var child = c[i].children;
            for(var j = 0; j < child.length; j++){
                var td = document.createElement("td");
                
                if(j == 0){
                    td.innerHTML = (child[j].innerHTML).trim();
                    tr.appendChild(td);
                    continue;
                }
                
                var select = document.createElement("select");
                select.className="form-control";
                select.name = "visibility"
                
                var visibility = document.getElementById("visibility_num").value;
                var values = [visibility, "", 1, 2];
                var text = [visibility === '1' ? "VISIBLE" : "INVISIBLE", "--------", "VISIBLE", "INVISIBLE"];
                
                for(var k=0; k < 4; k++){
                    var option = document.createElement("option");
                    
                    option.value = values[k];
                    option.innerHTML = text[k];
                    if(k == 1)
                        option.disabled = "true";
                    
                    select.appendChild(option);
                }
                td.appendChild(select);
                tr.appendChild(td);
            }
        }
        
        tbody.appendChild(tr);
    }
    
    submit_button.className = "btn btn-sm btn-success pull-right";
    submit_button.innerHTML = "Save";
    submit_button.type = "submit";
    
    new_table.appendChild(tbody);
    new_table.className = 'table';
    form.appendChild(new_table);
    form.appendChild(submit_button);
    
    div.appendChild(form);
}

function modal_editor(id_row, active_interactions = false)
{
    if(active_interactions)
    {
        var values = [];
        
        values = ajax_request("comparator/getActiveInteraction", {id: id_row});

        if(!values)
        {
            return;
        }

        // Fill the form
        $('#id_interaction').val(id_row);
        $('#substance_name').val(values.substance.name);
        $('#SMILES').val(values.substance.SMILES);
        $('#LogP').val(values.substance.LogP);
        $('#pubchem').val(values.substance.pubchem);
        $('#pdb').val(values.substance.pdb);
        $('#drugbank').val(values.substance.drugbank);
        $('#chEMBL').val(values.substance.chEMBL);
        $('#chEBI').val(values.substance.chEBI);
        $('#Km').val(values.Km);
        $('#Km_acc').val(values.Km_acc);
        $('#EC50').val(values.EC50);
        $('#EC50_acc').val(values.EC50_acc);
        $('#Ki').val(values.Ki);
        $('#Ki_acc').val(values.Ki_acc);
        $('#IC50').val(values.IC50);
        $('#IC50_acc').val(values.IC50_acc);
        $('#reference').html(values.reference);
        $('#uploaded').html(values.uploaded);

        $('#note').val(values.comment);

        $('#type option').removeAttr('selected');
        $('#type option[value="' + values.type + '"]').attr("selected", "selected");

        $('#target option').removeAttr('selected');
        $('#target option[value="' + values.target_id + '"]').attr("selected", "selected");
    }
    else
    {
        var values = [];
        
        values = ajax_request("comparator/getInteraction", {id: id_row});

        if(!values)
        {
            return;
        }

        // Fill the form
        $('#id_interaction').val(id_row);
        $('#substance_name').val(values.substance.name);
        $('#SMILES').val(values.substance.SMILES);
        $('#charge').val(values.charge);
        $('#LogP').val(values.substance.LogP);
        $('#temperature').val(values.temperature);
        $('#pubchem').val(values.substance.pubchem);
        $('#pdb').val(values.substance.pdb);
        $('#drugbank').val(values.substance.drugbank);
        $('#chEMBL').val(values.substance.chEMBL);
        $('#chEBI').val(values.substance.chEBI);
        $('#Position').val(values.Position);
        $('#LogK').val(values.LogK);
        $('#Water').val(values.Water);
        $('#Penetration').val(values.Penetration);
        $('#LogPerm').val(values.LogPerm);
        $('#Position_acc').val(values.Position_acc);
        $('#Water_acc').val(values.Water_acc);
        $('#LogK_acc').val(values.LogK_acc);
        $('#Penetration_acc').val(values.Penetration_acc);
        $('#LogPerm_acc').val(values.LogPerm_acc);
        $('#theta').val(values.theta);
        $('#abs_wl').val(values.abs_wl);
        $('#fluo_wl').val(values.fluo_wl);
        $('#QY').val(values.QY);
        $('#lt').val(values.lt);
        $('#theta_acc').val(values.theta_acc);
        $('#abs_wl_acc').val(values.abs_wl_acc);
        $('#fluo_wl_acc').val(values.fluo_wl_acc);
        $('#QY_acc').val(values.QY_acc);
        $('#lt_acc').val(values.lt_acc);
        $('#membrane_name').html(values.membrane_name);
        $('#method_name').html(values.method_name);
        $('#reference').html(values.reference);
        $('#last_update').html(values.last_update);
        $('#uploaded').html(values.uploaded);
    }

    modal.style.display = "block";
}


function delete_dataset(id)
{
    return alert_window("Are you sure you want to delete the dataset (ID = " + id + ")? <h5 style='color: red;'>This option removes any interactions associated with the dataset.<h5>", "delete/interaction/2/" + id + "?redirection=edit/dsInteractions"); 
}

function delete_transporter_dataset(id)
{
    return alert_window("Are you sure you want to delete the dataset (ID = " + id + ")? <h5 style='color: red;'>This option removes any transporters associated with the dataset.<h5>", "delete/transporter/2/" + id + "?redirection=edit/dsTransporters"); 
}

function delete_interaction(idDataset, id)
{
    return alert_window("Are you sure you want to delete the interaction (ID = " + id + ")? <h5 style='color: orange;'>This option will not remove compound detail from the database.<h5>", "delete/interaction/1/" + id + "?redirection=edit/dsInteractions/" + idDataset); 
}

function delete_transporter(idDataset, id)
{
    return alert_window("Are you sure you want to delete the transporter detail (ID = " + id + ")? <h5 style='color: orange;'>This option will not remove compound detail from the database.<h5>", "delete/transporter/1/" + id + "?redirection=edit/dsTransporters/" + idDataset); 
}