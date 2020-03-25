var plugin = LiteMol.Plugin.create({ 
    target: '#litemol', 
    viewportBackground: '#fff',
    layoutState: {
        hideControls: true,
    } 
});
var id = $("#nameStructure").val();

plugin.loadMolecule({
    id: 'Mole',
    url: '/media/files/3DStructures/' + id + '.mol',
    format: 'sdf' // default
});
