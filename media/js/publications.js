// Default limit items on page
var limit = 10;
// Default page
var page = 1;
// Table 
var table = $('#publications');
// rows
var all_rows = $(table).find('tbody > tr');
var rows = $(table).find('tbody > tr.active');
// Total rows
var total_rows = 0;
// Table total items
var total_items_elements = $('.table-items-total > element');
var total_items_element_active = $('.table-items-total > element.active');
var table_paginator = $('#paginator');
var input = $('#publSearch');

$(document).ready(function()
{
    getLimit();
    showContent();
    add_paginator();

    $(total_items_elements).each(function(i,e)
    {
        $(e).on('click', function(){
            $(total_items_elements).removeClass('active');
            $(this).addClass('active');
            limit = parseInt($(this).html());

            add_paginator();
            filter_table();
        });
    });

    function update_rows()
    {
        rows = $(table).find('tbody > tr.active');
    }

    // Search
    $(input).on('keyup', function(v)
    {
        var v = $(input).val();
        v = v.toLowerCase();

        if(v.length < 3)
        {
            $(all_rows).removeClass('active');
            $(all_rows).addClass('active');
            update_rows();
            add_paginator();
            filter_table();
            return;
        }

        $(all_rows).removeClass('active');

        $(all_rows).each(function(i,e){
            var whisper = $(e).find('whisper')[0];
            var text = $(whisper).html();
            text = text.toLowerCase();

            if(text.includes(v))
            {
                $(e).addClass('active');
            }
        });

        update_rows();
        add_paginator();
        filter_table();
    });
});

// Get limit
function getLimit()
{
    if(!total_items_element_active)
    {
        console.log('Invalid type.');
        return;
    }

    var selected = $(total_items_element_active).html();
    selected = parseInt(selected);

    if(selected)
    {
        limit = selected;
    }
}

// Add paginator if not exists
function add_paginator()
{
    if(!table)
    {
        console.log('Publication table not found.');
        return;
    }

    var total_rows = 0;
    $(rows).each(function(i,e)
    {
        total_rows++;
    });

    // First, clear paginator
    $(table_paginator).html('');

    var total_pages = total_rows / limit;
    total_pages = parseInt(total_pages) == total_pages ? total_pages : parseInt(total_pages) + 1;

    if(page > total_pages)
    {
        page = total_pages;
    }

    if(!page)
    {
        page = 1;
    }

    if(page < 5)
    {
        var max = total_pages < 8 ? total_pages : 5;
        for(var i = 0; i < max; i++)
        {
            $(table_paginator).append(make_page_item(i+1, page == (i+1)));
        }

        if(max != total_pages)
        {
            $(table_paginator).append(make_page_item('...'));

            var f = total_pages - 2;
            for(var i = f; i <= total_pages; i++)
            {
                $(table_paginator).append(make_page_item(i, page == (i)));
            }
        }
    }
    else if(page > total_pages - 4)
    {
        for(var i = 1; i < 5; i++)
        {
            (table_paginator).append(make_page_item(i, page == (i)));
        }

        $(table_paginator).append(make_page_item('...'));

        var f = total_pages - 4;
        for(var i = f; i <= total_pages; i++)
        {
            (table_paginator).append(make_page_item(i, page == (i)));
        }
    }
    else
    {
        for(var i = 1; i < 3; i++)
        {
            (table_paginator).append(make_page_item(i, page == (i)));
        }

        $(table_paginator).append(make_page_item('...'));
        $(table_paginator).append(make_page_item(page-1));
        $(table_paginator).append(make_page_item(page, true));
        $(table_paginator).append(make_page_item(page+1));
        $(table_paginator).append(make_page_item('...'));

        var f = total_pages - 1;
        for(var i = f; i <= total_pages; i++)
        {
            (table_paginator).append(make_page_item(i, page == (i)));
        }
    }
}

function change_pagination()
{
    var p = $(this).html();

    if(!p)
    {
        return;
    }

    page = parseInt(p);
    filter_table();
    add_paginator();
}

function make_page_item(html = null, active=false)
{
    var el = document.createElement('element');

    if(active)
    {
        el.classList.add('active');
    }

    el.innerHTML = html;
    $(el).on('click', change_pagination);

    return el;
}

// Filter table
function filter_table()
{
    $(table).find('tbody > tr').hide();

    var from = (page-1)*limit;
    var to = from + limit;
    to = to > total_rows ? total_rows : to;

    for(var i = from; i < to; i++)
    {
        $(rows[i]).show();
    }
}

// Show table content
function showContent()
{
    if(!table)
    {
        console.log('Publication table not found.');
        return;
    }

    $(rows).each(function(i, e)
    {
        total_rows++;
        if(i < limit)
        {
            $(e).show();
        }
    });
}