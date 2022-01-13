var plugin = LiteMol.Plugin.create({ 
    target: '#litemol', 
    viewportBackground: '#fff',
    layoutState: {
        hideControls: true,
    } 
});
var path = $("#structurePath").val();

plugin.loadMolecule({
    id: 'Mole',
    url: '/' + path,
    format: 'sdf' // default
});
