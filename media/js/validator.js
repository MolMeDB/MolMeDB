var joiner_parent = $('#joiner-overlay');

$('#close-joiner').on('click', function()
{
    $(joiner_parent).hide();  
});

function join_molecules()
{
    var target = $('#joiner_columns');

    // First, clear target
    $(target).html('');
    clearButtons();

    var inp_1 = document.createElement('input');
    var inp_2 = document.createElement('input');

    inp_1.classList.toggle('form-control');
    inp_2.classList.toggle('form-control');

    inp_1.setAttribute('id', 'iden_1');
    inp_2.setAttribute('id', 'iden_2');

    inp_1.setAttribute('placeholder', 'MMXXXXX');
    inp_2.setAttribute('placeholder', 'MMXXXXX');

    var col_1 = create_joiner_col('<h3>Insert identifier 1</h3>');
    var col_2 = create_joiner_col('<h3>Insert identifier 2</h3>');

    col_1.appendChild(inp_1);
    col_2.appendChild(inp_2);

    var target_btn = $('#joiner-content');
    var div = document.createElement('div');
    div.classList.toggle('joiner-buttons');

    var btn_1 = document.createElement('button');

    btn_1.classList.toggle('btn');
    btn_1.classList.toggle('btn-success');
    btn_1.setAttribute("onclick", "compare_substances(0,0,false)");

    btn_1.innerHTML = "Compare";

    div.appendChild(btn_1);

    $(target_btn).append(div);

    $(target).append(col_1);
    $(target).append(col_2);

    // Show joiner
    $(joiner_parent).show();
}

function compare_substances(subst_id_1, subst_id_2, nondupl = true)
{
    // Show joiner
    $(joiner_parent).show();

    if(!subst_id_1 && !subst_id_2)
    {
        subst_id_1 = $('#iden_1').val();
        subst_id_2 = $('#iden_2').val();
    }

    var substance_1 = ajax_request('compounds/internal/detail',{'id': subst_id_1, "ipassive": 1});
    var substance_2 = ajax_request('compounds/internal/detail', {'id': subst_id_2, "ipassive": 1});

    if(!substance_1 || !substance_2)
    {
        alert('Invalid data.');
        return;
    }

    var target = $('#joiner_columns');

    // First, clear target
    $(target).html('');
    clearButtons();

    var col_1 = create_joiner_col(
        "<a href='/mol/" + substance_1.detail.identifier + "' target='_blank'>" +
        substance_1.detail.name + "</a>"
    );
    var col_2 = create_joiner_col(
        "<a href='/mol/" + substance_2.detail.identifier + "' target='_blank'>" +
        substance_2.detail.name + "</a>"
    );

    // Basic section
    col_1.appendChild(create_section('Basic'));
    col_2.appendChild(create_section('Basic'));

    col_1.appendChild(create_row('Identifier', substance_1.detail.identifier));
    col_2.appendChild(create_row('Identifier', substance_2.detail.identifier));

    col_1.appendChild(create_row('LogP', substance_1.detail.LogP));
    col_2.appendChild(create_row('LogP', substance_2.detail.LogP));

    col_1.appendChild(create_row('SMILES', substance_1.detail.SMILES));
    col_2.appendChild(create_row('SMILES', substance_2.detail.SMILES));

    col_1.appendChild(create_row('InchiKey', substance_1.detail.inchikey));
    col_2.appendChild(create_row('InchiKey', substance_2.detail.inchikey));

    col_1.appendChild(create_row('pubchem', substance_1.detail.pubchem));
    col_2.appendChild(create_row('pubchem', substance_2.detail.pubchem));

    col_1.appendChild(create_row('chEMBL', substance_1.detail.chEMBL));
    col_2.appendChild(create_row('chEMBL', substance_2.detail.chEMBL));

    col_1.appendChild(create_row('chEBI', substance_1.detail.chEBI));
    col_2.appendChild(create_row('chEBI', substance_2.detail.chEBI));

    col_1.appendChild(create_row('pdb', substance_1.detail.pdb));
    col_2.appendChild(create_row('pdb', substance_2.detail.pdb));

    col_1.appendChild(create_row('drugbank', substance_1.detail.drugbank));
    col_2.appendChild(create_row('drugbank', substance_2.detail.drugbank));

    // Structures section
    col_1.appendChild(create_section('2D structure'));
    col_2.appendChild(create_section('2D structure'));

    var pic_div_1 = document.createElement("div");
    var pic_div_2 = document.createElement("div");

    pic_div_1.setAttribute("id", "joiner_pic_1");
    pic_div_2.setAttribute("id", "joiner_pic_2");

    col_1.appendChild(pic_div_1);
    col_2.appendChild(pic_div_2);

    // add_structure(col_1, substance_1.detail.SMILES)
    // add_structure(col_2, substance_2.detail.SMILES)

    // Interactions section
    col_1.appendChild(create_section('Interactions'));
    col_2.appendChild(create_section('Interactions'));

    var table_keys = {
        Membrane: "membrane",
        Method: "method",
        Q: "charge",
        T: "temperature",
        X_min: "Position",
        G_pen: "Penetration",
        G_wat: "Water",
        LogK: "LogK",
        LogPerm: "LogPerm",
        P_reference: "id_reference",
        S_reference: "id_dataset_reference"
    };


    var table_div_1 = document.createElement("div");
    var table_div_2 = document.createElement("div");
    var table_1 = create_table(table_keys);
    var tbody_1 = document.createElement('tbody');
    var table_2 = create_table(table_keys);
    var tbody_2 = document.createElement('tbody');

    for(var i = 0; i < substance_1.interactions.length; i++)
    {
        var inter = substance_1.interactions[i];
        var tr = document.createElement("tr");
        for (const [key,value] of Object.entries(table_keys))
        {
            var val = inter[value] == null ? "" : inter[value];
            
            var td = document.createElement('td');
            td.innerHTML = val;

            tr.appendChild(td);
        }
        tbody_1.appendChild(tr);
    }

    for(var i = 0; i < substance_2.interactions.length; i++)
    {
        var inter = substance_2.interactions[i];
        var tr = document.createElement("tr");
        for (const [key,value] of Object.entries(table_keys))
        {
            var val = inter[value] == null ? "" : inter[value];
            
            var td = document.createElement('td');
            td.innerHTML = val;

            tr.appendChild(td);
        }
        tbody_2.appendChild(tr);
    }   

    table_div_1.classList.toggle("table-detail");
    table_div_2.classList.toggle("table-detail");
    table_div_1.classList.toggle("scrollable");
    table_div_2.classList.toggle("scrollable");

    table_1.appendChild(tbody_1);
    table_2.appendChild(tbody_2);

    table_div_1.appendChild(table_1);
    table_div_2.appendChild(table_2);

    col_1.appendChild(table_div_1);
    col_2.appendChild(table_div_2);

    // Transporter section
    col_1.appendChild(create_section('Transporters'));
    col_2.appendChild(create_section('Transporters'));

    var table_keys = {
        Target: "target",
        Uniprot_id: "uniprot_id",
        pKm: "Km",
        pEC: "EC50",
        pKi: "Ki",
        pIC: "IC50",
    };

    hide_columns(table_div_1)
    hide_columns(table_div_2)

    var table_div_1 = document.createElement("div");
    var table_div_2 = document.createElement("div");
    var table_1 = create_table(table_keys);
    var tbody_1 = document.createElement('tbody');
    var table_2 = create_table(table_keys);
    var tbody_2 = document.createElement('tbody');

    for(var i = 0; i < substance_1.transporters.length; i++)
    {
        var inter = substance_1.transporters[i];
        var tr = document.createElement("tr");
        for (const [key,value] of Object.entries(table_keys))
        {
            var val = inter[value] == null ? "" : inter[value];
            
            var td = document.createElement('td');
            td.innerHTML = val;

            tr.appendChild(td);
        }
        tbody_1.appendChild(tr);
    }

    for(var i = 0; i < substance_2.transporters.length; i++)
    {
        var inter = substance_2.transporters[i];
        var tr = document.createElement("tr");
        for (const [key,value] of Object.entries(table_keys))
        {
            var val = inter[value] == null ? "" : inter[value];
            
            var td = document.createElement('td');
            td.innerHTML = val;

            tr.appendChild(td);
        }
        tbody_2.appendChild(tr);
    }   

    table_div_1.classList.toggle("table-detail");
    table_div_2.classList.toggle("table-detail");
    table_div_1.classList.toggle("scrollable");
    table_div_2.classList.toggle("scrollable");

    table_1.appendChild(tbody_1);
    table_2.appendChild(tbody_2);

    table_div_1.appendChild(table_1);
    table_div_2.appendChild(table_2);

    col_1.appendChild(table_div_1);
    col_2.appendChild(table_div_2);

    hide_columns(table_div_1)
    hide_columns(table_div_2)

    $(target).append(col_1);
    $(target).append(col_2);

    // Add buttons
    add_bottom_buttons(substance_1, substance_2, nondupl)

    // Load 2D structures
    update(substance_1.detail.SMILES, "joiner_pic_1");
    update(substance_2.detail.SMILES, "joiner_pic_2");

}

function hide_columns(element)
{
    deleted = 0;

    // Hide empty columns
    $(element).find('thead').find('td').each(function (key, th) {
        var is_empty = true;

        $(element).find('tr>td:nth-child(' + (key + 1 - deleted) + ')').each(function(k, td)
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
            $(element).find('tr>td:nth-child(' + (key + 1 - deleted) + ')').each(function (k, td) {
                $(td).remove();
            })

            // Hide header
            $(th).remove();

            deleted++;
        }
    });
}

function clearButtons()
{
    $('#joiner-content').find('button').each(function(k,l)
    {
        l.remove();
    });
}

function add_bottom_buttons(s1, s2, nondupl = false)
{
    var target = $('#joiner-content');
    var div = document.createElement('div');
    div.classList.toggle('joiner-buttons');

    var btn_1 = document.createElement('button');
    var btn_2 = document.createElement('button');
    var btn_3 = document.createElement('button');

    var s1name = s1.detail.name;
    var s2name = s2.detail.name;
    var s1id = s1.detail.id;
    var s2id = s2.detail.id;

    btn_1.classList.toggle('btn');
    btn_2.classList.toggle('btn');
    btn_3.classList.toggle('btn');
    btn_1.classList.toggle('btn-success');
    btn_2.classList.toggle('btn-success');
    btn_3.classList.toggle('btn-danger');
    btn_1.setAttribute("onclick", 
        "alert_window('Do you really want to merge " + s1name + " to " + s2name + "?', 'validator/join/" + s2id + "/" + s1id + "');")
    btn_2.setAttribute("onclick", 
        "alert_window('Do you really want to merge " + s2name + " to " + s1name + "?', 'validator/join/" + s1id + "/" + s2id + "');")
    btn_3.setAttribute("onclick", 
        "alert_window('Do you really want to label " + s2name + " <-> " + s1name + " as nonduplicity?', 'validator/disjoin/" + s1id + "/" + s2id + "');")

    btn_1.innerHTML = "Merge: " + s1name + " -> <b>" + s2name + "</b>";
    btn_2.innerHTML = "Merge: <b>" + s1name + "</b> <- " + s2name;
    btn_3.innerHTML = "Label as NON-duplicity";

    div.appendChild(btn_1);
    div.appendChild(btn_2);

    if(nondupl)
    {
        div.appendChild(btn_3);
    }

    target.append(div);
}

function create_table(keys = {})
{
    var table = document.createElement('table');
    var head = document.createElement('thead');

    head.classList.toggle("thead-detail");
    head.setAttribute("style", "font-weight: bold;");

    for (const [key,value] of Object.entries(keys))
    {
        var td = document.createElement('td');
        td.innerHTML = key;
        head.appendChild(td);
    }

    table.appendChild(head);
    return table;
}

function create_joiner_col(title)
{
    var res = document.createElement('div');
    res.classList.toggle('joiner-col');
    res.innerHTML = "<div><h3>" + title + "</h3></div>";

    return res;
}

function create_section(title)
{
    var r = document.createElement('div');
    r.classList.toggle('joiner-section');
    r.innerHTML = title;

    return r;
}

function create_row(label, val)
{
    var r = document.createElement('div');
    var t1 = document.createElement('div');
    var t2 = document.createElement('div');

    r.classList.toggle('joiner-row');

    t1.innerHTML = label;
    t2.innerHTML = val;

    r.appendChild(t1);
    r.appendChild(t2);

    return r;
}