/**
 * Accordion function
 */
var acc = document.getElementsByClassName("accordion");
var i;

for (i = 0; i < acc.length; i++) {
  acc[i].onclick = function() {
    this.classList.toggle("active");
    var panel = this.nextElementSibling;
    if (panel.style.maxHeight){
      panel.style.maxHeight = null;
      panel.style.minHeight = null;
    } else {
      panel.style.maxHeight = panel.scrollHeight + "px";
      panel.style.minHeight = "250px";
    } 
  }
  acc[0].click();
}


/**
 * Checks, for which methods are available interactions
 * 
 * @param {integer} id - Substance ID 
 */
function checkMethods(id)
{
    var params = {
        id: id
    };
    
    var result = ajax_request('detail/checkMethods', params);

    if(!result)
    {
        return;
    }
    
    // Parse data
    var data = result;
    var size = data.length;
    
    if(!size){
        return;
    }
    
    var t = 0;
    
    for(var j=0; j<size; j++)
    {
        var btn = document.getElementById('but' + data[j].id_method);
        btn.setAttribute("style", "display: inline-block");
        t++;
        btn.setAttribute("onclick", "showRows('" + id + "','but" + data[j].id_method +"');");
        btn.setAttribute("class", "btn-methods");
    }     
    
    if(t === 0)
    {
        document.getElementById("interaction-panel").style.display = "none";
        document.getElementById("no-data-panel").style.display = "block";
    }
    else{ 
        acc[1].click();
    }
}

    
/**
 * Shows checked rows in table
 * 
 * @param {integer} id - Substance ID 
 * @param {integer} idButton - ID of button just clicked
 */
 async function showRows(id, idButton)
 {
    var button = document.getElementById(idButton);
    var loader = document.getElementById("interaction_loader");
    loader.style.display = "block";
    await sleep(100);
    
    if(button)
    {
        if(button.value == "1"){
            button.value = "0";
            button.setAttribute("class", "btn-methods");
        }
        else{
            button.value = "1";
            button.setAttribute("class", "btn-methods-active");
        }
    }
    
    var panel = document.getElementById("accordionInteraction");
    var height = panel.scrollHeight + 500; 
    panel.style.maxHeight = height + "px";
    

    loadTable(id);
    loader.style.display = "none";
}


/**
 * Callback for reaload table if checkbox state is changed
 */
$('#hide-empty-interaction-columns').change(function()
{
    var id = $('#idSubstance').val();
    showRows(id, null);
});


/**
 * Loads table with interactions
 * 
 * @param {*} id Substance ID
 */
function loadTable(id)
{
    var div = document.getElementById("detailTab");
    if(div.children[0])
    {
        div.children[0].remove();
    }
    var hide_element = $('#hide-empty-interaction-columns');
    var hide_empty = false;

    if($(hide_element).is(':checked'))
    {
        hide_empty = true;
    }
    
    var table = document.createElement("table");
    div.setAttribute("class", "detail-content");
    var thead = document.createElement("thead");
    var tbody = document.createElement("tbody");
    thead.setAttribute("class", "thead-detail");
    var attributes = ["Membrane", "Method", "Q", 'T <br/><label class="units">[°C]</label>', 'X<sub>min</sub> <br/><label class="units">[nm]</label>', 
                      '&Delta;G<sub>pen</sub> <br/><label class="units">[kcal / mol]</label>', '&Delta;G<sub>wat</sub> <br/><label class="units">[kcal / mol]</label>', 
                      'LogK<sub>m</sub> <br/><label class="units">[mol<sub>m</sub>/mol<sub>w</sub></label>]', 'LogPerm <br/><label class="units">[cm/s]</label>', 
                      'Theta<br/><label class="units">[°]</label>','Abs_wl<br/><label class="units">[nm]</label>', 'Fluo_wl<br/><label class="units">[nm]</label>',
                      'QY<br/><label class="units"></label>', 'lt<br/><label class="units">[ns]</label>', 'Publication'];
    
    var methods = get_all_methods();
    var countMet = methods.length;
    var checked = [];
    
    // Make table header
    for(var i=0; i<attributes.length; i++)
    {
        var td = document.createElement("td");
        td.innerHTML = "<p><b>" + attributes[i] + "</b></p>";
        thead.appendChild(td);
    }

    // Get checked methods
    for(var j=0; j<countMet; j++)
    {
        var btn = document.getElementById("but" + methods[j].id);
        
        if(btn.value != "1")
            continue;

        checked.push(methods[j].id);
    }

    // If not checked, set nonsense value for getting empty table
    if(!checked[0])
    {
        checked = [-1];
    }

    // Get all interactions
    var data = ajax_request('detail/getInteractions', { id: id, idMethods: checked}, "POST");

    if(!data)
    {
        return;
    }

    var membrane_count = data.length;

    for (var i = 0; i < membrane_count; i++) 
    {
        var mem_detail = data[i];
        var mem_id = mem_detail.membrane_id;
        var mem_name = mem_detail.membrane_name;

        var rows = mem_detail.data;
        var row_count = rows.length;

        var first_row = true;

        for (var j = 0; j < row_count; j++) 
        {
            var interaction = rows[j];

            var tr = document.createElement("tr");
            var td = document.createElement("td");
            td.innerHTML = mem_name;
            if (first_row) 
            {
                td.setAttribute("rowspan", row_count);
                td.setAttribute("class", "first-td text-primary");
                first_row = false;
            }
            else
            {
                td.hidden = true;
            }
        
            tr.appendChild(td);
            td = document.createElement("td");
            td.innerHTML = interaction.method_name;
            tr.appendChild(td);

            //Fill table
            var charge = make_td(interaction.charge, true);
            var temp = make_td(interaction.temperature, true);
            var x_min = make_td(interaction.Position, false, interaction.Position_acc);
            var g_pen = make_td(interaction.Penetration, false, interaction.Penetration_acc);
            var g_wat = make_td(interaction.Water, false, interaction.Water_acc);
            var log_k = make_td(interaction.LogK, false, interaction.LogK_acc);
            var log_perm = make_td(interaction.LogPerm, false, interaction.LogPerm_acc);
            var theta = make_td(interaction.theta, false, interaction.theta_acc);
            var abs_wl = make_td(interaction.abs_wl, false, interaction.abs_wl_acc);
            var fluo_wl = make_td(interaction.fluo_wl, false, interaction.fluo_wl_acc);
            var QY = make_td(interaction.QY, false, interaction.QY_acc);
            var lt = make_td(interaction.lt, false, interaction.lt_acc);
            var ref = make_ref_td(interaction.id_reference, interaction.reference);

            tr.appendChild(charge);
            tr.appendChild(temp);
            tr.appendChild(x_min);
            tr.appendChild(g_pen);
            tr.appendChild(g_wat);
            tr.appendChild(log_k);
            tr.appendChild(log_perm);
            tr.appendChild(theta);
            tr.appendChild(abs_wl);
            tr.appendChild(fluo_wl);
            tr.appendChild(QY);
            tr.appendChild(lt);
            tr.appendChild(ref);

            tbody.appendChild(tr);
        }
    }
    
    table.appendChild(thead);
    table.appendChild(tbody);
    div.appendChild(table);

    // Process table
    var deleted = 0;

    if(hide_empty)
    {
        $(thead).find('td').each(function (key, th) {
            var is_empty = true;

            $(tbody).find('tr>td:nth-child(' + (key + 1 - deleted) + ')').each(function(k, td)
            {
                var val = $(td).html();

                if(!(val == '' || !val))
                {
                    is_empty = false;
                    return false;
                }
            })

            if(is_empty)
            {
                $(tbody).find('tr>td:nth-child(' + (key + 1 - deleted) + ')').each(function (k, td) {
                    $(td).remove();
                })

                // Hide header
                $(th).remove();

                deleted++;
            }
        });
    }
}

/**
 * Helper for making <td> element
 * 
 * @param {string} content 
 * @param {string} nobreak 
 * @param {string} acc - Approximation value 
 */
function make_td(content, nobreak = false, acc = false)
{
    var td = document.createElement("td");
    var text = content;
    
    if(text == null)
    {
       return td; 
    }
    
    if(nobreak)
    {
        text = '<span class="nobreak">' + text + '</span>';
    }
    
    if(!acc)
    {
        td.innerHTML = text;
        return td;
    }
    
    td.innerHTML = "<p class='attribute'>" + text + "</p>";
    td.innerHTML += "<p class='accuracy'><b>+/- " + acc + "</b></p>";
    
    return td;
}


/**
 * Makes reference <td> with whisper
 * 
 * @param {integer} idReference
 * @param {string} ref
 */
ref_iter = 0;
function make_ref_td(idReference, ref)
{
    var td = document.createElement("td");
    
    if(idReference == 0)
    {
        return td;
    }
    
    td.innerHTML = '<div class="popup" onclick="show_popup(\'' + ref_iter + '\')">' + idReference
            + '<span class="popuptext" id="ref_popup_' + ref_iter + '">' + ref + '</span></div>';
    
    ref_iter++;
    
    return td;
}

/**
 * Shows whisper popout
 * @param {integer} id 
 */
function show_popup(id)
{
    var popup = document.getElementById("ref_popup_" + id);
    popup.classList.toggle("show");
}

/**
 * Export function for exporting table of interactions
 * @param {string} name 
 */
function export_detail_data(name)
{
    var detail;
    var id = document.getElementById("idSubstance").value; 
    var rows = [];
    var date = new Date();
    rows[0] = ['Membrane', 'Method', 'Q', 'Temperature', 'X_min', 'X_min_+/-', 'G_pen', 'G_pen_+/-', 'G_wat', 'G_wat_+/-', 'LogK', 'LogK_+/-',
        'LogPerm', 'LogPerm_+/-', 'LogP', 'Theta', 'Theta_+/-', 'Abs_wl', 'Abs_wl_+/-, Fluo_wl', 'Fluo_wl_+/-','QY', 'QY_+/-', 'lt', 'lt_+/-', 'Publication'];
    
    detail = ajax_request("detail/getInteractions", {id: id, idMethods: 0, idMembranes: 0}, "POST");
    
    if(!detail)
    {
        return;
    }
    
    var count = detail.length;
    var row = 1;

    for(var i = 0; i<count; i++)
    {
        var mem_interactions = detail[i].data;
        var count_ints = mem_interactions.length;
        
        for(var j = 0; j < count_ints; j++)
        {
            var record = mem_interactions[j];
            rows[row] = [
                record.membrane_name,
                record.method_name,
                record.charge ? record.charge : "",
                record.temperature ? record.temperature : "",
                record.Position ? record.Position : "",
                record.Position_acc ? record.Position_acc : "",
                record.Penetration ? record.Penetration : "",
                record.Penetration_acc ? record.Penetration_acc : "",
                record.Water ? record.Water : "",
                record.Water_acc ? record.Water_acc : "",
                record.LogK ? record.LogK : "",
                record.LogK_acc ? record.LogK_acc : "",
                record.LogPerm ? record.LogPerm : "",
                record.LogPerm_acc ? record.LogPerm_acc : "",
                record.LogP ? record.LogP : "",
                record.theta ? record.theta : "",
                record.theta_acc ? record.theta_acc : "",
                record.abs_wl ? record.abs_wl : "",
                record.abs_wl_acc ? record.abs_wl_acc : "",
                record.fluo_wl ? record.fluo_wl : "",
                record.fluo_wl_acc ? record.fluo_wl_acc : "",
                record.QY ? record.QY : "",
                record.QY_acc ? record.QY_acc : "",
                record.lt ? record.lt : "",
                record.lt_acc ? record.lt_acc : "",
                record.reference ? record.reference : ""
            ];

            row++;
        }
    }

    var d = date.getMonth()+1 + "." + date.getDate() + "." + date.getFullYear();
    exportToCsv(name + '_' + d + '.csv', rows, false);
}