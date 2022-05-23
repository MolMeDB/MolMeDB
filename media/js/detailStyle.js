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
}

acc[0].click();


/**
 * Shows checked rows in table
 * 
 * @param {integer} id - Substance ID 
 * @param {integer} idButton - ID of button just clicked
 */
 async function showRows(element, id)
 {
    var button = element;
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
    showRows(null, id);
});


/**
 * Loads table with interactions
 * 
 * @param {*} id Substance ID
 */
async function loadTable(id)
{
    var div = document.getElementById("detailTab");
    div.innerHTML = "";

    var hide_element = $('#hide-empty-interaction-columns');
    var hide_empty = false;

    if($(hide_element).is(':checked'))
    {
        hide_empty = true;
    }
    
    var checked = [];

    $('button[id^=btn_meth_]').each(function(i, el)
    {
        if(!$(el).hasClass('btn-methods-active'))
        {
            return;
        }

        var id = $(el).attr('id');
        id = id.replace('btn_meth_', '');

        if(id)
        {
            checked.push(id);
        }
    });

    // If not checked, set nonsense value for getting empty table
    if(!checked[0])
    {
        checked = [-1];
    }

    // // Get all interactions - returns HTML table
    var content = ajax_request('interactions/all/passive', {id_compound:id, id_method: checked}, "GET", 'html');
    append_html_js(content, div);
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
 * Helper for making <td> with long text field element
 * 
 * @param {string} content 
 */
function make_textfield_td(content)
{
    var td = document.createElement("td");
    var text = content;
    
    if(text == null)
    {
       return td; 
    }
    
    if(text.length > 20)
    {
        td.innerHTML = "<span class='popup-hover'>"  
             + text.substring(0,15) + "..."
             + "<div style='margin-left: -10px;'>"
             + "<p>" + text + "</p>"
             + "</div>"
             + "</span>";
    }
    else
    {
        td.innerHTML = "<p class='attribute'>" + text + "</p>";
    }
    
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
    
    if(idReference == 0 || idReference == "null" || idReference == null)
    {
        return td;
    }

    if(ref.length <= 10)
    {
        td.innerHTML = ref;
    }
    else
    {
        td.innerHTML = '<div class="popup" onclick="show_popup(\'' + ref_iter + '\')">' + idReference
            + '<span class="popuptext" id="ref_popup_' + ref_iter + '">' + ref + '</span></div>';
    }

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
    rows[0] = ['Membrane', 'Method', 'Note', 'Q', 'Temperature', 'LogP', 'X_min', 'X_min_+/-', 'G_pen', 'G_pen_+/-', 'G_wat', 'G_wat_+/-', 'LogK', 'LogK_+/-',
        'LogPerm', 'LogPerm_+/-', 'Theta', 'Theta_+/-', 'Abs_wl', 'Abs_wl_+/-', 'Fluo_wl', 'Fluo_wl_+/-','QY', 'QY_+/-', 'lt', 'lt_+/-', 'Primary_reference', 'Secondary_reference'];
    
    detail = ajax_request("interactions/all/passive", {idCompound: id}, "GET");

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
                record.comment,
                record.charge,
                record.temperature,
                record.substance.LogP,
                record.Position ,
                record.Position_acc, 
                record.Penetration ,
                record.Penetration_acc, 
                record.Water, 
                record.Water_acc, 
                record.LogK, 
                record.LogK_acc, 
                record.LogPerm, 
                record.LogPerm_acc, 
                record.theta, 
                record.theta_acc, 
                record.abs_wl, 
                record.abs_wl_acc, 
                record.fluo_wl, 
                record.fluo_wl_acc, 
                record.QY, 
                record.QY_acc, 
                record.lt, 
                record.lt_acc, 
                record.primary_reference, 
                record.secondary_reference 
            ];

            row++;
        }
    }

    var d = date.getDate() + "." + (date.getMonth()+1) + "." + date.getFullYear();
    exportToCsv(name + '_' + d + '.csv', rows, false);
}