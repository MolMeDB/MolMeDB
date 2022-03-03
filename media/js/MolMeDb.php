<?php

if(FALSE): ?> <script> <?php endif?>

var detail;
var membranes;
var methods;
var protocol = "<?=$_SERVER['SERVER_PROTOCOL'] ?>";
protocol = protocol.startsWith('HTTPS') ? 'https://' : 'http://';
var url_prefix = protocol + "<?= $_SERVER['HTTP_HOST'] ?>";

/**
 * Creates new message span
 * 
 * @param {string} message - Message text
 * @param {string} type - success/warning/danger
 */
function add_message(message, type="success")
{
    var valid_types = ['success', 'warning', 'danger'];
    // Checks valid message type
    if(!valid_types.includes(type))
    {
        console.log('Wrong message type.');
        return;
    }

    var target = $("#alert-column");
        
    var res = '<div class="alert alert-' + type + ' alert dismissable fade in" >'
            + '<a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>'
            + message
            + '</div>';
    
    $(target).append(res);
}

/**
 * Ajax request function
 * Sends request to given uri
 * 
 * @param {string} uri
 * @param {object} params
 * @param {string} method - GET/POST only
 */
function ajax_request(uri, params, method = "GET", required_content_type = "json")
{
    method = method.toUpperCase();
    var valid_methods = ['GET', 'POST'];

    switch(required_content_type.toLowerCase())
    {
        case 'html':
            accept = 'text/html';
            break;

        default:
            accept = 'application/json';
    }

    if(!valid_methods.includes(method))
    {
        console.log('Wrong method type for ajax request.');
        return false;
    }

    // Make uri
    uri = url_prefix + "/api/" + uri;
    uri = uri.replace(/\&+$/, '');
    
    var result;

    // Send request
    $.ajax({
        url: uri,
        contentType: 'application/json',
        accepts: accept,
        type: method,
        headers:{
            "Authorization": "Basic " + $('#api_internal_token').val(),
        },
        async: false,
        data: params,
        success: function(data)
        {
            if(data)
            {
                result = data;
            }
            else
            {
                result = null;
            }
        },
        error: function(data)
        {
            add_message("Error ("+ data.status + ") ocurred during ajax request.", "danger");
            console.log(data);
            result = false;
        }
    });
    
    return result;
}

var show_overlay;
var hide_overlay;
var set_overlay_text;

/**** SET FULLSCREEN OVERLAY ****/
$(document).ready(function()
{
    var overlay = $('#fs-overlay');
    var overlay_text = $('#fs-overlay-text');

    /**
     * Displays overlay
     */
    show_overlay = async function()
    {
        $(overlay).show();
        await sleep(10);
    }

    /**
     * Hides overlay
     */
    hide_overlay = async function()
    {
        $(overlay_text).html('');
        $(overlay).hide();
        await sleep(10);
    }

    /**
     * Set overlay text
     * 
     * @param {string} text
     */
    set_overlay_text = async function(text)
    {
        text = text.trim();
        $(overlay_text).html(text);

        // Set position to the middle
        var width = $(overlay_text).width();
        var parent = $(overlay_text).parent();
        var margin = (width/2) * -1;

        $(parent).css('margin-left', margin);

        await sleep(10);
    }
})

/**
 * Appends html content to the given parent
 * + init all <script> contents
 * + executes functions given in js_callbacks
 */
function append_html_js(content, parent, js_callbacks = ['init'])
{
    if(!parent)
    {
        return;
    }

    $(parent).append(content);
    $(parent).find('script').each(function(i,e)
    {
        eval($(e).html());
        js_callbacks.forEach((fun, i) => {
            eval(fun+"()");
        });
    });
}

// /**
//  * 
//  */
// function browserSetActive(id)
// {
//     var x = document.getElementById(id);

//     if(x.classList.contains('browse-section-active')){
//         x.classList.remove('browse-section-active');
//         x.classList.add('non-active');
//     }
//     else{
//         x.classList.remove('non-active');
//         x.classList.add('browse-section-active');
//     }
// }

// function showBrowseContent(contentId){
//     var x = document.getElementById('content' + contentId);
//     var btn = document.getElementById('descBtn' + contentId);
    
//     if (x.style.display == 'none'){
//         x.style.display = 'block';
//         btn.classList.add('active');
//     }
//     else{
//         x.style.display = 'none';
//         btn.classList.remove('active');
//     }
// }


/**
 * Adds substances from dataset to comparator list 
 * @param {string} type 
 * @param {integer} id 
 */
function addSetToComparator(type, id)
{
    var ligands = ajax_request('compounds/ids/byPassiveInteractionType', {type: type, id: id});

    if(ligands === false)
    {
        add_message('Compounds were not added to the comparator list.', 'danger');
        return;
    }

    var count = ligands ? ligands.length : 0;

    for(var i = 0; i<count; i++)
    {
        var ID = ligands[i].id;
        var name = ligands[i].name;        
        
        add_to_comparator(ID, name);
    }

    if(!count)
    {
        alert("No compounds available.");
    }
    else
    {
        alert("Compounds [" + count + "] added to comparator list.");
    }   
}

/** 
* Sends request to MolMeDB server and returns canonized smiles
*
* @param {string} smiles
* @param {string} taget_input_id
*
*/
function canonize_smiles(smiles, target_input_id)
{
    if(smiles == '')
    {
        return;
    }

    var target_element = document.getElementById(target_input_id);

    var result = ajax_request('smiles/canonize', {smiles: smiles}, 'GET');

    if(!target_element || result === false || !result.canonized)
    {
        add_message('Cannot update smiles.', 'danger');
        return;
    }

    target_element.setAttribute('value', result.canonized);

    return;
}

/** w
* Sends request to MolMeDB server and 
* returns publication info from remote servers
*
* @param {string} input_id - ID of input with given DOI
*
*/
function get_reference_by_doi(input_id)
{
    var doi = document.getElementById(input_id).value;

    if(!doi || doi == '')
    {
        add_message("Invalid DOI.", "danger");
        return;
    }

    var result = ajax_request('publications/find/remote', {doi: doi});

    if(result === false)
    {
        add_message('Cannot download publication info.', 'danger');
        return;
    }

    if(!result)
    {
        add_message('Publication for given DOI was not found.', 'warning');
        return;
    }

    $('#authors').val(result.authors);
    $('#pmid').val(result.pmid);
    $('#title').val(result.title);
    $('#journal').val(result.journal);
    $('#volume').val(result.volume);
    $('#issue').val(result.issue);
    $('#page').val(result.pages);
    $('#year').val(result.year);
    $('#publicated_date').val(result.publicatedDate);

    // Generate citation
    var citation = result.authors + ": " + result.title.trim() + " ";

    if(result.journal)
    {
        citation = citation + result.journal + ", ";

        if(result.volume)
        {
            citation = citation + "Volume " + result.volume;

            if(result.issue)
            {
                citation = citation + " (" + result.issue + ")";
            }

            citation = citation + ", ";
        }

        if(result.pages)
        {
            citation = citation + result.pages + ", ";
        }
    }

    citation = citation + result.year;

    citation = citation.trim();

    $('#citation').val(citation);

    return;
}


/**
 * Downloads dataset for given reference ID
 * @param {integer} idRef - Reference ID
 */
function download_dataset_byRef(idRef)
{
    // Header of csv file
    var spans = [
        "Name",
        'Identifier', 
        'Pubchem', 
        'Drugbank', 
        "SMILES",  
        "Membrane", 
        "Method", 
        "LogP", 
        "MW", 
        "Charge", 
        "Temperature [°C]", 
        "Note", 
        "X_min [nm]", 
        "+/-_X_min", 
        "LogK [mol_m/mol_w]", 
        "+/-_LogK", 
        "G_wat", 
        "+/-_G_wat [kcal/mol]", 
        "G_pen [kcal/mol]", 
        "+/-_G_pen", 
        "LogPerm [cm/s]", 
        "+/-_LogPerm", 
        "primary_reference",
        "secondary_reference"
    ];
           
    var data = [];
     
    // Get data
    data = ajax_request("interactions/all/passive", {id_reference: idRef});

    if(!data)
    {
        add_message('Problem with getting data from server.', 'danger');
        return;
    }
    
    var count = data.length;
    
    if(count < 1)
    {
        alert("No compounds available.");
        return;
    }
    
    var export_data = [];
    
    // FILL HEADER
    export_data[0] = [];
    for(var i = 0; i < spans.length; i++)
    {
        export_data[0][i] = spans[i];
    }
    
    // FILL BODY
    for(var i = 0; i<count; i++){
        export_data[i+1] = 
        [
            data[i].name,
            data[i].identifier,
            data[i].pubchem,
            data[i].drugbank,
            data[i].SMILES,
            data[i].membrane,
            data[i].method,
            data[i].LogP,
            data[i].MW,
            data[i].charge,
            data[i].temperature,
            data[i].comment,
            data[i].Position,
            data[i].Position_acc,
            data[i].LogK,
            data[i].LogK_acc,
            data[i].Water,
            data[i].Water_acc,
            data[i].Penetration,
            data[i].Penetration_acc,
            data[i].LogPerm,
            data[i].LogPerm_acc,
            data[i].publication,
            data[i].secondary_publication,
        ];
        
    }
    
    exportToCsv('Dataset.csv', export_data);
}

/**
 * Async function for JS delay
 * 
 * @param {integer} ms - Time in [ms] for delay
 */
function sleep(ms) 
{
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Function for generating CSV file
 * 
 * @param {string} filename - Name of exported file
 * @param {array} rows - CSV file data
 * 
 */
function exportToCsv(filename, rows) 
{
    if(rows.length == 0){
        return false;
    }

    var processRow = function (row) {
        var finalVal = '';
        for (var j = 0; j < row.length; j++) {
            if (row[j] == 'undefined' || row[j] == null)
            {
                row[j] = '';
            }
            var innerValue = row[j] === null ? '' : row[j].toString();
            if (row[j] instanceof Date) {
                innerValue = row[j].toLocaleString();
            };
            var result = innerValue.replace(/"/g, '""');
            if (result.search(/("|,|\n)/g) >= 0)
                result = '"' + result + '"';
            if (j > 0)
                finalVal += ';';
            finalVal += result;
        }
        return finalVal + '\n';
    };

    var csvFile = '';
    for (var i = 0; i < rows.length; i++) {
        csvFile += processRow(rows[i]);
    }

    var blob = new Blob([csvFile], { type: 'text/csv;charset=utf-8;' });
    if (navigator.msSaveBlob) { // IE 10+
        navigator.msSaveBlob(blob, filename);
    } else {
        var link = document.createElement("a");
        if (link.download !== undefined) { // feature detection
            // Browsers that support HTML5 download attribute
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
}

/**
 * Shows/hides alert window
 * 
 * @param {string} message - Text of alert message
 * @param {string} address - Address, where to redirect by click to YES
 * @param {boolean} dismiss - Hide alert box? 
 */
function alert_window(message, address, dismiss = false)
{
    var alert_box = document.getElementById("modal_alert_link");
    
    if(dismiss)
    {
        alert_box.style.display = 'none';
        alert_box.innerHTML = '';
        return;
    }
    var div_header = document.createElement("div");
    div_header.setAttribute("class", "header");
    div_header.innerHTML = "Alert";
    
    var text_div = document.createElement("div");
    text_div.className = 'text';
    text_div.innerHTML = message;
    
    var div_btns = document.createElement("div");
    div_btns.setAttribute("class", "buttons");
    
    var btn_NO = document.createElement("button");
    btn_NO.setAttribute("class", "btn btn-danger");
    btn_NO.innerHTML = "No";
    btn_NO.setAttribute("onclick","alert_window('', '', true)");
    
    var btn_YES = document.createElement("button");
    btn_YES.setAttribute("class", "btn btn-success");
    btn_YES.innerHTML = "Yes";
    btn_YES.setAttribute("onclick", "redirect('" + address + "')");
    
    alert_box.appendChild(div_header);
    alert_box.appendChild(text_div);
    div_btns.appendChild(btn_NO);
    div_btns.appendChild(btn_YES);
    alert_box.appendChild(div_btns);
    
    alert_box.style.display = 'block';
}

/**
 * Redirection to given address
 * 
 * @param {string} path 
 * @param {object} params - Optional
 * @param {string} method - Optional, POST/GET. Default is GET 
 */
function redirect(path, params = {}, method = 'GET') 
{
    var valid_methods = ['POST', 'GET'];

    path = url_prefix + "/" + path.trim("/");

    method = method.toUpperCase();
    method = valid_methods.includes(method) ? method : "GET"; // Set method to get by default if not specified.

    if(method === 'GET' && jQuery.isEmptyObject(params))
    {
        window.location.href = path;
        return;
    }

    // The rest of this code assumes you are not using a library.
    // It can be made less wordy if you use one.
    var form = document.createElement("form");
    form.setAttribute("method", method);
    form.setAttribute("action", path);

    for(var key in params) {
        if(params.hasOwnProperty(key)) {
            var hiddenField = document.createElement("input");
            hiddenField.setAttribute("type", "hidden");
            hiddenField.setAttribute("name", key);
            hiddenField.setAttribute("value", params[key]);

            form.appendChild(hiddenField);
        }
    }

    document.body.appendChild(form);
    form.submit();
}

/**
 * Gets all membranes
 * 
 * @return {array}
 */
function get_all_membranes()
{
    var result = ajax_request("membranes/get");

    if(result === false)
        return [];

    return result;
}

/**
 * Gets all methods
 * 
 * @return {array}
 */
function get_all_methods()
{
    var result = ajax_request("methods/all");

    if(result === false)
        return [];

    return result;
}

/**
 * Gets all publications
 * 
 * @return {array}
 */
function get_all_publications()
{
    var result = ajax_request("publications/all");

    if(result === false)
        return [];
    
    return result;
}

/**** SHOWS MODAL ****/




/**** COMPARATOR LIST FUNCTIONS ****/

var COMP_PREFIX = "COMP_";

/**
 * Returns localstorage attribute for comparator list
 * 
 * @param {integer} id 
 */
function get_attr(id)
{
    return COMP_PREFIX + id;
}

/**
 * Return id from attr
 * 
 * @param {string} attr 
 * 
 * @return {integer}
 */
function get_substance_id(attr)
{
    if(!attr)
    {
        return null;
    }

    var is_a_num = new RegExp("^[0-9]*$");

    if (is_a_num.test(attr))
    {
        return attr;
    }

    attr = attr.toString();

    if(attr.startsWith(COMP_PREFIX))
    {
        return attr.substr((COMP_PREFIX.length));
    }

    if(attr.startsWith('search_' + COMP_PREFIX))
    {
        var str = 'search_' + COMP_PREFIX;
        return attr.substr((str.length));
    }

    if(attr.startsWith('detail_' + COMP_PREFIX))
    {
        var str = 'detail_' + COMP_PREFIX;
        return attr.substr((str.length));
    }

    return null;
}

/**
 * Returns search list item ID
 * 
 * @param {integer} id
 */
function get_search_list_id(id)
{
    return 'search_' + get_attr(id);
}

/**
 * Returns id of compound item
 * in comparator detail 
 * 
 * @param {integer} id 
 */
function get_comparator_detail_id(id)
{
    return 'detail_' + get_attr(id);
}

/**
 * Makes comparator list to menu 
 */
function make_comparator_list()
{
    // Get all 
    var keys = Object.keys(localStorage), i = keys.length;

    while (i--) 
    {
        var id = get_substance_id(keys[i]);

        if(!id)
        {
            continue;
        }

        var attr = keys[i];

        var name = localStorage.getItem(keys[i]);

        // Add to comparator list
        var ul = document.getElementById("comparator-ul");
        var div = document.createElement("div");
        var text = document.createElement("span");
        var func = "remove_from_comparator('" + attr + "')";
        var span = document.createElement("span");

        text.innerHTML = name;
        span.classList.add("glyphicon");
        span.classList.add("glyphicon-remove");
        span.classList.add("comp-list-remove");
        div.appendChild(span);
        div.appendChild(text);
        div.setAttribute("id", attr);
        div.setAttribute("onclick", func)
        div.classList.add('dropdown-text');
        ul.appendChild(div);

        // Toggle search list items
        var search_list_item = document.getElementById(get_search_list_id(id));

        if(search_list_item)
        {
            var span = search_list_item.children[0];

            // Change icon and color
            search_list_item.classList.toggle("btn-primary");
            search_list_item.classList.toggle("btn-danger");
            span.classList.toggle("glyphicon-plus");
            span.classList.toggle("glyphicon-minus");
        }
    }
}

/**
 * Adds new record to comparator list 
 * 
 * @param {integer} id  - Compound ID
 * @param {string} name - Compound name
 */
function add_to_comparator(id, name)
{
    var attr = get_attr(id);
    var comparator_detail_id = get_comparator_detail_id(id);
    var search_list_item = document.getElementById(get_search_list_id(id));

    // Checks if object is already stored
    if (is_in_comparator(id))
    {
        return true;
    }

    // Store new record
    localStorage.setItem(attr, name);

    // Add to comparator list
    var ul = document.getElementById("comparator-ul");
    var div = document.createElement("div");
    var func = "remove_from_comparator('" + attr + "')";
    var span = document.createElement("span");
    span.classList.add("glyphicon");
    span.classList.add("glyphicon-remove");
    span.classList.add("comp-list-remove");
    div.appendChild(span);
    div.appendChild(document.createTextNode(name));
    div.setAttribute("id", attr);
    div.setAttribute("onclick", func)
    div.classList.add('dropdown-text');
    ul.appendChild(div);

    // Toggle in search list if exists
    if(search_list_item)
    {
        var span = search_list_item.children[0];

        // Change icon and color
        search_list_item.classList.toggle("btn-primary");
        search_list_item.classList.toggle("btn-danger");
        span.classList.toggle("glyphicon-plus");
        span.classList.toggle("glyphicon-minus");
    }
}

/**
 * Checks if item with given ID is already in comparator list
 * 
 * @param {integer} id - Compound ID
 * 
 * @return {boolean} 
 */
function is_in_comparator(id)
{
    id = get_substance_id(id);

    var attr = get_attr(id);

    if (localStorage.getItem(attr))
    {
        return true;
    }

    return false;
}

/**
 * Removes compound from comparator list
 * 
 * @param {string} id - Compound ID 
 */
function remove_from_comparator(id)
{
    var id = get_substance_id(id);

    var attr = get_attr(id);
    var btn_search_list_id = get_search_list_id(id);
    var comparator_detail_id = get_comparator_detail_id(id);

    // Remove from comparator list
    var ul = document.getElementById("comparator-ul");
    var li = document.getElementById(attr);

    if(ul && li)
    {
        ul.removeChild(li);
    }

    // Toggle in search list
    var btn = document.getElementById(btn_search_list_id);

    // Remove from comparator detail list
    var comparator_item = document.getElementById(comparator_detail_id);

    if(comparator_item !== null)
    {
        comparator_item.remove();
    }

    if (btn && btn.classList.contains('btn-danger')) 
    {
        var span = btn.children[0];
        btn.classList.toggle("btn-danger"); 
        btn.classList.toggle("btn-primary");
        span.classList.toggle("glyphicon-minus"); 
        span.classList.toggle("glyphicon-plus");
    }

    // Remove from storage
    localStorage.removeItem(attr);
}

/**
 * Removes all from local storage
 */
function flush_comparator_list()
{
    var keys = Object.keys(localStorage), i = keys.length;

    while (i--) 
    {
        var id = get_substance_id(keys[i]);

        if(!id)
        {
            continue;
        }

        remove_from_comparator(id);
    }

    var btn = document.getElementById("btn-addALL");

    if(!btn)
    {
        return;
    }
    
    if (btn.classList.contains("btn-danger")) 
    {
        btn.classList.toggle("btn-danger"); btn.classList.toggle("btn-primary");
        btn.innerHTML = "Add all to comparator";
    }
}


/**
 * Verify paginations under the search lists
 * 
 */
function verifyPagination(id_paginator)
{
    if(!id_paginator)
    {
        id_paginator = 'paginator';
    }

    var paginator = document.getElementById(id_paginator);
    var childs = paginator.children;
    var count = childs.length;
    var active;

    if(count < 6) return;
    
    for(var i=0; i<count; i++)
    {
        if(childs[i].className == 'active')
            active = i;
    }
    
    if(active < 5)
    {
        for(var i = 6; i<count-1; i++){
            childs[i].remove();
            i--;
            count--;
        }
        var element = document.createElement("li");
        element.className = "disabled";
        var a = document.createElement("a");
        a.innerHTML = "...";
        element.appendChild(a);
        paginator.insertBefore(element, childs[childs.length-1]);
    }
    else if(active > count-5)
    {
        for(var i = 1; i<count-6; i++){
            childs[i].remove();
            i--;
            count--;
        }
        var element = document.createElement("li");
        element.className = "disabled";
        var a = document.createElement("a");
        a.innerHTML = "...";
        element.appendChild(a);
        childs[0].after(element);
    }
    else 
    {
        for(var i = 1; i<active-2; i++)
        {
            childs[i].remove();
            i--;
            active--; count--;
        }
        for(var i = active+3; i<count-1; i++)
        {
            childs[i].remove();
            i--;
            active--; count--;
        }
        var element = document.createElement("li");
        element.className = "disabled";
        var a = document.createElement("a");
        a.innerHTML = "...";
        element.appendChild(a);
        childs[0].after(element);
        
        var element = document.createElement("li");
        element.className = "disabled";
        var a = document.createElement("a");
        a.innerHTML = "...";
        element.appendChild(a);
        childs[count-1].after(element);
    }
}