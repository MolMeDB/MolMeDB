const PREFIX_MEMBRANE   = "membrane";
const PREFIX_METHOD     = "method";
const PREFIX_MOL        = "mol";

const DOWNLOADER        = "DW";

const DELIM             = "!~!";
const COMMA             = "!@!";

/**
 * Adds new item to storage
 */
function add_to_storage(attr, value, is_array = false)
{
    let v = get_from_storage(attr);

    if(v)
    {
        v = v.toString().split(',');
    }

    if(is_array)
    {
        if(!Array.isArray(value))
        {
            value = [value];
        }

        var t = value;
        value = [];

        t.forEach((el) => {
            value.push(el.toString().replaceAll(',', COMMA));
        });

        if(!Array.isArray(v))
        {
            localStorage.setItem(attr, value);
        }
        else
        {
            v = [...new Set([...v,...value])];
            localStorage.setItem(attr, v);
        }
    }
    else
    {
        localStorage.setItem(attr, value);
    }
}

/**
 * Get value from storage
 */
function get_from_storage(attr, is_array = false)
{
    let data = localStorage.getItem(attr);

    if(!data || !is_array)
    {
        return data;
    }

    data = data.split(',');
    var t = data;
    data = [];

    t.forEach((el) => {
        data.push(el.replaceAll(COMMA, ','));
    });
    
    return data;
}

/**
 * Remove from storage
 */
function remove_from_storage(attr)
{
    localStorage.removeItem(attr);
}

/**
 * Removes all from local storage
 */
function flush_storage()
{
    localStorage.clear();
}

/**
 * Adds new item to downloader
 * 
 */
function add_to_downloader(val, type)
{
    let attr = DOWNLOADER + "_" + type;
    add_to_storage(attr, val, true);
}

/**
 * Checks, if membrane is in downloader list
 */
function is_in_download_list(id, name, type)
{
    let val = id + DELIM + name;
    let all = get_from_storage(DOWNLOADER + "_" + type, true);

    return all && all.indexOf(val) !== -1;
}

/**
 * 
 */
function disable_button(cl)
{
    cl = '.' + cl;
    $(cl).removeClass('btn-warning');
    $(cl).addClass('btn-success');
    $(cl).html('<span class="glyphicon glyphicon-ok"></span>');
    $(cl).attr('title', 'Added to downloader');
}

/**
 * 
 */
function enable_button(cl, html = "")
{
    cl = '.' + cl;
    $(cl).removeClass('btn-success');
    $(cl).addClass('btn-warning');
    $(cl).html(html ? html : 'Add to downloader');
}

/**
 * Update downloader list
 */
function update_downloader_list()
{
    var total = 0;

    let mem_target = $('#download-membrane-list');
    let met_target = $('#download-method-list');
    let sub_target = $('#download-molecule-list');

    // Get all items in list
    let mem_items = [];
    let met_items = [];
    let sub_items = [];

    $(mem_target).children().each((i,e) => {
        let id = $(e).attr('id');
        if(id)
        {
            mem_items.push(id);
        }
    });

    $(met_target).children().each((i,e) => {
        let id = $(e).attr('id');
        if(id)
        {
            met_items.push(id);
        }
    });

    $(sub_target).children().each((i,e) => {
        let id = $(e).attr('id');
        if(id)
        {
            sub_items.push(id);
        }
    });

    // Get from storage
    let mem_s = get_from_downloader(PREFIX_MEMBRANE);
    let met_s = get_from_downloader(PREFIX_METHOD);
    let mol_s = get_from_downloader(PREFIX_MOL);

    // Check all membranes
    mem_s.forEach((e) => {
        let id = 'dwn-membrane-' + e.id;
        disable_button(id);
        
        if(mem_items.indexOf(id) !== -1)
        {
            mem_items = mem_items.filter(row => row !== id);
            return;
        }

        let n = $('<div></div>');
        $(n).attr('id', id);
        $(n).html(e.name);
        $(n).attr('onclick', 'remove_from_downloader("' + e.id + '", "' + PREFIX_MEMBRANE + '");');
        $(mem_target).append(n);
    });

    // Check all methods
    met_s.forEach((e) => {
        let id = 'dwn-method-' + e.id;
        disable_button(id);
        
        if(met_items.indexOf(id) !== -1)
        {
            met_items = met_items.filter(row => row !== id);
            return;
        }

        let n = $('<div></div>');
        $(n).attr('id', id);
        $(n).html(e.name);
        $(n).attr('onclick', 'remove_from_downloader("' + e.id + '", "' + PREFIX_METHOD + '");');
        $(met_target).append(n);
    });

    // Check all molecules
    // $(sub_target).find('.fli-disabled').remove();
    mol_s.forEach((e) => {
        let id = 'dwn-mol-' + e.id;
        disable_button(id);

        if(sub_items.indexOf(id) !== -1)
        {
            sub_items = sub_items.filter(row => row !== id);
            return;
        }

        let n = $('<div></div>');
        $(n).attr('id', id);
        $(n).html(e.name);
        $(n).attr('onclick', 'remove_from_downloader("' + e.id + '", "' + PREFIX_MOL + '");');
        $(sub_target).append(n);
    });

    // Remove old
    mem_items.forEach((el) => {
        $('#' + el).remove();
        enable_button(el);
    });
    met_items.forEach((el) => {
        $('#' + el).remove();
        enable_button(el);
    });
    sub_items.forEach((el) => {
        $('#' + el).remove();
        enable_button(el, '<span class="glyphicon glyphicon-plus"></span>');
    });

    total = mem_s.length + met_s.length + mol_s.length;

    $('#downloader-fb-total').html(total);

    $('#dwn-sel-mols').html('(' + mol_s.length + ')');
    $('#only-selected-mols-count').html(mol_s.length);
    $('#dwn-sel-mems').html('(' + mem_s.length + ')');
    $('#dwn-sel-mets').html('(' + met_s.length + ')');

    if(!$('.feedback').hasClass('fb-visible'))
    {
        $('.feedback').addClass('animate');
        setTimeout(function() {
            $('.feedback').removeClass('animate');
        }, 500);
    }

    let all_green = true;

    $('.btn-add').each((i,e) => {
        if(!all_green || $(e).hasClass('btn-warning'))
        {
            all_green = false;
        }
    });

    if(all_green)
    {
        $('#btn-addALL').html('Remove list from downloader');
        $('#btn-addALL').removeClass('btn-warning');
        $('#btn-addALL').addClass('btn-danger');
    }
    else
    {
        $('#btn-addALL').html('Add list to the downloader');
        $('#btn-addALL').removeClass('btn-danger');
        $('#btn-addALL').addClass('btn-warning');
    }
}

/**
 * Adds membrane to downloader
 */
function add_membrane_to_downloader(id, name)
{
    let val = id + DELIM + name;
    if(is_in_download_list(id, name, PREFIX_MEMBRANE))
    {
        remove_from_downloader(id, PREFIX_MEMBRANE);
    }
    else
    {
        add_to_downloader(val, PREFIX_MEMBRANE); 
        update_downloader_list();
    }
}

/**
 * Adds method to downloader
 */
function add_method_to_downloader(id, name)
{
    let val = id + DELIM + name;
    if(is_in_download_list(id, name, PREFIX_METHOD))
    {
        remove_from_downloader(id, PREFIX_METHOD);
    }
    else
    {
        add_to_downloader(val, PREFIX_METHOD); 
        update_downloader_list();
    }
}

/**
 * Adds membrane to downloader
 */
function add_molecule_to_downloader(id, name)
{
    let val = id + DELIM + name;
    if(is_in_download_list(id, name, PREFIX_MOL))
    {
        remove_from_downloader(id, PREFIX_MOL);
    }
    else
    {
        add_to_downloader(val, PREFIX_MOL); 
        update_downloader_list();
    }
}

/**
 * Returns membranes in download list
 */
function get_from_downloader(prefix)
{
    let data = get_from_storage(DOWNLOADER + "_" + prefix, true);
    let result = [];

    if(!Array.isArray(data))
    {
        return result;
    }

    data.forEach(element => {
        if(!element)
        {
            return;
        }    

        let s = element.toString().split(DELIM);

        if(!s)
        {
            return;
        }

        result.push({
            id: s[0],
            name: s[1]
        })
    });

    return result;
}

/**
 * Removes from list
 */
function remove_from_downloader(ids = [], prefix = PREFIX_MEMBRANE)
{
    if(!Array.isArray(ids))
    {
        ids = [ids];
    }

    let t = ids;
    ids = [];

    t.forEach((e)=> {
        ids.push(e.toString());
    });

    let current = get_from_storage(DOWNLOADER + "_" + prefix, true);
    let new_vals = [];

    if(!Array.isArray(current))
    {
        return true;
    }

    current.forEach(function (element) {
        let v = element.split(DELIM);
        let id = v[0];

        if(ids.indexOf(id) === -1)
        {
            new_vals.push(element);
        }
    });

    remove_from_storage(DOWNLOADER + "_" + prefix);
    add_to_storage(DOWNLOADER + "_" + prefix, new_vals, true);
    update_downloader_list();
}



function setPosition()
{
    var win = $(window);
    win.scroll(function ()
    {
        setPos();
    });

    window.addEventListener('resize', setPos);
}

function setPos()
{
    var footer_from_top = $('#footer').offset().top;
    var bottom = $(window).scrollTop() + $(window).height() + 50;
    var target = $('#feedback');

    var right = $('#main-page').offset().left + 20;

    if(bottom >= footer_from_top)
    {
        $(target).css('position', 'absolute');
        $(target).css('bottom', '0');
        $(target).css('right', '20px');
    }
    else
    {
        $(target).css('position', 'fixed');
        $(target).css('bottom', '-10px');
        $(target).css('right', right+'px');
    }
}


function ini_func()
{
    $('.feedback-list-category').find('.glyphicon-resize-full').on('click', function() 
    {
        var el = $(this).parent().parent().parent();
        var other = $(el).siblings('.feedback-list-category');

        var is_expanded = parseInt($(el).css('height')) > 110 ? true : false;

        if(is_expanded)
        {
            // Hide other and expand current
            $('.feedback-list-category').css('height', "110px");
            $('.feedback-list-items').css('overflow-y', "hidden");
            $('.feedback-basic-list-items').css('overflow-y', "hidden");
        }
        else
        {
            // Hide other and expand current
            $(el).css('height', "80%");
            $(el).find('.feedback-list-items').css('overflow-y', "auto");
            $(el).find('.feedback-basic-list-items').css('overflow-y', "auto");
            $(other).css('height', '10%');
            $(other).find('.feedback-list-items').css('overflow-y', 'hidden');
            $(other).find('.feedback-basic-list-items').css('overflow-y', 'hidden');
        }
    });

    $('#btn-addALL').on('click', function()
    {
        let enable = $(this).hasClass('btn-warning');

        $('.btn-add').each((i,e) => {

            if(enable && $(e).hasClass('btn-warning'))
            {
                $(e).click();
            }
            else if(!enable && !$(e).hasClass('btn-warning'))
            {
                $(e).click();
            }
        });
    });

    $('#dwn-fast-download').on('click', function() {
        let mem_s = get_from_downloader(PREFIX_MEMBRANE);
        let met_s = get_from_downloader(PREFIX_METHOD);
        let mol_s = get_from_downloader(PREFIX_MOL);

        let form = $('<form method="POST" action="/export/downloader/1"></form>');

        mem_s.forEach((el) => {$('<input name="id_membranes[]" hidden value="' + el.id + '"/>').appendTo(form)});
        met_s.forEach((el) => {$('<input name="id_methods[]" hidden value="' + el.id + '"/>').appendTo(form)});
        mol_s.forEach((el) => {$('<input name="id_molecules[]" hidden value="' + el.id + '"/>').appendTo(form)});

        $(form).append($('<input name="logic" value="OR" hidden>'));
        $(form).appendTo('body').submit();
    });
}

window.addEventListener("load",  update_downloader_list, false);
window.addEventListener("load",  setPosition, false);
window.addEventListener("load",  setPos, false);
window.addEventListener("load",  ini_func, false);

