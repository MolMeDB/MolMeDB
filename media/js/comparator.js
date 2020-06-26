// Get the modal
var modal = document.getElementById('m-comp');

// Get the button that opens the modal
var btnModal = document.getElementById("comp-col-button");

// Get the <span> element that closes the modal
var span = document.getElementById("m-close");

// Advanced section accordion
var acc = document.getElementsByClassName("accordion");

/** Holds current interactions IDs */
var interaction_ids = [];
/** Holds current substance IDs */
var substance_ids = [];
var substanceCount;

var ret = [];

var i;
var values = [];

// Holds chosen options in advanced
var options = [];
options['charges'] = options['membranes'] = options['methods'] = [];

// Holds data for export
var dataset_export;

// Chart global variables
var fun = function (){};
var myChart = fun;
var charts = [fun, fun, fun];
var color = ['rgba(50,230,21,1)', 'rgba(255,99,132,1)','rgba(255,220,100,1)', 'rgba(0,50,255,1)' , 'rgba(229,146,0,1)', 'rgba(150,0,255,1)', 'rgba(110,200,132,1)', 
             'rgba(215,99,255,1)', 'rgba(25,99,160,1)', 'rgba(80,99,210,1)', 'rgba(255,150,0,1)', 'rgba(0,255,132,1)', 'rgba(100,150,0,1)', 'rgba(20,130,250,1)'];

var active_methods = [];
            

/**
 * Function for checking offset while scrolling
 * Holds button for "COMPARE DATA" on the correct position
 */
$(document).scroll(function() 
{
    if($('#comp-columns').offset().top + $('#comp-columns').height() >= $('#footer').offset().top)
    {
        $('#comp-columns').css('position', 'absolute');
    }
    if($(document).scrollTop() + window.innerHeight < $('#footer').offset().top)
    {
        $('#comp-columns').css('position', 'fixed'); // restore when you scroll up
    }
});

/**
 * Sets overlay and call proper function
 * 
 * @param {boolean} download
 */
async function setOverLay(download = false)
{
    await show_overlay();

    if (download)
    {
        await set_overlay_text('Making CSV file. Please wait...');
        export_whole_dataset("Dataset");
    }
    else{
        await set_overlay_text('Loading interactions. Please wait...');
        loadComparatorTable();
    }
}


// When the user clicks the button, open the modal 
if(btnModal !== null){
    btnModal.onclick = function() 
    {
        if(modal == null)
        {
            modal = document.getElementById('m-comp');
        }
        modal.style.display = "block";
    }
}

// When the user clicks on <span> (x), close the modal
span.onclick = function() {
    modal.style.display = "none";
}

// When the user clicks anywhere outside of the modal, close it
window.onclick = function(event) {
    if (event.target == modal) {
        modal.style.display = "none";
    }
}

/**
 * Accordion button function
 */
for (i = 0; i < acc.length; i++) 
{
  acc[i].onclick = function() 
  {
    this.classList.toggle("active");
    var panel = this.nextElementSibling;
    if (panel.style.maxHeight){
      panel.style.maxHeight = null;
    } else {
      panel.style.maxHeight = panel.scrollHeight + "px";
    } 
  }
}

/**
 * Removes substance from comparator list
 * 
 * @param {string} id 
 */
function remove_substance(id)
{
    id = id.toString();

    // Remove from comparator list
    remove_from_comparator(id);

    // Remove from substance ids
    id = get_substance_id(id);
    var index = substance_ids.indexOf(id);
    
    substance_ids.splice(index,1);
    
    // Update IDs of interactions
    total_interaction();
}

/**
 * Start function
 * Loads comparator substances
 */
$(document).ready(function()
{
    var names = [];

    // Get substance IDs
    $('#comparator-ul>div').each(function(k, obj)
    {
        var id = $(obj).attr('id');
        var ch = $(obj).children()[1];
        var name = $(ch).html();

        names.push(name);
        id = get_substance_id(id);
        substance_ids.push(id);
    });

    var ulMod = document.getElementById("substanceListMod");
    
    var i = substance_ids.length;
    
    while (i--)
    {       
        //Second part (visible)
        var liMod = document.createElement("li");
        var IDstring = get_comparator_detail_id(substance_ids[i]);
        var func = "remove_substance('" + IDstring + "');";
        liMod.appendChild(document.createTextNode(names[i]));
        liMod.setAttribute("id", IDstring);
        liMod.setAttribute("title", "Remove");
        liMod.setAttribute("onclick", func);
        ulMod.appendChild(liMod);
    }

    // Get count of available interactions
    total_interaction();
    
    //Nacteni membran/metod
    load_membranes_methods();
    
    //Nacteni nabojů
    create_charges();
});

/** HIDES CHART WINDOW */
$("#btn-chart-close").click(function(){
    var div = document.getElementById("superpositionWindow");
    div.style.display = 'none';
});


/**
 * Gets values to the chart for approximation
 * @param {array} array 
 */
function getValues(array)
{
    var result = [];
    var i = 0; var k = 0
    var size = array.length;
    
    while (k<size){
        var count = array[k].length;
        for(var j=0; j<count; j++){
            result[i] = Math.round(array[k][j]*1000)/1000;
            i++;
        }
        k++;
    }
    return result;
}


/**
 * Loads chart for chosen energy profiles
 * 
 * @param {array} energyFlags 
 */
function loadSummaryChart(energyFlags)
{
    var dataset = [];
    var values = [];
    var config;
    var labelsSet = [];

    dataset_export = [];
    dataset_export[0] = [];
    dataset_export[0][0] = 'Distance [nm]';
    
    // Get data
    var data = ajax_request('comparator/getEnergyValues', {flags: energyFlags}, "POST");

    if(!data)
    {
        var div = document.getElementById("superpositionWindow");
        div.style.display = 'none';
        return;
    }

    for(var i=0; i<36; i++){
        labelsSet[i] = i/10;
        dataset_export[i+1] = [];
        dataset_export[i+1][0] = i/10;
    }

    var count = data.length;

    for(var i=0; i<count; i++)
    {
        var detail = data[i];

        dataset[i] = {};
        dataset[i].label = detail.substance + '(' + detail.membrane + '|' + detail.method + ')';
        dataset[i].borderWidth = 1;
        dataset[i].pointRadius = 4;
        dataset[i].backgroundColor = 'rgba(255,255,255,0.1)';
        dataset[i].borderColor = color[i].toString();
        dataset_export[0][i+1] = dataset[i].label + " [kcal/mol]";
    
        var index; var x_main = []; var y_main = []; var X = []; var Y = []; var values = [];
        var size = detail.data.length;

        // Copying values of free energy profile to the arrays
        for(var j=0; j<size; j++)
        { 
            x_main[j] = detail.data[j].distance;
            y_main[j] = detail.data[j].energy;
        }
        
        for(var j= 0; j<36; j++){ //Interpolation point by point
            if(j===0){
                values[j] = y_main[0];
                dataset_export[j+1][i+1] = y_main[0];
                continue;
            }
            index = findNearestIndex(x_main, labelsSet[j]);
            if (index === -1){ //If value is out of array
                values[j] = 0;
                dataset_export[j+1][i+1] = 0;
            }
            else{
                X = fillValues(x_main, index);
                Y = fillValues(y_main, index);
                values[j] = nevillesIteratedInterpolation(labelsSet[j], X, Y);
                values[j] = Math.round(values[j]*10000)/10000;
                dataset_export[j+1][i+1] = values[j];
            }
        }

        dataset[i].data = values;
        dataset[i].spanGaps = true;

    }

    data = {labels: labelsSet,
            datasets: dataset
    };
    config = {
        type: 'line',
        data: data,
        options: {
            responsive: true,
            events: ['mousemove'],
            tooltips: {
                mode: 'x'
            },
            legend: {
                display: true,
                labels: {
                fontColor: 'rgb(0, 0, 10)'
                }
            },
            title: {
                display: false,
                position: 'top',
                text: 'Distance'
            },
            scales: {
                yAxes: [{
                    ticks: {
                        beginAtZero: true
                    },
                    scaleLabel: {
                            display: true,
                            labelString: 'Free energy [kcal / mol]',
                            padding: 10
                        },
                    gridLines: {
                            
                        }
                }],
                xAxes: [{
                        ticks: {
                            beginAtZero: true,
                            autoSkip: false,
                            callback: function(dataLabel, index) {
                                    return  (dataLabel % 1 === 0 || dataLabel === 3.5) ? dataLabel : '';
                            }
                        },
                        scaleLabel: {
                            display: true,
                            labelString: 'Distance [nm]'
                        },
                        gridLines: {
                            display: true
                        }
                        
                }]
            }
        }
    }
    renderChart("myChart", config);
}




function renderChart(id, config, i = 0){
    if(id == "myChart"){
        if(typeof myChart != 'function'){
            myChart.destroy();
        }
        var ctx = document.getElementById(id).getContext('2d');
        myChart = new Chart(ctx, config);
    }
    
    else{
        if(typeof charts[i] != 'function')
            charts[i].destroy();
        
        var ctx = document.getElementById(id).getContext('2d');
        charts[i] = new Chart(ctx, config);
    }
}



/**
 * Exports interactions for given filter
 * @param {string} name 
 */
function export_whole_dataset(name)
{
    var data = [];
    var date = new Date();
    // Set header
    data[0] = ['Name', 'Identifier', 'Pubchem', 'Drugbank', 'SMILES', 'Method', 'Membrane', 'Note', 'Q', 'Temperature', 'LogP', 'X_min', 'X_min_+/-', 'G_pen', 'G_pen_+/-', 'G_wat', 'G_wat_+/-', 'LogK', 'LogK_+/-', 'LogPerm', 
        'LogPerm_+/-', 'MW', 'Primary_reference', 'Secondary_reference'];

    // Divide into subqueries for php limitations
    var per_request = 100;
    var k = 1;
    var actual_index = 0;
    var interactions = [];

    while(actual_index < interaction_ids.length)
    {
        var ids = interaction_ids.slice(actual_index, per_request + actual_index);
        var ajax_data = ajax_request('comparator/getInteraction', { id: ids.join() });

        if (ajax_data === false) 
        {
            hide_overlay();
            return;
        }

        interactions = interactions.concat(ajax_data);

        actual_index = k*per_request;
        k++;
    }

    var row_count = interactions.length;
    
    for(var i = 0; i <row_count; i++)
    {
        var int = interactions[i];
        
        data[i+1] = [
            int.substance.name,
            int.substance.identifier,
            int.substance.pubchem,
            int.substance.drugbank,
            int.substance.SMILES,
            int.method,
            int.membrane,
            int.comment, 
            int.charge, 
            int.temperature, 
            int.substance.LogP,
            int.Position, 
            int.Position_acc, 
            int.Penetration, 
            int.Penetration_acc, 
            int.Water, 
            int.Water_acc, 
            int.LogK, 
            int.LogK_acc, 
            int.LogPerm, 
            int.LogPerm_acc, 
            int.substance.MW,
            int.reference, 
            int.secondary_reference && int.id_reference != int.id_secondary_reference ? int.secondary_reference : "",
        ];
    }

    
    var d = date.getDate() + "." + (date.getMonth()+1) + "." + date.getFullYear();
    exportToCsv(name + '_' + d + '.csv', data);

    hide_overlay();
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
    var label = text;
    
    if(text == null)
    {
       return td; 
    }
    
    if(text.length > 20)
    {
        text = text.substring(0,15) + "...";
    }
    
    td.innerHTML = "<p title='" + label + "' class='attribute'>" + text + "</p>";
    
    return td;
}


function createTD(val, appr = false)
{
    var empty = false;
    
    if(val == null || val.toString().toLowerCase() == "null")
    {
        val = "";
        empty = true;
    }   
    
    var td = document.createElement("td");
    
    td.innerHTML = "<span class=\"attribute\">" + val + "</span>";
    
    if(appr && !empty)
    {
        var acc = document.createElement("p");
        acc.classList.toggle("accuracy");
        acc.innerHTML = "<b> +/- " + appr + "</b>";
        
        td.appendChild(acc);
    }
    
    return td;
}

/**
 * Gets comparator table for given params
 */
function loadComparatorTable()
{
    var container = document.getElementById("tableDiv");
    //Delete old table
    if(container.children[0])
    {
        container.children[0].remove();
    }

    var table = document.createElement("table");
    table.classList.toggle("table-comparator"); table.id = "comparatorTable";
    var thead = document.createElement("thead");
    thead.classList.toggle("thead-comparator");
    var headNames = ["<b>Name</b>", "<b>Membrane</b>", "<b>Method</b>","<b>Q</b>", "<b>Note</b>" , "<b>T<br/> <label class='units'>[°C]</label></b>", "<b>LogP<br/> <label class='units'>[mol<sub>m</sub>/mol<sub>w</sub>]</label></b>", 
        "<b>X<sub>min</sub> <label class='units'>[nm]</label></b>", '<b>&Delta;G<sub>pen</sub> <label class="units">[kcal / mol]</label></b>', '<b>&Delta;G<sub>wat</sub> <label class="units">[kcal / mol]</label></b>', 
        '<b>LogK<sub>m</sub> <label class="units">[mol<sub>m</sub>/mol<sub>w</sub>]</label></b>', '<b>LogPerm <label class="units">[cm/s]</label></b>', 
        '<b>MW [Da]</b>', "<b>Reference</b>",
        '<i class="fa fa-line-chart" style="font-size:24px; color: #269abc;"></i>'];
    var count = headNames.length;
    var tr = document.createElement("tr");
    
    //Header of table
    for(var i = 0; i<count; i++)
    {
        if(i === count-1){
            var th = document.createElement("td");
        }
        else
            var th = document.createElement("th");
        
        th.setAttribute("asc", "false");
        if(i == 0)
            th.classList.toggle("first-td");
        else if (i == count -1){
            th.id = "btn-chart";
            th.title = 'Show chart of free energy profiles';
        }
        th.innerHTML = headNames[i] + "<span class='dir'></span>";
        tr.appendChild(th);
    }
    
    thead.appendChild(tr);
    table.appendChild(thead);
    
    
    //Data
    var tbody = document.createElement("tbody");
    
    var iterator = 0;
    var ref_iterator = 0;

    // Load only maximal 200 compounds
    var interactions_limited = interaction_ids.slice(0,200);

    var ajax_data = ajax_request('comparator/getInteraction', { id: interactions_limited.join() });

    if(!ajax_data)
    {
        hide_overlay();
        return;
    }

    var interactions = ajax_data;

    if(!Array.isArray(interactions))
    {
        interactions = [interactions];
    }

    var row_count = interactions.length; 
    
    for(var i = 0; i<row_count; i++)
    {
        res = values[i] = interactions[i];

        //Inserting data to the table
        var tr = document.createElement("tr");
        
        // Load names the to table
        for(var j=0; j<3; j++){
            var td = document.createElement("td");
            var a = document.createElement("a");
            
            if(j == 0){
                a.innerHTML =  res.substance.name;
                a.href = '/mol/' + res.substance.identifier;
                a.setAttribute("target", "_blank");
                td.classList.toggle("first-td"); td.classList.toggle("text-primary");
            }
            else if (j==1){
                a.innerHTML = res.membrane;
                a.href = '/browse/membranes';
            }
            else{
                a.innerHTML = res.method;
                a.href='/browse/methods';
            }
            td.appendChild(a);
            tr.appendChild(td);
        }
            
            // Load interaction details
            
            var td_note = make_textfield_td(res.comment);
            var td_Q = createTD(res.charge);
            var td_T = createTD(res.temperature);
            var td_logP = createTD(res.substance.LogP);
            var td_x_min = createTD(res.Position, res.Position_acc);
            var td_g_pen = createTD(res.Penetration, res.Penetration_acc);
            var td_g_wat = createTD(res.Water, res.Water_acc);
            var td_logK = createTD(res.LogK, res.LogK_acc);
            var td_logPerm = createTD(res.LogPerm, res.LogPerm_acc);
            var td_mw = createTD(res.substance.MW);
            
            // Load reference detail
            var td_ref = document.createElement('td');
            ref_iterator++;
            
            if(res.id_reference != 0)
                td_ref.innerHTML = "<div class=\"popup\" onclick=\"show_popup('" + ref_iterator + "')\">" + res.id_reference + "<span class=\"popuptext\" id=\"ref_popup_" + ref_iterator + "\">" + res.reference + "</span></div>";
            else
                td_ref.innerHTML = "";
            
            //Checkbox for chart
            var id_substance = res.id_substance;
            var td_checkbox = document.createElement("td");
            var input = document.createElement("input");
            
            input.type="checkbox";
            input.name = "graphCheckBox";
            input.value = res.energy_profile_flag;;

            if(!res.energy_profile_flag)
            {
                input.setAttribute("disabled", true);
                input.setAttribute("title", "Free energy profile is not available");
            }   

            td_checkbox.appendChild(input);
            
            tr.appendChild(td_Q);
            tr.appendChild(td_note);
            tr.appendChild(td_T);
            tr.appendChild(td_logP);
            tr.appendChild(td_x_min);
            tr.appendChild(td_g_pen);
            tr.appendChild(td_g_wat);
            tr.appendChild(td_logK);
            tr.appendChild(td_logPerm);
            tr.appendChild(td_mw);
            tr.appendChild(td_ref);
            tr.appendChild(td_checkbox);
            tbody.appendChild(tr);
            
            iterator++;
            if(iterator > 200)
                break;
    }
    
    table.appendChild(tbody);
    container.appendChild(table);
    
    get_sorting_func();
       
    //Function for chart-button
    $("#btn-chart").click(function(){
        var table, checked, count, ids = [];
        table = document.getElementById("comparatorTable");
        checked =  $('input[name="graphCheckBox"]:checked');
        count = checked.length;
        if(count === 0){ alert("No compounds chosen!"); return;}

        $(checked).each(function(i, obj){
            if(!$(obj).val())
            {
                return false;
            }

            ids.push($(obj).val());
        });

        document.getElementById("superpositionWindow").style.display = "block";
        loadSummaryChart(ids);  
    });
    
    hide_overlay();
}  

/**
 * Shws popup whisper of publication detail
 * 
 * @param {integer} id - Row ID 
 */
function show_popup(id)
{
    var popup = document.getElementById("ref_popup_" + id);
    popup.classList.toggle("show");
}


//Modal functions
var button_modal_add = document.getElementById("btn-add");
var modal_body = document.getElementsByClassName("myModal-body");
var content_charts = document.getElementsByClassName("modal-content-chart");
var generate_button = document.getElementById("generate-chart");
var modal = document.getElementById("myModal");
var filter_box = document.getElementById("filter_2");
var checkbox_cont = document.getElementById("checkbox-container");
var checkbox_sep = document.getElementById("separate");

/**
 * Adds second dataset option
 */
button_modal_add.onclick = function() 
{
    if(filter_box.hidden === true){
        filter_box.hidden = false;
        this.innerHTML = "Remove dataset #2";
        checkbox_cont.hidden = false;
    }
    else{
        filter_box.hidden = true;
        this.innerHTML = "Add dataset (1)";
        checkbox_cont.hidden = true;
        checkbox_sep.checked = true;
    }
}

generate_button.onclick = function() 
{
    loadChart(0);
};

/**
 * Show data comparator chart
 * 
 * @param {integer} id 
 */
function loadChart(id)
{
    // Get chosen values
    var id_membrane = document.getElementById("membrane_" + id).value;
    var id_method = document.getElementById("method_" + id).value;
    var charge_1 = document.getElementById("charge_" + id).value;
    var column = document.getElementById("column_" + id).value;

    var id_membrane_2 = document.getElementById("membrane_1").value;
    var id_method_2 = document.getElementById("method_1").value;
    var charge_2 = document.getElementById("charge_1").value;
    
    // Init
    var config; var dataset = [];
    var unit = document.getElementById("units_" + id + "_" + column);
    var units = ["LogP [mol_m/mol_w]", "X_min [nm]", "G_pen [kcal/mol]", "G_wat [kcal/mol]", "LogK [mol_m/mol_w]", "LogPerm [cm/s]"];
    var separated = document.getElementById("separate").checked;

    // Loading data
    var data = returnData(id_membrane, id_method, charge_1, column);
    var data_2 = returnData(id_membrane_2, id_method_2, charge_2, column);

    // Initialize export file
    dataset_export = [];
    dataset_export[0] = [];
    dataset_export[0][0] = 'Name';
    
    var X = []; var Y = []; var Z = [];

    var method = data[0] ? data[0].method : "None";
    var membrane = data[0] ? data[0].membrane : "None";
    
    dataset[0] = {}; 
    dataset[0].label = method + " (" + membrane + ") [" + charge_1 + "]";
    dataset_export[0][1] = unit.innerHTML + " [" + units[unit.value] + "]" + ' (' + dataset[0].label + ')';
    dataset[0].borderWidth = 1; 
    dataset[0].pointRadius = 4; 
    dataset[0].backgroundColor = 'rgba(255,255,255,0.1)';     
    dataset[0].borderColor = 'rgba(220,100,99,1)'; 
    dataset[0].showLine = false; 
    dataset[0].spanGaps = true;
    
    // Show 2 datasets?
    if(!filter_box.hidden)
    { 
        var method_2 = data_2[0] ? data_2[0].method : "None";
        var membrane_2 = data_2[0] ? data_2[0].membrane : "None";
        dataset[1] = {};
        dataset[1].label = method_2 + " (" + membrane_2 + ") [" + charge_2 + "]";
        dataset_export[0][2] = unit.innerHTML + " [" + units[unit.value] + "]" + ' (' + dataset[1].label + ')';
        dataset[1].borderWidth = 1;
        dataset[1].pointRadius = 4;
        dataset[1].backgroundColor = 'rgba(255,255,255,0.1)';
        dataset[1].borderColor = 'rgba(80,99,210,1)';
        dataset[1].showLine = false;
        dataset[1].spanGaps = true;
    }
    
    var labs = [];

    // Showing two datasets ?
    if(!filter_box.hidden)
    { 
        // Make one array from both datasets
        var total = summarizeData(data, data_2, separated);  

        if(!separated)
        {   
            dataset[0].data = [];
            for(var j=0; j<total.length; j++){
                dataset_export[j+1] = [];
                dataset[0].data[j] = {};
                dataset[0].data[j].x = dataset_export[j+1][1] = total[j][1];
                dataset[0].data[j].y = dataset_export[j+1][2] = total[j][2];
                labs[j] = dataset_export[j+1][0] = total[j][0];
            }

            data = {
                labels: labs,
                datasets: dataset 
            };
        }
        else
        {
            for(var j=0; j<total.length; j++)
            {
                dataset_export[j+1] = [];
                X[j] = dataset_export[j+1][0] = total[j][0];
                Y[j] = dataset_export[j+1][1] = total[j][1];
                Z[j] = dataset_export[j+1][2] = total[j][2];
            }
            dataset[0].data = Y;
            dataset[1].data = Z;
            data = {
                labels: X,
                datasets: dataset
            };
        }
    }
    else
    {
        for(var j=0; j<data.length; j++)
        {
                dataset_export[j+1] = [];
                X[j] = dataset_export[j+1][0] = data[j].name;
                Y[j] = dataset_export[j+1][1] = data[j].value;
        }
        dataset[0].data = Y;
        data = {
            labels: X,
            datasets: dataset
        };
    }

    config = {      
        type: 'line',
        data: data,
        options: {
            responsive: true,
            events: ['mousemove'],
            tooltips: {
                mode: 'index',
                    callbacks: {
                    }
            },
            legend: {
                display: true,
                labels: {
                fontColor: 'rgb(20, 30, 10)'
                }
            },
            title: {
            },
            scales: {
                yAxes: 
                [
                    {
                        ticks: {
                            beginAtZero: true
                        },
                        scaleLabel: {
                            display: true,
                            labelString: units[unit.value],
                            padding: 10
                        },
                    }
                ],
                xAxes: 
                [
                    {
                        ticks: {
                            beginAtZero: true,
                            autoSkip: false
                        },
                        scaleLabel: {
                            display: true,
                            padding: 10
                        },
                        gridLines: {
                            display: true
                        }
                    }
                ]
            }
        }
    }

    if(separated == false)
    {
        config.options.scales.xAxes[0].type = 'linear';
        config.options.scales.xAxes[0].position = 'bottom';
        config.options.tooltips.callbacks.title = function(tooltipItem, data)
        {
            return data['labels'][tooltipItem[0]['index']];
        };
        config.options.tooltips.callbacks.label = function(tooltipItem, data) {
            return method + " (" + membrane + ")" + ": " + data['datasets'][0]['data'][tooltipItem['index']]['x'];   
        };
        config.options.tooltips.callbacks.afterLabel = function(tooltipItem, data) {
            return method_2 +  " (" + membrane_2 + ")" + ": " + data['datasets'][0]['data'][tooltipItem['index']]['y'];
        };
        config.options.scales.xAxes[0].scaleLabel.labelString = units[unit.value]; 
        
    }
    
    renderChart("myChart" + id, config, id);
}


function returnData(id_membrane, id_method, charge, column)
{
    var columns = ["LogP", "Position", "Penetration", "Water", "LogK", "LogPerm"];
    column = columns[column];

    if(charge == 'Empty')
    {
        charge = null;
    }

    var result = [];
    var count = values.length;

    for(var j = 0; j < count; j++)
    {
        if(values[j].id_membrane == id_membrane && values[j].id_method == id_method && 
            (charge == 'all' || values[j].charge == charge))
        {
            var val;

            if(column === "LogP")
            {
                val = values[j].substance.LogP;
            }
            else
            {
                val = values[j][column];
            }

            // Not adding empty or nonnumeric values
            if(val > 0 || val < 0)
            {
                var new_obj = {
                    name: values[j].substance.name,
                    value: val,
                    membrane: values[j].membrane,
                    method: values[j].method,
                };

                result.push(new_obj);
            }
        }
    }
    
    return result;
}

/**
 * Summarize data to chart
 * 
 * @param {object} data 
 * @param {object} data_2 
 * @param {boolean} separated - Shows data separated?
 */
function summarizeData(data, data_2, separated)
{
    var result = [];
    var size = data.length;
    var size_2 = data_2.length;

    if(!separated){
        if(size < size_2){
            for(var i = 0; i<size; i++)
            {
                var name = data[i].name;
                var  j = 0;

                while(j < size_2)
                {
                    if(data_2[j].name === name)
                    {
                        result.push([
                            name,
                            data_2[j].value,
                            data[i].value
                        ]);
                        break;
                    }

                    j++;
                }
            }
        }
        else
        {
            for(var i = 0; i<size_2; i++)
            {
                var name = data_2[i].name;
                var  j = 0;

                while(j < size)
                {
                    if(data[j].name === name)
                    {
                        result.push([
                            name,
                            data[j].value,
                            data_2[i].value
                        ])
                        break;
                    }
                    j++;
                }
            }
        }
    }
    else
    {
        for(var i = 0; i<size; i++)
        {
            var j = 0;
            size_2 = data_2.length;

            while(j < size_2)
            {
                if(!data_2[j])
                {
                    j++;
                    continue;
                }

                if(data_2[j].name === data[i].name)
                {
                    result.push([
                        data[i].name,
                        data[i].value,
                        data_2[j].value
                    ])

                    delete data_2[j];
                    break;
                }

                j++;
            }

            if(j === size_2)
            {
                result.push([
                    data[i].name,
                    data[i].value,
                    "null"
                ])
            }
            
        }

        size_2 = data_2.length;

        // Add no matching data
        for(var i = 0; i < size_2; i++)
        {
            if (!data_2[i])
            {
                continue;
            }

            result.push([
                data_2[i].name,
                "null",
                data_2[i].value
            ]);
        }
    }

    return result;
}

/**
 * Callback for choose membrane/method
 * 
 * @param {object} obj - Current object 
 * @param {integer} type - Type of chosen record 
 */
function advanced_style(obj, type)
{
    var alls = document.getElementsByClassName("sel-all");
    var mems = document.getElementsByClassName("filter-mems");
    var mets = document.getElementsByClassName("filter-meths");
    var charges = document.getElementsByClassName("filter-charge");
    
    //Methods
    if(type === 0)
    { 
        if (obj === alls[type]) //If users clicked on Select All
        { 
            if(obj.classList.contains("active"))
            { 
                obj.classList.remove("active");
                
                for(var i = 0; i<mets.length; i++)
                {
                    mets[i].classList.remove("active");
                }
            }
            else
            {
                obj.classList.add("active");
                for(var i = 0; i<mets.length; i++)
                {
                    mets[i].classList.add("active");
                }
            }
        }
        else{
            var active = 0; 
            obj.classList.toggle("active");
            for(var i = 0; i<mets.length; i++){
                    if(mets[i].classList.contains("active"))
                        active++;
            }
            
            if(active === mets.length)
                alls[type].classList.add("active");
            else
                alls[type].classList.remove("active");
        }
    }
    
    else if (type === 1) //Membranes
    { 
        if(obj === alls[type]){ //If users clicked on Select All
            if(obj.classList.contains("active")){ 
                obj.classList.remove("active");
                for(var i = 0; i<mems.length; i++){
                    mems[i].classList.remove("active");
                }
            }
            else{
                obj.classList.add("active");
                for(var i = 0; i<mems.length; i++){
                    mems[i].classList.add("active");
                }
            }
        }
        else{
            var active = 0;
            obj.classList.toggle("active");
            for(var i = 0; i<mems.length; i++){
                    if(mems[i].classList.contains("active"))
                        active++;
            }
            
            if(active === mems.length)
                alls[type].classList.add("active");
            else
                alls[type].classList.remove("active");
        }
    }
    else
    { //Charges
        if(obj === alls[type]){ //If users clicked on Select All
            if(obj.classList.contains("active")){ 
                obj.classList.remove("active");
                for(var i = 0; i<charges.length; i++){
                    charges[i].classList.remove("active");
                }
            }
            else{
                obj.classList.add("active");
                for(var i = 0; i<charges.length; i++){
                    charges[i].classList.add("active");
                }
            }
        }
        else{
            var active = 0;
            obj.classList.toggle("active");
            for(var i = 0; i<charges.length; i++){
                    if(charges[i].classList.contains("active"))
                        active++;
            }
            
            if(active === charges.length)
                alls[type].classList.add("active");
            else
                alls[type].classList.remove("active");
        }
    }
    
    var count;
    
    total_interaction();

    if(type === 0)
    {
        load_membranes_methods(true);
    }
    
    if(type < 2)
    {
         create_charges();
    }
}

/**
 * Callback for export data from chart
 */
$("#export_dataChart").click(function()
{
    exportToCsv("dataset.csv", dataset_export);
});

/**
 * Callback for export data from energy chart 
 */
$("#export_energy_dataChart").click(function()
{
    exportToCsv("FreeEnergy_data.csv", dataset_export);
});




/**
 * Gets total number of interactions for given params
 */
function total_interaction()
{
    var active_mem = document.getElementsByClassName("filter-mems");
    var active_met = document.getElementsByClassName("filter-meths");
    var active_charges = document.getElementsByClassName("filter-charge");
    substanceCount = substance_ids.length;
    var active_membranes = [];
    active_methods = [];
    var active_ch = [];
    
    // Get active membranes
    for(var i = 0; i < active_mem.length; i++)
    {
        if(active_mem[i].classList.contains("active"))
            active_membranes.push(active_mem[i].children[0].value);
    }
    
    // Get active methods
    for(var i = 0; i < active_met.length; i++)
    {
        if(active_met[i].classList.contains("active"))
            active_methods.push(active_met[i].children[0].value);
    }
    
    // Get active charges
    for(var i = 0; i <  active_charges.length; i++)
    {
        if(active_charges[i].classList.contains("active"))
        {
            var val = active_charges[i].children[0].value;

            if(val == "Empty")
            {
                active_ch.push('NULL');
            }
            else
            {
                active_ch.push(val);
            }
        }
    }

    // Get interaction IDs
    var ajax_data = ajax_request('comparator/getInteractionIds', 
    {
        membrane_ids: active_membranes,
        method_ids: active_methods,
        substance_ids: substance_ids,
        charges: active_ch
    }, "POST");

    interaction_ids = [];

    if(!ajax_data && !active_ch.length && !substance_ids.length && 
            !active_membranes.length && !active_methods.length)
    {
        document.getElementById("total_interaction").innerHTML = "<b style='font-size:15px; color: red'>Choose some method / membrane.</b>";
        return;
    }
    else if(ajax_data === false)
    {
        add_message('Problem with getting interactions from server.', 'danger');
        return;
    }

    options['charges'] = [];
    
    // Store loaded data
    $(ajax_data).each(function(index, data)
    {
        interaction_ids.push(data.id);
        
        var ch = data.charge;
        if(ch == null)
        {
            ch = 'Empty';
        }

        if(!options['charges'].includes(ch))
        {
            options['charges'].push(ch);
        }
    });

    // Update visible count of interactions
    var count;

    if (typeof interaction_ids !== 'undefined' && interaction_ids.length > 0)
    {
        count = interaction_ids.length;
    }
    else
    {
        count = 0;
    }

    document.getElementById("total_interaction").innerHTML = "<b style='color: red'>" + count + "</b>";

    return;
}



/**
 * Creates charges
 */
function create_charges()
{
    var count;
    
    if (typeof options['charges'] !== 'undefined' && options['charges'].length > 0)
        count = options['charges'].length;
    else
        return;
    
    var select_1 = document.getElementById("charge_0");
    var select_2 = document.getElementById("charge_1");
    
    select_1.innerHTML = "";
    select_2.innerHTML = "";
    
    var option = document.createElement("option");
    option.setAttribute("value", 'all');
    option.innerHTML = 'All';
    var option_2 = document.createElement("option");
    option_2.setAttribute("value", 'all');
    option_2.innerHTML = 'All';


    select_1.appendChild(option);
    select_2.appendChild(option_2);
    
    var tr = document.getElementById("charges-row");
    tr.innerHTML = "";
    var td = document.createElement("td");
    td.setAttribute("class", "sel-all active"); td.setAttribute("onclick", "advanced_style(this, 2)");
    td.innerHTML = "<b>Select All</b>";
    tr.appendChild(td);
    
    for(var i = 0; i < count; i++)
    {
        var text;
        if(options['charges'][i] === '')
        {
            text = "EMPTY";
        }
        else
        {
            text = options['charges'][i];
        }
        var td = document.createElement("td");
        td.innerHTML = "<input type=\"hidden\" value=\"" + options['charges'][i] + '">' + text;
        td.setAttribute("onclick", "advanced_style(this, 2)");
        td.setAttribute("class", "filter-charge");
        tr.appendChild(td);
        
        //Graphs
        var option = document.createElement("option");
        option.setAttribute("value", options['charges'][i]);
        option.innerHTML = text;
        var option_2 = document.createElement("option");
        option_2.setAttribute("value", options['charges'][i]);
        option_2.innerHTML = text;
        
        
        select_1.appendChild(option);
        select_2.appendChild(option_2);
    }
}

/**
 * Loads available membranes/methods
 * 
 * @param {boolean} methods - Load methods? 
 */
function load_membranes_methods(methods=false)
{
    var config = 
    {
        substance_ids: substance_ids,
        method_ids: 0,
        membrane_ids: 0
    };

    if(methods)
    {
        config.method_ids = active_methods;
    }

    var membranes_all = ajax_request('membranes/getAll', config, "POST");
    var methods_all = ajax_request('methods/getAll', config, "POST");

    if(membranes_all == false || methods_all == false)
    {
        return false;
    }
    
    options['membranes'] = membranes_all;
    options['methods'] = methods_all;

    if(!methods)
    {
        create_mets();
    }
    create_mems();
}


/**
 * Creates membrane list
 * 
 */
function create_mems()
{
    var tr = document.getElementById("membranes");
    tr.innerHTML = "";
    
    var select_1 = document.getElementById("membrane_0");
    var select_2 = document.getElementById("membrane_1");
    
    select_1.innerHTML = "";
    select_2.innerHTML = "";
    
    //Select All
    var td = document.createElement('td');
    td.className = "sel-all active";
    td.setAttribute('onclick', 'advanced_style(this, 1)');
    td.innerHTML = "<b>Select all</b>";
    
    tr.appendChild(td);
    //Other values
    var c = options['membranes'].length;
    
    for(var i = 0; i < c; i++){
        var td = document.createElement('td');
        td.className = 'filter-mems';
        td.setAttribute('onclick', 'advanced_style(this, 1)');
        var input = document.createElement("input");
        input.hidden = true;
        input.setAttribute("value" , options['membranes'][i].id);
        td.appendChild(input);
        td.innerHTML += options['membranes'][i].name;
        
        tr.appendChild(td);
        
        //Graphs
        var option = document.createElement("option");
        option.setAttribute("value", options['membranes'][i].id);
        option.innerHTML = options['membranes'][i].name;
        var option_2 = document.createElement("option");
        option_2.setAttribute("value", options['membranes'][i].id);
        option_2.innerHTML = options['membranes'][i].name;
        
        
        select_1.appendChild(option);
        select_2.appendChild(option_2);
    }
}

/**
 * Creates method list
 */
function create_mets()
{
    var tr = document.getElementById("methods");
    tr.innerHTML = "";
    
    var select_1 = document.getElementById("method_0");
    var select_2 = document.getElementById("method_1");
    
    select_1.innerHTML = "";
    select_2.innerHTML = "";
    
    //Select All
    var td = document.createElement('td');
    td.className = "sel-all active";
    td.setAttribute('onclick', 'advanced_style(this, 0)');
    td.innerHTML = "<b>Select all</b>";
    
    tr.appendChild(td);
    //Other values
    var c = options['methods'].length;
    
    for(var i = 0; i < c; i++){
        var td = document.createElement('td');
        td.className = 'filter-meths';
        td.setAttribute('onclick', 'advanced_style(this, 0)');
        var input = document.createElement("input");
        input.hidden = true;
        input.setAttribute("value" , options['methods'][i].id);
        td.appendChild(input);
        td.innerHTML += options['methods'][i].name;
        
        tr.appendChild(td);
        
        //Graphs
        var option = document.createElement("option");
        option.setAttribute("value", options['methods'][i].id);
        option.innerHTML = options['methods'][i].name;
        var option_2 = document.createElement("option");
        option_2.setAttribute("value", options['methods'][i].id);
        option_2.innerHTML = options['methods'][i].name;
        
        
        select_1.appendChild(option);
        select_2.appendChild(option_2);
    }
}

