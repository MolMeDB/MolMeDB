var source_name = null;
var source_link_id = null;
var source_type = null;
var is_folder = false;
var source = null;

// Drag handlers
function handleDragStart(e){
    this.style.opacity = '0.4';

    is_folder = $(this).hasClass('folder');
    is_inner_item = $(this).parent('ul').hasClass('f_ul');

    source = this;

    if(is_folder || is_inner_item)
    {
        var n_el = $(this).find('> div.text');
        source_name = $(n_el).html();
        source_link_id = $(this).data('id');
        source_type = $(this).data('type');
    }
    else
    { // ITEM
        source_name = $(this).html();
        source_link_id = $(this).data('id');
        source_type = $(this).data('type');
    }
}

function handleDragEnd(e)
{
    this.style.opacity = '1';
    $('#tree').find('li').removeClass('drag-target');
}

function handleDragOver(e) {
    if (e.preventDefault) {
        e.preventDefault();
    }

    return false;
}


function handleDragLeave(e) {
    $('#tree').find('li').removeClass('drag-target');
    $(this).addClass('drag-target');
}

function handleDrop(e) {
    e.stopPropagation(); // stops the browser from redirecting.

    var target = this;
    var t_is_folder = $(this).hasClass('folder');
    var t_is_unlabeled = $(this).hasClass('unlabeled') || $(this).parent('dcontent').hasClass('unlabeled');

    if(!t_is_folder && $(this).parent('ul').hasClass('f_ul'))
    {
        var p = $(this).parent('ul');
        var prev = $(p).prev('li.folder');
        if(prev)
        {
            target = prev;
            t_is_folder = true;
        }
    }

    if(!t_is_folder && !t_is_unlabeled || !source_type || !source_link_id || !source_name)
    {
        return;
    }

    // Unlink item
    if(t_is_unlabeled && !is_folder)
    {
        if(confirm('Unlink [' + source_name + '] item?'))
        {
            var params = {
                id: source_link_id,
                type: source_type,
            };

            var result = ajax_request('settings/unlink_item', params, 'POST');

            if(result == false)
            {
                alert('Cannot unlink item, please try again.');
                return;
            }

            // Remove old element
            $(source).remove();

            // Add to unlabeled
            var targ = $(this).hasClass('unlabeled') ? this : $(this).parent('dcontent');
            // Make unlinked element
            var el = document.createElement('dli');
            $(el).attr('data-id', $(source).data('id'));
            $(el).attr('data-type', $(source).data('type'));
            $(el).html($(source).html());
            $(el).attr('draggable', 'true');

            $(targ).append(el);
        }
    }
    else if(t_is_folder && is_folder) // Move folders
    {
        var target_link_id = $(target).data('id');
        var text_el = $(target).find('> div.text');
        var target_name = $(text_el).html();
        var target_type = $(target).data('type')

        if(target_link_id && target_name && target_type == source_type && confirm("Move folder [" + source_name + "] to the [" + target_name + "] folder?"))
        {
            var params = {
                source_id: source_link_id,
                item_type: source_type,
                target_link_id: target_link_id,
                item_id: null
            };

            var t = $(target).next('ul.f_ul');
            var children = $(source).next('ul.f_ul')[0];

            if(!t || !children)
            {
                alert('Cannot move folder, please try again.');
                return;
            }

            var r = ajax_request('settings/move_enum_type', params, "POST");

            if(r == false)
            {
                alert('Cannot move folder, please try again.');
                return;
            }

            var el = $(source).clone(true);
            $(el).css('opacity', '1');

            $(t[0]).append(el);
            $(t[0]).append($(children).clone(true));
            $(source).remove();
            $(children).remove();
        }
    }
    else if(t_is_folder && !is_folder) // Move item to another folder
    {
        var target_link_id = $(target).data('id');
        var text_el = $(target).find('> div.text');
        var target_name = $(text_el).html();
        var target_type = $(target).data('type')

        if(target_link_id && target_name && target_type == source_type && confirm("Move item [" + source_name + "] to the [" + target_name + "] folder?"))
        {
            var params = {
                item_id: source_link_id,
                item_type: source_type,
                target_link_id: target_link_id,
                source_id: null
            };

            var t = $(target).next('ul.f_ul');

            if(!t)
            {
                alert('Cannot link item, please try again.');
                return;
            }

            var r = ajax_request('settings/move_enum_type', params, "POST");

            if(r == false)
            {
                alert('Cannot link item, please try again.');
                return;
            }

            // var el = $(source).clone(true);
            var el = document.createElement("li");
            $(el).data('id', $(source).data('id'));
            $(el).data('type', $(source).data('type'));
            $(el).attr('draggable', 'true');
            $(el).html($(source).html());

            $(el).css('opacity', '1');

            $(t[0]).append(el);
            $(source).remove();
        }
    }

    source_type = null;
    

    return false;
}


$(document).ready(function()
{
    var tree = $('#tree');
    
    // Set handlers
    $(tree).find('li').bind('dragstart', handleDragStart);
    $(tree).find('li').bind('dragover', handleDragOver);
    $(tree).find('li').bind('dragleave', handleDragLeave);
    $(tree).find('li').bind('dragend', handleDragEnd);
    $(tree).find('li').bind('drop', handleDrop);

    $('#item-list').find('dli').bind('dragstart', handleDragStart);
    $('#item-list').find('dcontent.unlabeled').bind('dragover', handleDragOver);
    $('#item-list').find('dli').bind('dragleave', handleDragLeave);
    $('#item-list').find('dli').bind('dragend', handleDragEnd);
    $('#item-list').find('dli').bind('drop', handleDrop);

    
    $(tree).find('li > div.text').on('click', function()
    {
        var el = $(this).parent();
        var parent = $(el).parent();
        var onTop = $(parent).hasClass('tree-view');
        var nextSibbling = $(el).next();
        var nextSibblingName = $(nextSibbling).prop('nodeName');
        var openable = nextSibblingName ? nextSibblingName.toString().toLowerCase() == 'ul' : false;

        // $(tree).find('li').removeClass('active');
        
        if(openable)
        {
            var is_opened = $(nextSibbling).is(':visible');

            if(is_opened) // Close
            {
                $(nextSibbling).hide();
                $(el).removeClass('folder-open');
                $(el).addClass('folder');
            }
            else // OPEN
            {
                $(nextSibbling).show();
                $(el).removeClass('folder');
                $(el).addClass('folder-open');
            }
        }

        // $(el).addClass('active');
    });

    // Adds new item to the folder
    $('.add-folder').on('click', function(){
        var el = $(this).parent().parent();
        var id = $(el).data('id');

        var new_item_name = prompt('Add category: ', '');

        if(!new_item_name || !id)
        {
            return;
        }

        var params = {
            parent_link_id: id,
            new_item: new_item_name
        };

        redirect('setting/add_enum_type', params, "POST");
    });

    // Adds new item to the folder
    $('.edit').on('click', function(){
        var el = $(this).parent().parent();
        var id = $(el).data('id');
        var text = $(el).find('> div.text')[0];

        var new_item_name = prompt('New name: ', $(text).html());

        if(!new_item_name || !id)
        {
            return;
        }

        var params = {
            link_id: id,
            new_name: new_item_name
        };

        redirect('setting/edit_enum_type', params, "POST");
    });

    // Edits regexp
    $('.edit-regexp').on('click', function(){
        var el = $(this).parent().parent();
        var id = $(el).data('id');
        var text = $(el).data('regexp');
        var regexp;

        if(!(regexp = prompt('New regexp: ', text)))
        {
            return;
        }

        if(!id)
        {
            return;
        }

        var params = {
            link_id: id,
            regexp: regexp
        };

        redirect('setting/edit_regexp', params, "POST");
    });

    // Removes element
    $('.delete').on('click', function(){
        var el = $(this).parent().parent();
        var id = $(el).data('id');

        if(!confirm('Delete section?') || !id )
        {
            return;
        }

        var params = {
            link_id: id,
        };  

        redirect('setting/delete_enum_type', params, "POST");
    });
});