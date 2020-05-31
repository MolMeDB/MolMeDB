var interaction_ids = [];
var substance_ids = [];
var detail = $('#stats-list-detail');

/**
 * Show detail for membrane/method stats
 */
$('.stats-list-item').click(function()
{
    var sublist = $('#stats-sublist');

    // Remove class from list objects
    $('.stats-list-item').each(function(index, obj) {
        obj.classList.remove('list-active');
    });

    // Clear sublist
    $(sublist).find('.stats-sublist-item').each(function(k, item)
    {
        $(item).remove();
    })

    var data = $(this).data('spec');
    var id = data.id;
    var type = data.type; 
    var api_endpoint = null;
    var sublist_class = null;

    if(type == 'membrane')
    {
        api_endpoint = 'stats/membrane_subcats';
        sublist_class = 'method';
    }
    else if(type == 'method')
    {
        api_endpoint = 'stats/method_subcats';
        sublist_class = 'membrane';
    }

    if(!api_endpoint)
    {
        add_message('Error during loading detail.', 'warning');
    }

    var data = ajax_request(api_endpoint, {id: id});

    if(!data)
    {
        return;
    }

    // Make sublist
    $(data).each(function(k, row)
    {
        var div = document.createElement('div');
        var name = document.createElement('div');
        var count = document.createElement('div');

        div.setAttribute('data-spec', '{"id_cat": "' + id + '", "id_subcat": "' + row.id + '"}');
        div.classList.add('stats-sublist-item');
        div.classList.add(sublist_class);

        name.innerHTML = row.name;
        count.innerHTML = row.count;

        div.appendChild(name);
        div.appendChild(count);

        $(sublist).append(div);
    });




    $('.stats-sublist-item').click(async function()
    {
        // Remove class from list objects
        $('.stats-sublist-item').each(function(index, obj) {
            obj.classList.remove('sublist-active');
        });

        var data = $(this).data('spec');
        var id_membrane;
        var id_method;

        if(this.classList.contains("membrane"))
        {
            id_membrane = data.id_subcat;
            id_method = data.id_cat;
        }
        else
        {
            id_membrane = data.id_cat;
            id_method = data.id_subcat;
        }

        $(this).addClass('sublist-active');

        // Show loader
        var loader = document.createElement('div');
        loader.classList.toggle('element-loader');
        $(detail).html(loader);
        await sleep(50);

        var api_endpoint = 'stats/detail';

        var data = ajax_request(api_endpoint, {id_membrane: id_membrane, id_method: id_method});

        if(!data)
        {
            return;
        }

        // Make detail
        $(detail).html("");

        var membrane = document.createElement('div');
        var method = document.createElement('div');
        var interactions = document.createElement('div');
        var substances = document.createElement('div');
        var download_interactions = document.createElement('div');
        var add_to_comparator_btn = document.createElement('div');

        membrane.classList.add('stats-list-detail-btn');
        method.classList.add('stats-list-detail-btn');
        interactions.classList.add('stats-list-detail-btn');
        substances.classList.add('stats-list-detail-btn');
        download_interactions.classList.add('stats-list-detail-btn');
        add_to_comparator_btn.classList.add('stats-list-detail-btn');

        membrane.innerHTML = '<div>Membrane</div><div>' + data.membrane + '</div>';
        method.innerHTML = '<div>Method</div><div>' + data.method + '</div>';
        interactions.innerHTML = '<div>Total interactions</div><div>' + data.total_interactions + '</div>';
        substances.innerHTML = '<div>Total substances</div><div>' + data.total_compounds + '</div>';
        download_interactions.innerHTML = '<div>Download dataset</div><button id="download-dataset" class="stats-button">Download</button>';
        add_to_comparator_btn.innerHTML = '<div>Add to comparator</div><button id="add-to-comparator" class="stats-button">Add</button>';

        
        $(detail).append(membrane);
        $(detail).append(method);
        $(detail).append(substances);
        $(detail).append(interactions);
        $(detail).append(download_interactions);
        $(detail).append(add_to_comparator_btn);

        interaction_ids = data.interaction_ids;
        substance_ids = data.substance_ids;

        // Data export
        $('#download-dataset').click(async function()
        {
            await show_overlay();
            await set_overlay_text('Processing... 0 %');

            var data = [];
            var date = new Date();
            // Set header
            data[0] = ['Name', 'Method', 'Membrane','Q', 'Temperature', 'LogP', 'X_min', 'X_min_+/-', 'G_pen', 'G_pen_+/-', 'G_wat', 'G_wat_+/-', 'LogK', 'LogK_+/-', 'LogPerm', 
                'LogPerm_+/-', 'MW', 'Publication'];

            // Divide into subqueries for php GET REQUEST LENGTH limitations
            var per_request = 100;
            var k = 1;
            var actual_index = 0;
            var interactions = [];

            while(actual_index < interaction_ids.length)
            {
                var ids = interaction_ids.slice(actual_index, per_request + actual_index);
                var ajax_data = ajax_request('comparator/getInteraction', { id: ids.join() });
                
                actual_index = k*per_request;
                var percents = parseInt((actual_index * 100) / interaction_ids.length);

                percents = percents > 100 ? 100 : percents;

                await set_overlay_text('Processing... ' + percents + " %");

                if (!ajax_data) 
                {
                    hide_overlay();
                    return;
                }

                interactions = interactions.concat(ajax_data);

                k++;
            }

            var row_count = interactions.length;
            
            for(var i = 0; i <row_count; i++)
            {
                var int = interactions[i];
                
                data[i+1] = [
                    int.substance.name,
                    int.method,
                    int.membrane,
                    int.charge ? int.charge : "",
                    int.temperature ? int.temperature : "",
                    int.substance.LogP ? int.substance.LogP : "",
                    int.Position ? int.Position : "",
                    int.Position_acc ? int.Position_acc : "",
                    int.Penetration ? int.Penetration : "",
                    int.Penetration_acc ? int.Penetration_acc : "",
                    int.Water ? int.Water : "",
                    int.Water_acc ? int.Water_acc : "",
                    int.LogK ? int.LogK : "",
                    int.LogK_acc ? int.LogK_acc : "",
                    int.LogPerm ? int.LogPerm : "",
                    int.LogPerm_acc ? int.LogPerm_acc : "",
                    int.substance.MW ? int.substance.MW : "",
                    int.reference ? int.reference : ""
                ];
            }

            
            var d = date.getDate() + "." + (date.getMonth()+1) + "." + date.getFullYear();
            exportToCsv('Dataset' + '_' + d + '.csv', data);

            hide_overlay();
        });


        // Add to comparator
        $('#add-to-comparator').click(function()
        {
            $(substance_ids).each(function(k, s)
            {
                add_to_comparator(s.id, s.name);
            })

            add_message("Successfully added (" + substance_ids.length + " compounds) to comaprator.");
        });
    });

    $(this).addClass('list-active');

    $('.stats-sublist-item:first').click();

});


$('.stats-list-item:first').click();
